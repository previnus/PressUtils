<?php
/** Run on a disposable site with the isolated DNS and TLS receiver fixture. */

use Wpus\Crm;
use Wpus\Db;
use Wpus\Outbound;
use Wpus\Queue;
use Wpus\Secrets;

$origin = 'https://receiver.example.test';
$secret = getenv('WPUS_RECEIVER_KEY');
if (!defined('WP_CLI') || !WP_CLI || wp_get_environment_type() !== 'local' || !$secret || !Outbound::approved($origin)) {
    throw new RuntimeException('Use the isolated release fixture.');
}
$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        WP_CLI::error($message);
    }
    WP_CLI::log('PASS ' . $message);
};
$admin = get_users(['role' => 'administrator', 'number' => 1])[0];
wp_set_current_user($admin->ID);
update_option('wpus_outbound_paused', false, false);
$url = $origin . '/recover';
$assert(Crm::configure(0, 0, 'Release receiver', $url, $secret, [], '') === 'saved', 'Configures the isolated HTTPS receiver');
$id = (int) Db::value('SELECT id FROM %i WHERE label = %s', Db::table('webhook_destinations'), 'Release receiver');
$assert(Crm::test($id, 1) === 'queued', 'Queues a real HTTPS synthetic delivery');
Queue::sweep();
$table = Db::table('webhook_deliveries');
$row = Db::row('SELECT * FROM %i WHERE destination_id = %d', $table, $id);
$assert($row['state'] === 'retry_wait' && (int) $row['last_http_status'] === 503
    && strtotime($row['next_attempt_at'] . ' UTC') >= time() + 85, 'Real HTTP 503 schedules a retry using Retry-After');
$body = Secrets::open('delivery', $row['delivery_uuid'], $row['body_key_id'], $row['body_ciphertext']);
$timestamp = (string) time();
$signature = hash_hmac('sha256', $timestamp . "\n" . $row['delivery_uuid'] . "\n" . $body, $secret);
$headers = ['Content-Type: application/json', 'X-WPUS-Delivery-ID: ' . $row['delivery_uuid'],
    'X-WPUS-Timestamp: ' . $timestamp, 'X-WPUS-Key-ID: ' . substr(hash('sha256', $secret), 0, 16)];
$rejected = Outbound::request('POST', $url, $origin, [...$headers, 'X-WPUS-Signature: v1=' . str_repeat('0', 64)], $body);
$assert($rejected['status'] === 401, 'Receiver rejects an invalid signature');
Db::query('UPDATE %i SET next_attempt_at = %s WHERE id = %d', $table, Db::utc(), $row['id']);
Queue::sweep();
$after = Db::row('SELECT * FROM %i WHERE id = %d', $table, $row['id']);
$assert($after['state'] === 'succeeded' && (int) $after['attempt_count'] === 2 && $after['body_ciphertext'] === null,
    'Real TLS retry recovers using the same delivery ID and clears the body');
$duplicate = Outbound::request('POST', $url, $origin, [...$headers, 'X-WPUS-Signature: v1=' . $signature], $body);
$assert($duplicate['status'] === 208, 'Receiver deduplicates a valid repeat');
$mismatch = 'https://badcert.example.test';
$badTls = Outbound::request('POST', $mismatch . '/recover', $mismatch, [], $body);
$assert($badTls['code'] === 'transport' && $badTls['status'] === 0, 'Real TLS rejects a mismatched certificate hostname');
$private = 'https://private.example.test';
$blocked = Outbound::request('GET', $private . '/accept', $private);
$assert($blocked['code'] === 'dns', 'Real DNS private answers are rejected before opening a connection');

$assert(Crm::configure($id, 2, 'Release receiver', $origin . '/accept', '', [], '') === 'saved', 'Uses a healthy receiver for the queue load fixture');
$assert(Crm::test($id, 3) === 'queued', 'Verifies the updated receiver');
Queue::sweep();
$generation = (int) Db::value('SELECT generation FROM %i WHERE id = %d', Db::table('webhook_destinations'), $id);
$now = time();
$events = Db::table('webhook_events');
// Each delivery needs a distinct event ID because the outbox deduplicates event/destination pairs.
for ($offset = 0; $offset < 10000; $offset += 200) {
    $eventsArgs = [];
    $deliveriesArgs = [];
    for ($i = 0; $i < 200; $i++) {
        $eventsArgs[] = wp_generate_uuid4();
    }
    $assertSql = Db::query('INSERT INTO %i (event_uuid, event_type, occurred_at) VALUES '
        . implode(',', array_fill(0, 200, "(%s,'wpus.test',UTC_TIMESTAMP())")), $events, ...$eventsArgs);
    if ($assertSql !== 200) {
        WP_CLI::error('Unable to seed synthetic load events.');
    }
    global $wpdb;
    $first = (int) $wpdb->insert_id;
    $eventRows = Db::rows('SELECT id FROM %i WHERE id >= %d ORDER BY id LIMIT 200', $events, $first);
    foreach ($eventRows as $i => $eventRow) {
        $delivery = wp_generate_uuid4();
        $encrypted = Secrets::seal('delivery', $delivery, $body);
        array_push($deliveriesArgs, $delivery, $eventRow['id'], $id, $generation, $encrypted['encrypted'],
            $encrypted['key_id'], Db::utc($now + ($offset + $i < 60 ? 0 : HOUR_IN_SECONDS)), Db::utc($now));
    }
    if (Db::query('INSERT INTO %i (delivery_uuid,event_id,destination_id,destination_generation,body_ciphertext,body_key_id,is_test,state,next_attempt_at,created_at) VALUES '
        . implode(',', array_fill(0, 200, "(%s,%d,%d,%d,%s,%s,1,'pending',%s,%s)")), $table, ...$deliveriesArgs) !== 200) {
        WP_CLI::error('Unable to seed synthetic load deliveries.');
    }
}
$assert((int) Db::value('SELECT COUNT(*) FROM %i WHERE destination_id = %d AND state = %s', $table, $id, 'pending') === 10000,
    'Seeds 10,000 encrypted synthetic deliveries');
$observed = [];
for ($tick = 0; $tick < 4; $tick++) {
    $start = microtime(true);
    Queue::sweep();
    $succeeded = (int) Db::value('SELECT COUNT(*) FROM %i WHERE destination_id = %d AND state = %s', $table, $id, 'succeeded') - 2;
    $measured = $tick * 60 + microtime(true) - $start;
    array_push($observed, ...array_fill(0, $succeeded - count($observed), $measured));
}
$assert(count($observed) === 60 && $observed[56] < 300, sprintf('60 eligible deliveries at depth 10,000 clear with projected one-minute cadence p95 %.2fs', $observed[56] ?? 300));
$assert((int) Db::value('SELECT COUNT(*) FROM %i WHERE destination_id = %d AND state = %s', $table, $id, 'pending') === 9940,
    'Future deliveries stay queued');
