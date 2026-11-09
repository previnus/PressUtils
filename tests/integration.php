<?php
/**
 * Run with wp eval-file against a disposable WordPress site with WPUS active.
 * This exercise writes settings and creates WordPress posts and plugin records.
 */

use Wpus\Audit;
use Wpus\Admin;
use Wpus\Crm;
use Wpus\Db;
use Wpus\Health;
use Wpus\Lifecycle;
use Wpus\Monitoring;
use Wpus\NetworkPolicy;
use Wpus\Notes;
use Wpus\Operations;
use Wpus\Outbound;
use Wpus\Performance;
use Wpus\Policy;
use Wpus\Privacy;
use Wpus\Queue;
use Wpus\Secrets;
use Wpus\Settings;
use Wpus\TestTransport;

if (!defined('WP_CLI') || !WP_CLI || wp_get_environment_type() !== 'local') {
    throw new RuntimeException('Use a disposable local WordPress installation.');
}

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        WP_CLI::error($message);
    }
    WP_CLI::log('PASS ' . $message);
};

$admin = get_users(['role' => 'administrator', 'number' => 1])[0] ?? null;
$assert($admin instanceof WP_User, 'Administrator fixture exists');
wp_set_current_user($admin->ID);
$assert(Lifecycle::ready() && Queue::available(), 'Schema and Action Scheduler are ready');
$assert((bool) as_has_scheduled_action(Queue::HOOK, [], Queue::GROUP), 'Utility Suite recurring job is registered');
$loopback = apply_filters('as_async_request_queue_runner_post_args', ['sslverify' => false, 'redirection' => 5]);
$assert($loopback['sslverify'] === true && $loopback['redirection'] === 0, 'Async scheduler loopbacks verify TLS and refuse redirects');
$assert(Admin::auditFilter(['from' => "2026-11-\0"]) === null, 'Malformed audit date is rejected without a PHP error');
update_option('wpus_outbound_paused', true, false);
update_option('wpus_outbound_paused', '0', false);
$assert(Policy::sitePaused() === false, 'Worker cached the unpaused site option');
global $wpdb;
Db::query('UPDATE %i SET option_value = %s WHERE option_name = %s', $wpdb->options, '1', 'wpus_outbound_paused');
$assert(Policy::sitePaused() === false && Policy::sitePaused(true) === true && !Policy::outbound(true),
    'Fresh egress check detects a pause persisted outside the worker request');
Db::query('UPDATE %i SET option_value = %s WHERE option_name = %s', $wpdb->options, '', 'wpus_outbound_paused');

$patch = static function (string $module, array $values): string {
    return Settings::save($module, $values, Settings::get($module, true)['revision']);
};

$suffix = strtolower(wp_generate_password(8, false));
$assert($patch('admin_tools', ['expiry' => false]) === 'saved', 'Expiration fixture starts disabled');
$before = Settings::get('admin_tools', true)['revision'];
$assert($patch('admin_tools', ['expiry' => true]) === 'saved', 'Administrator can opt in to expiration');
$assert(Settings::save('admin_tools', ['expiry' => false], $before) === 'conflict', 'Stale settings revision is rejected');
$assert($patch('security', ['retention' => 0]) === 'invalid', 'Out-of-range retention is rejected');
$assert($patch('admin_tools', ['expiry_types' => ['shop_order']]) === 'invalid', 'Order expiration is rejected');

$post = wp_insert_post(['post_title' => 'WPUS fixture ' . $suffix, 'post_content' => 'Initial content', 'post_status' => 'publish'], true);
$assert(is_int($post) && $post > 0, 'Published post fixture created');
$assert(Operations::scheduleExpiry($post, "2026-11-09T12:\0") === 'invalid', 'Malformed expiry date is rejected without a PHP error');
$title = 'Technical fixture ' . $suffix;
$assert(Notes::save($title, 'Two lines' . "\n" . '<script>markup</script>', $post, 0, 0) === 'saved', 'Authorized linked note is stored');
$note = Db::row('SELECT * FROM %i WHERE title = %s', Db::table('admin_notes'), $title);
$assert($note && Notes::save('Stale', 'Change', $post, (int) $note['id'], 99) === 'conflict', 'Stale note revision is rejected');
$assert((bool) Privacy::export($admin->user_email)['data'], 'WordPress privacy export includes attributable data');

$subscriber = wp_insert_user([
    'user_login' => 'wpus_test_' . $suffix, 'user_pass' => wp_generate_password(), 'role' => 'subscriber',
    'user_email' => 'wpus_' . $suffix . '@example.test',
]);
$assert(is_int($subscriber), 'Unprivileged account fixture created');
wp_set_current_user($subscriber);
$assert(Notes::list()['items'] === [] && Notes::remove((int) $note['id'], 1) === 'denied', 'Unprivileged user cannot read or delete notes');
$assert($patch('security', ['logins' => false]) === 'denied', 'Unprivileged user cannot change settings');
wp_set_current_user($admin->ID);

$expiry = static function (int $post) use ($assert): void {
    $when = wp_date('Y-m-d\TH:i', time() + 1800, wp_timezone());
    $assert(Operations::scheduleExpiry($post, $when) === 'queued', 'Expiration is queued');
    $assert(Db::query("UPDATE %i SET run_at = %s WHERE operation_type = 'expiry' AND object_id = %d AND state = 'pending'",
        Db::table('operations'), Db::utc(time() - 60), $post) === 1, 'Expiration fixture made due');
};
$expiry($post);
wp_update_post(['ID' => $post, 'post_content' => 'Changed content']);
Queue::sweep();
$assert(get_post_status($post) === 'publish', 'Worker skips an edited post');
$expiry($post);
Queue::sweep();
$assert(get_post_status($post) === 'draft', 'Worker expires an unchanged post');

$base = rtrim((string) wp_parse_url(home_url('/'), PHP_URL_PATH), '/');
$from = $base . '/old-' . $suffix;
$target = $base . '/new-' . $suffix;
$assert(Monitoring::saveRule($from, $target, 302, 0, 0) === 'saved', 'Local redirect rule is saved');
$assert(Monitoring::saveRule($target, $from, 301, 0, 0) === 'invalid', 'Redirect cycle is rejected');
$assert(Monitoring::saveRule($from . '-other', '//example.com/out', 301, 0, 0) === 'invalid', 'Protocol-relative redirect is rejected');
$assert(Monitoring::path($base . '/private%2fother') === null && Monitoring::path($base . '/wp-login.php') === null, 'Encoded separators and sensitive routes are rejected');
$assert(Outbound::origin('https://www.example.com/webhook/path') === 'https://www.example.com', 'HTTPS webhook origin is canonicalized');
$assert(Outbound::origin('https://example.com:444/unsafe') === null && Outbound::origin('https://user@example.com/path') === null
    && Outbound::origin('https://example.com/test?query=1') === null, 'Unsafe webhook URL syntax is rejected');
foreach (['127.0.0.1', '10.10.0.1', '169.254.169.254', '100.64.0.1', '192.0.2.1', '224.0.0.1', '::1', '::ffff:127.0.0.1', '2001:db8::1', '3fff::1'] as $address) {
    $assert(!Outbound::publicIp($address), 'Rejects non-public address ' . $address);
}
$assert(Outbound::publicIp('8.8.8.8') && Outbound::publicIp('2606:4700:4700::1111'), 'Allows ordinary public IPv4 and IPv6');

$old = Db::insert('monitoring_events', ['check_key' => 'test', 'details_json' => '{}', 'observed_at' => Db::utc(time() - 31 * DAY_IN_SECONDS)]);
$assert(Performance::previewCleanup() === 'saved', 'Cleanup preview is persisted');
$preview = Performance::cleanupPreview();
$assert($preview && Performance::applyCleanup((int) $preview['id'], (int) $preview['revision']) === 'saved'
    && !Db::value('SELECT id FROM %i WHERE id = %d', Db::table('monitoring_events'), $old), 'Cleanup removes frozen expired candidates');
$assert(Performance::applyCleanup((int) $preview['id'], (int) $preview['revision']) === 'conflict', 'Cleanup preview cannot be reapplied');

if (Crm::available() && Outbound::approved('https://example.com')) {
    require_once __DIR__ . '/transport-fixture.php';
    update_option('wpus_outbound_paused', false, false);
    $secret = bin2hex(random_bytes(32));
    $label = 'CRM fixture ' . $suffix;
    $assert(Crm::configure(0, 0, $label, 'https://example.com/wp-utility-test', $secret, ['content.published'], '{"content_id":"post_id"}') === 'saved',
        'CRM destination stores encrypted credentials');
    $destination = Db::row('SELECT * FROM %i WHERE label = %s', Db::table('webhook_destinations'), $label);
    $id = (int) $destination['id'];
    $assert(Crm::changeState($id, 1, 'active') === 'unavailable', 'Unverified destination cannot be enabled');
    $assert(Secrets::resolve($id, 'signing') === $secret && Secrets::resolve($id, 'endpoint') === 'https://example.com/wp-utility-test',
        'Stored secrets decrypt only in their bound context');
    $sealed = Secrets::seal('test', 'a', 'sample');
    $assert(Secrets::open('test', 'b', $sealed['key_id'], $sealed['encrypted']) === null, 'Authenticated encryption rejects another record binding');
    $assert(Crm::test($id, 1) === 'queued', 'Synthetic test is recorded without an inline request');
    $body = Db::row('SELECT body_ciphertext, body_key_id, delivery_uuid FROM %i WHERE destination_id = %d', Db::table('webhook_deliveries'), $id);
    $payload = json_decode(Secrets::open('delivery', $body['delivery_uuid'], $body['body_key_id'], $body['body_ciphertext']), true);
    $assert($payload['event_type'] === 'wpus.test' && $payload['data']['synthetic'] === true, 'Synthetic body follows the event envelope');
    TestTransport::$onLookup = static function () use ($id): void {
        Db::query('UPDATE %i SET lease_until = %s WHERE destination_id = %d', Db::table('webhook_deliveries'), Db::utc(time() - 1), $id);
    };
    Queue::sweep();
    TestTransport::$onLookup = null;
    $assert(TestTransport::$connections === 0, 'Transport does not connect if the lease expires during DNS');
    $assert(Crm::changeState($id, 1, 'revoked') === 'saved' && Secrets::resolve($id, 'signing') === null,
        'Revocation removes secret and pending body before any transport');
    $assert(!Db::value('SELECT body_ciphertext FROM %i WHERE destination_id = %d', Db::table('webhook_deliveries'), $id),
        'Revoked delivery body has been redacted');

    $assert(Crm::configure(0, 0, $label . ' signed', 'https://example.com/wp-utility-test', $secret,
        ['content.published'], '{"content_id":"post_id"}') === 'saved', 'Signed delivery fixture is configured');
    $destinations = Db::table('webhook_destinations');
    $deliveries = Db::table('webhook_deliveries');
    $id = (int) Db::value('SELECT id FROM %i WHERE label = %s', $destinations, $label . ' signed');
    $assert(Crm::test($id, 1) === 'queued', 'Signed synthetic request is queued');
    Queue::sweep();
    $test = Db::row('SELECT state, delivery_uuid FROM %i WHERE destination_id = %d AND is_test = 1', $deliveries, $id);
    $assert($test['state'] === 'succeeded' && TestTransport::$connections === 1, 'Successful synthetic request verifies the destination generation');
    $options = TestTransport::$options;
    $headers = [];
    foreach ($options[CURLOPT_HTTPHEADER] as $header) {
        [$key, $value] = explode(': ', $header, 2);
        $headers[$key] = $value;
    }
    $raw = $options[CURLOPT_POSTFIELDS];
    $expectedSignature = 'v1=' . hash_hmac('sha256', $headers['X-WPUS-Timestamp'] . "\n" . $test['delivery_uuid'] . "\n" . $raw, $secret);
    $assert($headers['X-WPUS-Delivery-ID'] === $test['delivery_uuid'] && $headers['X-WPUS-Signature'] === $expectedSignature,
        'Worker signs exact JSON bytes and the stable delivery ID');
    $assert($options[CURLOPT_RESOLVE] === ['example.com:443:8.8.8.8'] && !$options[CURLOPT_FOLLOWLOCATION]
        && $options[CURLOPT_PROXY] === '' && $options[CURLOPT_SSL_VERIFYPEER] && $options[CURLOPT_SSL_VERIFYHOST] === 2,
        'Worker pins a validated address and disables redirects and proxies while verifying TLS');
    $assert(Crm::changeState($id, 2, 'active') === 'saved' && $patch('crm', ['capture' => true]) === 'saved', 'Verified destination is explicitly enabled');
    Crm::observe('content.published', $post, 'publish', ['content_id' => $post, 'content_type' => 'post']);
    Crm::observe('content.published', $post, 'publish', ['content_id' => $post, 'content_type' => 'post']);
    $delivery = Db::row('SELECT id, delivery_uuid FROM %i WHERE destination_id = %d AND is_test = 0', $deliveries, $id);
    $assert($delivery && (int) Db::value('SELECT COUNT(*) FROM %i WHERE destination_id = %d AND is_test = 0', $deliveries, $id) === 1,
        'Repeated observations of the same transition produce one delivery');
    TestTransport::$status = 429;
    TestTransport::$retryAfter = '120';
    Queue::sweep();
    $retry = Db::row('SELECT state, next_attempt_at FROM %i WHERE id = %d', $deliveries, $delivery['id']);
    $assert($retry['state'] === 'retry_wait' && strtotime($retry['next_attempt_at'] . ' UTC') >= time() + 118, 'HTTP 429 honors Retry-After');
    $mapped = json_decode(TestTransport::$options[CURLOPT_POSTFIELDS], true)['data'];
    $assert(($mapped['post_id'] ?? 0) === $post && !isset($mapped['content_id']), 'Field aliases apply only to the event data');
    Db::query('UPDATE %i SET next_attempt_at = %s WHERE id = %d', $deliveries, Db::utc(), $delivery['id']);
    TestTransport::$status = 400;
    Queue::sweep();
    $assert(Db::value('SELECT state FROM %i WHERE id = %d', $deliveries, $delivery['id']) === 'dead'
        && Crm::redrive((int) $delivery['id']) === 'queued', 'Terminal failure permits one manual redrive');
    TestTransport::$status = 204;
    Queue::sweep();
    $after = Db::row('SELECT state, delivery_uuid, attempt_count FROM %i WHERE id = %d', $deliveries, $delivery['id']);
    $assert($after['state'] === 'succeeded' && $after['delivery_uuid'] === $delivery['delivery_uuid'] && (int) $after['attempt_count'] === 3,
        'Redrive preserves delivery identity and attempt history');
    TestTransport::$records[] = ['ipv6' => '::1'];
    $connections = TestTransport::$connections;
    $blocked = Outbound::request('HEAD', 'https://example.com/path', 'https://example.com');
    $assert($blocked['code'] === 'dns' && TestTransport::$connections === $connections, 'Mixed public and private DNS answers never connect');
    $assert(Crm::changeState($id, 3, 'revoked') === 'saved', 'Verified test destination is revoked');
}

$assert(Health::snapshot()['checks']['schema'] === 'ok', 'Health projection reports ready schema');
$assert((bool) Audit::search(Db::utc(time() - 3600), Db::utc(time() + 60), 'content.expired', $admin->ID, 25), 'Expiry is recorded in audit');
$assert(Privacy::erase($admin->user_email)['items_removed'], 'Privacy eraser redacts personal attribution');
$assert(!Db::value('SELECT author_user_id FROM %i WHERE id = %d', Db::table('admin_notes'), $note['id']), 'Erased note no longer identifies its author');

if (is_multisite()) {
    $policy = NetworkPolicy::get(true);
    $assert(NetworkPolicy::save(['disabled' => ['monitoring'], 'outbound' => true, 'purge' => false], $policy['revision']) === 'saved',
        'Network policy is saved with a revision');
    $assert((bool) NetworkPolicy::attribution($admin->ID), 'Main site can export network policy attribution');
    $assert(NetworkPolicy::save(['disabled' => [], 'outbound' => false, 'purge' => false], $policy['revision']) === 'conflict',
        'Stale network policy is rejected');
    $siteId = wp_insert_site(['domain' => wp_parse_url(network_home_url(), PHP_URL_HOST), 'path' => '/wpus-' . $suffix . '/', 'network_id' => get_current_network_id()]);
    $assert(is_int($siteId), 'Created a new site on the active network');
    Lifecycle::onSite($siteId, static function () use ($assert, $title): void {
        $assert(Lifecycle::ready(), 'New network site provisioned');
        $assert(Policy::state('monitoring') === 'paused' && !Policy::outbound(), 'Network policy narrows site permissions');
        $assert(!Db::value('SELECT id FROM %i WHERE title = %s', Db::table('admin_notes'), $title), 'Site cannot see another site’s notes');
    });
    $policy = NetworkPolicy::get(true);
    $assert(NetworkPolicy::save(['disabled' => [], 'outbound' => false, 'purge' => false], $policy['revision']) === 'saved',
        'Network policy restored');
    $assert(Privacy::erase($admin->user_email)['items_removed'] && !NetworkPolicy::attribution($admin->ID),
        'Privacy erase removes network policy attribution');
}

WP_CLI::success('WP Utility Suite integration checks passed.');
