<?php
namespace Wpus;

defined('ABSPATH') || exit;

final class Crm
{
    public const ORDER_EVENT = 'woocommerce.order_status_changed';
    private const ORDER_ERASURE = 'woocommerce.order_erasure';

    public const EVENTS = [
        'content.published' => ['content_id', 'content_type'],
        self::ORDER_EVENT => ['order_id', 'previous_status', 'current_status'],
        'woocommerce.stock_below_threshold' => ['product_id', 'stock_status'],
    ];

    public static function available(): bool
    {
        return Secrets::active() !== null && Outbound::available() && function_exists('as_schedule_recurring_action')
            && defined('WPUS_WEBHOOK_ORIGINS') && is_array(WPUS_WEBHOOK_ORIGINS) && WPUS_WEBHOOK_ORIGINS !== [];
    }

    public static function destinations(): array
    {
        return Db::rows("SELECT * FROM %i WHERE state <> 'revoked' ORDER BY id DESC LIMIT 20", Db::table('webhook_destinations'));
    }

    public static function deliveries(): array
    {
        return Db::rows(
            'SELECT d.id, d.delivery_uuid, d.state, d.attempt_count, d.last_code, d.last_http_status, d.created_at,
             d.cycle_count, d.is_test, t.label, e.event_type FROM %i d JOIN %i t ON t.id = d.destination_id
             JOIN %i e ON e.id = d.event_id ORDER BY d.id DESC LIMIT 50',
            Db::table('webhook_deliveries'), Db::table('webhook_destinations'), Db::table('webhook_events')
        );
    }

    public static function attempts(int $id): array
    {
        return Db::rows(
            'SELECT attempt_no, started_at, result, http_status, latency_ms FROM %i WHERE delivery_id = %d ORDER BY attempt_no DESC LIMIT 16',
            Db::table('webhook_attempts'), $id
        );
    }

    public static function configure(int $id, int $revision, string $label, string $url, string $secret, array $events, string $mapping): string
    {
        if (!Policy::canManage('crm') || Policy::state('crm') !== 'active') {
            return 'unavailable';
        }
        $changesSecret = $url !== '' || $secret !== '';
        if (($changesSecret || !$id) && (!current_user_can('wpus_rotate_secrets')
                || (wp_get_environment_type() === 'production' && !is_ssl()))) {
            return 'denied';
        }
        $origin = $url !== '' ? Outbound::origin($url) : null;
        $allowedFields = array_unique(array_merge(...array_values(self::EVENTS)));
        $aliases = $mapping === '' ? [] : json_decode($mapping, true, 3);
        if ($label === '' || strlen($label) > 80 || (!$id && ($url === '' || $secret === ''))
            || ($url !== '' && ($origin === null || !Outbound::approved($origin)))
            || ($secret !== '' && !preg_match('/\A[A-Za-z0-9_-]{32,128}\z/D', $secret))
            || array_diff($events, array_keys(self::EVENTS)) || !is_array($aliases) || count($aliases) > count($allowedFields)) {
            return 'invalid';
        }
        foreach ($aliases as $field => $alias) {
            if (!in_array($field, $allowedFields, true) || !is_string($alias)
                || !preg_match('/\A[A-Za-z][A-Za-z0-9_]{0,39}\z/D', $alias)
                || in_array(strtolower($alias), ['constructor', 'prototype'], true)) {
                return 'invalid';
            }
        }
        foreach (self::EVENTS as $fields) {
            $mapped = array_map(static fn (string $field): string => $aliases[$field] ?? $field, $fields);
            if (count(array_unique($mapped)) !== count($fields)) {
                return 'invalid';
            }
        }
        $events = array_values(array_unique($events));
        return Db::locked('crm', static fn () => Db::transaction(static function () use ($id, $revision, $label, $url, $origin, $secret, $events, $aliases) {
            if (!$id) {
                if (count(self::destinations()) >= 20) {
                    return 'limit';
                }
                $id = Db::insert('webhook_destinations', [
                    'public_id' => wp_generate_uuid4(), 'state' => 'disabled', 'label' => $label,
                    'endpoint_origin' => $origin, 'event_types_json' => wp_json_encode($events),
                    'mapping_json' => wp_json_encode($aliases), 'created_at' => Db::utc(), 'updated_at' => Db::utc(),
                ]);
            } else {
                $old = self::destination($id);
                if (!$old || $old['state'] === 'revoked' || (int) $old['revision'] !== $revision) {
                    return 'conflict';
                }
                $changed = $url !== '' || $secret !== '' || $events !== Db::decode($old['event_types_json'])
                    || $aliases !== Db::decode($old['mapping_json']);
                $updated = Db::query(
                    'UPDATE %i SET label = %s, endpoint_origin = %s, event_types_json = %s, mapping_json = %s,
                     generation = generation + %d, state = %s, revision = revision + 1, updated_at = %s
                     WHERE id = %d AND revision = %d',
                    Db::table('webhook_destinations'), $label, $origin ?? $old['endpoint_origin'], wp_json_encode($events),
                    wp_json_encode($aliases), (int) $changed, $changed ? 'disabled' : $old['state'], Db::utc(), $id, $revision
                );
                if ($updated !== 1) {
                    return $updated === 0 ? 'conflict' : false;
                }
                if ($changed && !self::invalidate($id)) {
                    return false;
                }
            }
            return $id && ($url === '' || Secrets::store($id, 'endpoint', $url))
                && ($secret === '' || Secrets::store($id, 'signing', $secret))
                && Audit::record('destination.saved', 'destination', $id) ? 'saved' : false;
        })) ?: 'error';
    }

    private static function destination(int $id): ?array
    {
        return Db::row('SELECT * FROM %i WHERE id = %d', Db::table('webhook_destinations'), $id);
    }

    private static function invalidate(int $id): bool
    {
        return Db::query(
            "UPDATE %i SET state = 'cancelled', body_ciphertext = NULL, body_key_id = NULL, lease_token = NULL, lease_until = NULL
             WHERE destination_id = %d AND body_ciphertext IS NOT NULL AND state <> 'succeeded'",
            Db::table('webhook_deliveries'), $id
        ) !== false;
    }

    public static function changeState(int $id, int $revision, string $action): string
    {
        if (!Policy::canManage('crm') || !in_array($action, ['active', 'paused', 'revoked'], true)
            || ($action === 'revoked' && !current_user_can('wpus_rotate_secrets'))) {
            return 'denied';
        }
        return Db::locked('crm', static fn () => Db::transaction(static function () use ($id, $revision, $action) {
            $row = self::destination($id);
            if (!$row || $row['state'] === 'revoked' || (int) $row['revision'] !== $revision) {
                return 'conflict';
            }
            if ($action === 'active' && (Policy::state('crm') !== 'active'
                    || $row['verified_generation'] !== $row['generation'] || !Outbound::approved($row['endpoint_origin']))) {
                return 'unavailable';
            }
            $updated = Db::query(
                'UPDATE %i SET state = %s, revision = revision + 1, updated_at = %s WHERE id = %d AND revision = %d',
                Db::table('webhook_destinations'), $action, Db::utc(), $id, $revision
            );
            if ($updated !== 1) {
                return $updated === 0 ? 'conflict' : false;
            }
            if ($action === 'revoked' && (!self::invalidate($id)
                || Db::query('DELETE FROM %i WHERE owner_id = %d', Db::table('secrets'), $id) === false)) {
                return false;
            }
            return Audit::record('destination.' . $action, 'destination', $id) ? 'saved' : false;
        })) ?: 'error';
    }

    public static function test(int $id, int $revision): string
    {
        if (!Policy::canManage('crm') || Policy::state('crm') !== 'active' || !Policy::outbound() || !Queue::available()) {
            return 'unavailable';
        }
        return Db::locked('crm', static fn () => Db::transaction(static function () use ($id, $revision) {
            $destination = self::destination($id);
            if (!$destination || (int) $destination['revision'] !== $revision || $destination['state'] === 'revoked'
                || !Outbound::approved($destination['endpoint_origin'])) {
                return 'conflict';
            }
            if (!Audit::reserve('test_' . gmdate('YmdH') . '_' . $id, 5) || !self::capacity(1)) {
                return 'limit';
            }
            return self::persist('wpus.test', ['synthetic' => true, 'correlation_id' => wp_generate_uuid4()], [$destination], true)
                && Audit::record('destination.test_queued', 'destination', $id) ? 'queued' : false;
        })) ?: 'error';
    }

    private static function capacity(int $add, string $type = ''): bool
    {
        $deliveries = Db::table('webhook_deliveries');
        $count = Db::value(
            "SELECT COUNT(*) FROM %i WHERE state IN ('pending','leased','retry_wait','dead') AND body_ciphertext IS NOT NULL",
            $deliveries
        );
        if ($count === null || (int) $count + $add > 10000) {
            return false;
        }
        if ($type !== 'content.published') {
            return true;
        }
        $content = Db::value(
            "SELECT COUNT(*) FROM %i d JOIN %i e ON e.id = d.event_id
             WHERE e.event_type = %s AND d.state IN ('pending','leased','retry_wait','dead') AND d.body_ciphertext IS NOT NULL",
            $deliveries, Db::table('webhook_events'), $type
        );
        $actor = get_current_user_id();
        return $content !== null && (int) $content + $add <= 500
            && (!$actor || Audit::reserve('content_' . gmdate('YmdH') . '_' . $actor, 20));
    }

    private static function sourceEnabled(string $type): bool
    {
        return match ($type) {
            'content.published', 'wpus.test' => true,
            self::ORDER_EVENT => Policy::feature('woocommerce', 'orders', true),
            'woocommerce.stock_below_threshold' => Policy::feature('woocommerce', 'stock', true),
            default => false,
        };
    }

    public static function observe(string $type, int $object, string $state, ?array $data, ?string $expectedPrevious = null): void
    {
        if ($object < 1 || !isset(self::EVENTS[$type]) || !Policy::feature('crm', 'capture', true) || !self::sourceEnabled($type)) {
            return;
        }
        $ok = Db::locked('crm', static fn () => Db::transaction(static function () use ($type, $object, $state, $data, $expectedPrevious) {
            if ($type === self::ORDER_EVENT && !Commerce::canSendOrder($object)) {
                return true;
            }
            $table = Db::table('event_sources');
            $source = Db::row('SELECT state_code FROM %i WHERE source_type = %s AND object_id = %d', $table, $type, $object);
            if (($source['state_code'] ?? null) === $state) {
                return true;
            }
            if (Db::query(
                'INSERT INTO %i (source_type, object_id, state_code, observed_at) VALUES (%s, %d, %s, %s)
                 ON DUPLICATE KEY UPDATE state_code = VALUES(state_code), observed_at = VALUES(observed_at)',
                $table, $type, $object, $state, Db::utc()
            ) === false) {
                return false;
            }
            if ($data === null || !Policy::outbound()
                || ($expectedPrevious !== null && $source && $source['state_code'] !== $expectedPrevious)) {
                return true;
            }
            $destinations = array_values(array_filter(self::destinations(), static fn (array $row): bool =>
                $row['state'] === 'active' && $row['generation'] === $row['verified_generation']
                && Outbound::approved($row['endpoint_origin']) && in_array($type, Db::decode($row['event_types_json']), true)
            ));
            if (!$destinations) {
                return true;
            }
            $orderId = $type === self::ORDER_EVENT ? $object : null;
            return self::capacity(count($destinations), $type) && self::persist($type, $data, $destinations, false, $orderId);
        }), 1);
        if (!$ok) {
            Audit::reserve('capture_' . gmdate('Ymd'), 1000000);
        }
    }

    private static function persist(string $type, array $data, array $destinations, bool $test, ?int $orderId = null): bool
    {
        $eventUuid = wp_generate_uuid4();
        $event = Db::insert('webhook_events', [
            'event_uuid' => $eventUuid, 'event_type' => $type, 'order_id' => $orderId, 'occurred_at' => Db::utc(),
        ]);
        if (!$event) {
            return false;
        }
        foreach ($destinations as $destination) {
            $delivery = wp_generate_uuid4();
            $mapping = $test ? [] : Db::decode($destination['mapping_json']);
            $mapped = [];
            foreach ($data as $key => $value) {
                if ($test || in_array($key, self::EVENTS[$type], true)) {
                    $mapped[$mapping[$key] ?? $key] = $value;
                }
            }
            $body = wp_json_encode([
                'event_type' => $type, 'schema_version' => 1, 'event_id' => $eventUuid,
                'occurred_at' => gmdate('Y-m-d\TH:i:s\Z'), 'site_id' => get_option('wpus_site_uuid'), 'data' => $mapped,
            ]);
            $encrypted = $body === false ? null : Secrets::seal('delivery', $delivery, $body);
            if (!$encrypted || !Db::insert('webhook_deliveries', [
                'delivery_uuid' => $delivery, 'event_id' => $event, 'destination_id' => $destination['id'],
                'destination_generation' => $destination['generation'], 'body_ciphertext' => $encrypted['encrypted'],
                'body_key_id' => $encrypted['key_id'], 'is_test' => (int) $test, 'state' => 'pending',
                'next_attempt_at' => Db::utc(), 'created_at' => Db::utc(),
            ])) {
                return false;
            }
        }
        return true;
    }

    public static function eraseOrder(int $id): void
    {
        if ($id < 1 || !Lifecycle::ready()) {
            return;
        }
        if (!self::clearOrder($id)) {
            if (Db::query(
                'INSERT INTO %i (source_type, object_id, state_code, observed_at) VALUES (%s, %d, %s, %s)
                 ON DUPLICATE KEY UPDATE object_id = object_id',
                Db::table('event_sources'), self::ORDER_ERASURE, $id, 'pending', Db::utc()
            ) === false) {
                Audit::record('commerce.erasure_failed', 'webhook', null, null, [], 0);
                return;
            }
            Audit::record('commerce.erasure_deferred', 'webhook', null, null, [], 0);
        }
    }

    private static function clearOrder(int $id, int $wait = 1): bool
    {
        $events = Db::table('webhook_events');
        $deliveries = Db::table('webhook_deliveries');
        return Db::locked('crm', static fn () => Db::transaction(static function () use ($id, $events, $deliveries): bool {
            return Db::query(
                "UPDATE %i d JOIN %i e ON e.id = d.event_id SET d.state = 'cancelled',
                 d.body_ciphertext = NULL, d.body_key_id = NULL, d.lease_token = NULL, d.lease_until = NULL, d.last_code = 'order_erased'
                 WHERE e.order_id = %d AND e.event_type = %s AND d.body_ciphertext IS NOT NULL",
                $deliveries, $events, $id, self::ORDER_EVENT
            ) !== false && Db::query(
                "UPDATE %i a JOIN %i d ON a.delivery_id = d.id AND a.attempt_no = d.attempt_count
                 JOIN %i e ON d.event_id = e.id SET a.result = 'order_erased', a.finished_at = %s
                 WHERE e.order_id = %d AND e.event_type = %s AND a.result = 'started'",
                Db::table('webhook_attempts'), $deliveries, $events, Db::utc(), $id, self::ORDER_EVENT
            ) !== false && Db::query('UPDATE %i SET order_id = NULL WHERE order_id = %d AND event_type = %s',
                $events, $id, self::ORDER_EVENT) !== false
                && Db::query('DELETE FROM %i WHERE source_type IN (%s, %s) AND object_id = %d',
                    Db::table('event_sources'), self::ORDER_EVENT, self::ORDER_ERASURE, $id) !== false;
        }), $wait);
    }

    public static function redrive(int $id): string
    {
        if (!Policy::canManage('crm') || Policy::state('crm') !== 'active' || !Policy::outbound()) {
            return 'denied';
        }
        return Db::locked('crm', static fn () => Db::transaction(static function () use ($id) {
            $changed = Db::query(
                "UPDATE %i d JOIN %i t ON t.id = d.destination_id SET d.state = 'pending', d.cycle_count = 2,
                 d.cycle_attempts = 0, d.cycle_started_at = NULL, d.next_attempt_at = %s
                 WHERE d.id = %d AND d.state = 'dead' AND d.cycle_count = 1 AND d.body_ciphertext IS NOT NULL
                 AND d.created_at > %s AND d.destination_generation = t.generation
                 AND (t.state = 'active' OR (d.is_test = 1 AND t.state <> 'revoked'))",
                Db::table('webhook_deliveries'), Db::table('webhook_destinations'), Db::utc(), $id, Db::utc(time() - WEEK_IN_SECONDS)
            );
            if ($changed !== 1) {
                return $changed === 0 ? 'conflict' : false;
            }
            return Audit::record('delivery.redriven', 'delivery', $id) ? 'queued' : false;
        })) ?: 'error';
    }

    public static function sweep(): void
    {
        foreach (Db::rows('SELECT object_id FROM %i WHERE source_type = %s ORDER BY object_id LIMIT 20',
            Db::table('event_sources'), self::ORDER_ERASURE) as $order) {
            if (!self::clearOrder((int) $order['object_id'], 0)) {
                break;
            }
        }
        $deliveries = Db::table('webhook_deliveries');
        $now = Db::utc();
        $cutoff = Db::utc(time() - WEEK_IN_SECONDS);
        Db::query(
            "UPDATE %i SET body_ciphertext = NULL, body_key_id = NULL, lease_token = NULL, lease_until = NULL,
             state = IF(state IN ('dead','succeeded'), state, 'cancelled') WHERE created_at <= %s AND body_ciphertext IS NOT NULL
             ORDER BY created_at LIMIT 100", $deliveries, $cutoff
        );
        if (Policy::state('crm', true) !== 'active' || !Policy::outbound(true)) {
            return;
        }
        $capture = Policy::feature('crm', 'capture');
        $orders = (int) self::sourceEnabled(self::ORDER_EVENT);
        $stock = (int) self::sourceEnabled('woocommerce.stock_below_threshold');
        $deadline = hrtime(true) + 12_000_000_000;
        for ($batch = 0; $batch < 20 && hrtime(true) < $deadline; $batch++) {
            $row = Db::locked('crm', static fn () => Db::transaction(static function () use ($deliveries, $now, $cutoff, $capture, $orders, $stock) {
                $row = Db::row(
                    "SELECT d.*, e.event_type, e.order_id FROM %i d JOIN %i t ON t.id = d.destination_id JOIN %i e ON e.id = d.event_id
                     WHERE d.created_at > %s AND d.body_ciphertext IS NOT NULL AND d.destination_generation = t.generation
                     AND ((d.is_test = 1 AND t.state <> 'revoked') OR (%d = 1 AND t.state = 'active' AND t.verified_generation = t.generation))
                     AND (d.is_test = 1 OR e.event_type = 'content.published'
                          OR (%d = 1 AND e.event_type = 'woocommerce.order_status_changed')
                          OR (%d = 1 AND e.event_type = 'woocommerce.stock_below_threshold'))
                     AND ((d.state IN ('pending','retry_wait') AND d.next_attempt_at <= %s) OR (d.state = 'leased' AND d.lease_until <= %s))
                     ORDER BY d.next_attempt_at, d.id LIMIT 1 FOR UPDATE",
                    $deliveries, Db::table('webhook_destinations'), Db::table('webhook_events'),
                    $cutoff, (int) $capture, $orders, $stock, $now, $now
                );
                if (!$row) {
                    return [];
                }
                if ($row['state'] === 'leased' && Db::query(
                    "UPDATE %i SET result = 'lease_expired', finished_at = %s WHERE delivery_id = %d AND attempt_no = %d AND result = 'started'",
                    Db::table('webhook_attempts'), $now, $row['id'], $row['attempt_count']
                ) === false) {
                    return false;
                }
                if ((int) $row['cycle_attempts'] >= 8
                    || ($row['cycle_started_at'] && $row['cycle_started_at'] <= Db::utc(time() - 3 * DAY_IN_SECONDS))) {
                    return Db::query(
                        "UPDATE %i SET state = 'dead', last_code = 'attempts_exhausted', lease_token = NULL, lease_until = NULL WHERE id = %d",
                        $deliveries, $row['id']
                    ) === 1 ? ['finished' => true] : false;
                }
                $row['lease_token'] = wp_generate_uuid4();
                $row['attempt_count'] = (int) $row['attempt_count'] + 1;
                $row['cycle_attempts'] = (int) $row['cycle_attempts'] + 1;
                $row['cycle_started_at'] ??= $now;
                if (Db::query(
                    "UPDATE %i SET state = 'leased', lease_token = %s, lease_until = %s, attempt_count = %d,
                     cycle_attempts = %d, cycle_started_at = %s WHERE id = %d",
                    $deliveries, $row['lease_token'], Db::utc(time() + 90), $row['attempt_count'],
                    $row['cycle_attempts'], $row['cycle_started_at'], $row['id']
                ) !== 1 || !Db::insert('webhook_attempts', [
                    'delivery_id' => $row['id'], 'attempt_no' => $row['attempt_count'], 'started_at' => $now, 'result' => 'started',
                ])) {
                    return false;
                }
                return $row;
            }));
            if (!$row) {
                break;
            }
            if (!isset($row['finished'])) {
                self::send($row);
            }
        }
    }

    private static function send(array $row): void
    {
        if ($row['event_type'] === self::ORDER_EVENT && !Commerce::canSendOrder((int) $row['order_id'])) {
            self::finish($row, 'cancelled', 'order_unavailable', 0, 0);
            return;
        }
        $destination = self::destination((int) $row['destination_id']);
        if (!$destination || $destination['generation'] !== $row['destination_generation'] || $destination['state'] === 'revoked') {
            self::finish($row, 'cancelled', 'destination_changed', 0, 0);
            return;
        }
        if (Policy::state('crm', true) !== 'active' || !Policy::outbound()
            || (!(int) $row['is_test'] && ($destination['state'] !== 'active' || !Policy::feature('crm', 'capture')))) {
            self::finish($row, 'retry_wait', 'policy_paused', 0, 0, time() + 60);
            return;
        }
        $origin = $destination['endpoint_origin'];
        $endpoint = Secrets::resolve((int) $destination['id'], 'endpoint');
        $secret = Secrets::resolve((int) $destination['id'], 'signing');
        $body = Secrets::open('delivery', $row['delivery_uuid'], $row['body_key_id'], $row['body_ciphertext']);
        if (!$endpoint || !$secret || $body === null || !Outbound::approved($origin)) {
            self::finish($row, 'dead', 'destination_unavailable', 0, 0);
            return;
        }
        $timestamp = (string) time();
        $signature = hash_hmac('sha256', $timestamp . "\n" . $row['delivery_uuid'] . "\n" . $body, $secret);
        $result = Outbound::request('POST', $endpoint, $origin, [
            'Content-Type: application/json',
            'X-WPUS-Delivery-ID: ' . $row['delivery_uuid'],
            'X-WPUS-Timestamp: ' . $timestamp,
            'X-WPUS-Key-ID: ' . substr(hash('sha256', $secret), 0, 16),
            'X-WPUS-Signature: v1=' . $signature,
        ], $body, 5, static function () use ($row): bool {
            if (Policy::state('crm', true) !== 'active') {
                return false;
            }
            $current = Db::row(
                "SELECT t.state, t.endpoint_origin, e.event_type, e.order_id FROM %i d JOIN %i t ON t.id = d.destination_id JOIN %i e ON e.id = d.event_id
                 WHERE d.id = %d AND d.lease_token = %s AND d.state = 'leased' AND d.destination_generation = t.generation
                 AND d.lease_until > %s AND d.created_at > %s AND d.cycle_started_at > %s",
                Db::table('webhook_deliveries'), Db::table('webhook_destinations'), Db::table('webhook_events'),
                $row['id'], $row['lease_token'], Db::utc(), Db::utc(time() - WEEK_IN_SECONDS), Db::utc(time() - 3 * DAY_IN_SECONDS)
            );
            return $current && $current['state'] !== 'revoked' && Outbound::approved($current['endpoint_origin'])
                && ((int) $row['is_test'] || ($current['state'] === 'active' && Policy::feature('crm', 'capture')
                    && self::sourceEnabled($current['event_type'])
                    && ($current['event_type'] !== self::ORDER_EVENT || Commerce::canSendOrder((int) $current['order_id']))));
        });
        $status = $result['status'];
        if ($result['code'] === 'policy_paused') {
            self::finish($row, 'retry_wait', 'policy_paused', 0, 0, time() + 60);
            return;
        }
        if ($result['code'] === 'http' && $status >= 200 && $status < 300) {
            self::finish($row, 'succeeded', 'accepted', $status, $result['latency']);
            return;
        }
        $minutes = [1, 5, 15, 60, 240, 720, 1440];
        $delay = ($minutes[$row['cycle_attempts'] - 1] ?? 0) * 60;
        $retryable = in_array($result['code'], ['transport', 'dns'], true) || in_array($status, [408, 429], true) || $status >= 500;
        $next = time() + $delay + wp_rand(0, min(60, (int) ($delay / 10)));
        if (in_array($status, [429, 503], true) && $result['retry_after'] !== null) {
            $next = max($next, time() + $result['retry_after']);
        }
        $deadline = min(strtotime($row['created_at'] . ' UTC') + WEEK_IN_SECONDS, strtotime($row['cycle_started_at'] . ' UTC') + 3 * DAY_IN_SECONDS);
        $retry = $retryable && $delay && $next < $deadline;
        self::finish($row, $retry ? 'retry_wait' : 'dead', $result['code'], $status, $result['latency'], $retry ? $next : null);
    }

    private static function finish(array $row, string $state, string $code, int $status, int $latency, ?int $next = null): void
    {
        Db::transaction(static function () use ($row, $state, $code, $status, $latency, $next) {
            $clear = in_array($state, ['succeeded', 'cancelled'], true);
            $changed = Db::query(
                'UPDATE %i SET state = %s, last_code = %s, last_http_status = NULLIF(%d, 0), next_attempt_at = %s,
                 lease_token = NULL, lease_until = NULL, body_ciphertext = IF(%d, NULL, body_ciphertext),
                 body_key_id = IF(%d, NULL, body_key_id) WHERE id = %d AND state = %s AND lease_token = %s',
                Db::table('webhook_deliveries'), $state, $code, $status, Db::utc($next),
                (int) $clear, (int) $clear, $row['id'], 'leased', $row['lease_token']
            );
            if ($changed !== 1 || Db::query(
                'UPDATE %i SET finished_at = %s, result = %s, http_status = NULLIF(%d, 0), latency_ms = %d
                 WHERE delivery_id = %d AND attempt_no = %d',
                Db::table('webhook_attempts'), Db::utc(), $code, $status, $latency, $row['id'], $row['attempt_count']
            ) !== 1) {
                return false;
            }
            if ($state === 'succeeded' && (int) $row['is_test']) {
                return Db::query(
                    "UPDATE %i SET verified_generation = generation, revision = revision + 1
                     WHERE id = %d AND generation = %d AND verified_generation <> generation AND state <> 'revoked'",
                    Db::table('webhook_destinations'), $row['destination_id'], $row['destination_generation']
                ) !== false;
            }
            if (in_array($status, [401, 403], true)) {
                return Db::query(
                    "UPDATE %i SET state = 'paused', revision = revision + 1
                     WHERE id = %d AND generation = %d AND state = 'active'",
                    Db::table('webhook_destinations'), $row['destination_id'], $row['destination_generation']
                ) !== false;
            }
            return true;
        });
    }

    public static function prune(): void
    {
        $cutoff = Db::utc(time() - 30 * DAY_IN_SECONDS);
        $deliveries = Db::table('webhook_deliveries');
        $events = Db::table('webhook_events');
        $attempts = Db::table('webhook_attempts');
        foreach (Db::rows('SELECT id FROM %i WHERE created_at < %s ORDER BY created_at LIMIT 100', $deliveries, $cutoff) as $row) {
            Db::transaction(static fn () => Db::query('DELETE FROM %i WHERE delivery_id = %d', $attempts, $row['id']) !== false
                && Db::query('DELETE FROM %i WHERE id = %d', $deliveries, $row['id']) !== false);
        }
        foreach (Db::rows('SELECT id FROM %i WHERE occurred_at < %s ORDER BY occurred_at LIMIT 100', $events, $cutoff) as $row) {
            Db::query('DELETE FROM %i WHERE id = %d AND NOT EXISTS (SELECT 1 FROM %i WHERE event_id = %d)',
                $events, $row['id'], $deliveries, $row['id']);
        }
        $destinations = Db::table('webhook_destinations');
        foreach (Db::rows("SELECT id FROM %i WHERE state = 'revoked' AND updated_at < %s LIMIT 20", $destinations, $cutoff) as $row) {
            Db::query('DELETE FROM %i WHERE id = %d AND NOT EXISTS (SELECT 1 FROM %i WHERE destination_id = %d)',
                $destinations, $row['id'], $deliveries, $row['id']);
        }
    }
}
