<?php
namespace Wpus;

defined('ABSPATH') || exit;

final class Health
{
    public static function snapshot(): array
    {
        if (!Lifecycle::ready()) {
            return ['status' => 'unknown', 'checks' => ['schema' => 'setup']];
        }
        $maintenance = Db::value(
            "SELECT observed_at FROM %i WHERE check_key = 'maintenance' ORDER BY observed_at DESC LIMIT 1",
            Db::table('monitoring_events')
        );
        $scheduler = Queue::available() ? (!$maintenance ? 'unknown' :
            (strcmp($maintenance, Db::utc(time() - 1200)) >= 0 ? 'ok' : 'stale')) : 'unavailable';
        $deliveries = Db::table('webhook_deliveries');
        $queue = Db::row(
            "SELECT SUM(state IN ('pending','retry_wait','leased')) AS waiting, SUM(state = 'dead') AS dead
             FROM %i WHERE created_at >= %s", $deliveries, Db::utc(time() - WEEK_IN_SECONDS)
        );
        $dropped = Db::value('SELECT counter_value FROM %i WHERE counter_key = %s',
            Db::table('counters'), 'capture_' . gmdate('Ymd'));
        return [
            'status' => $scheduler === 'unknown' ? 'unknown' : ($scheduler === 'ok' && !$dropped && empty($queue['dead']) ? 'ok' : 'degraded'),
            'checks' => ['schema' => 'ok', 'scheduler' => $scheduler, 'outbound' => Policy::outbound() ? 'allowed' : 'paused'],
            'last_maintenance_utc' => $maintenance,
            'queue' => ['waiting' => (int) ($queue['waiting'] ?? 0), 'dead' => (int) ($queue['dead'] ?? 0), 'dropped_today' => (int) $dropped],
        ];
    }
}
