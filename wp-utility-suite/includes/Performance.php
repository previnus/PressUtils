<?php
namespace Wpus;

defined('ABSPATH') || exit;

final class Performance
{
    public static function diagnostics(): array
    {
        global $wpdb;
        $autoload = array_values(wp_autoload_values_to_autoload());
        $placeholders = implode(', ', array_fill(0, count($autoload), '%s'));
        $options = $autoload ? Db::rows(
            "SELECT option_name, LENGTH(option_value) AS bytes FROM %i
             WHERE autoload IN ({$placeholders}) ORDER BY bytes DESC LIMIT 20",
            $wpdb->options, ...$autoload
        ) : [];

        $overdue = 0;
        foreach (_get_cron_array() as $timestamp => $hooks) {
            if ((int) $timestamp >= time() - 300) {
                break;
            }
            foreach ($hooks as $scheduled) {
                $overdue += count($scheduled);
            }
        }
        return ['overdue' => $overdue, 'options' => $options];
    }

    public static function httpHooks(): void
    {
        $started = [];
        add_filter('pre_http_request', static function ($result, array $args, string $url) use (&$started) {
            if ($result === false && count($started) < 30) {
                $started[hash('sha256', $url)] = hrtime(true);
            }
            return $result;
        }, PHP_INT_MAX, 3);
        add_action('http_api_debug', static function ($response, string $context, string $transport, array $args, string $url) use (&$started): void {
            $key = hash('sha256', $url);
            if ($context !== 'response' || !isset($started[$key])) {
                return;
            }
            $elapsed = (int) ((hrtime(true) - $started[$key]) / 1_000_000);
            unset($started[$key]);
            if ($elapsed >= 1000 && Audit::reserve('slow_http_' . gmdate('Ymd'), 250)) {
                $status = wp_remote_retrieve_response_code($response);
                Db::insert('monitoring_events', [
                    'check_key' => 'slow_http',
                    'details_json' => wp_json_encode(['milliseconds' => $elapsed, 'class' => $status ? (int) floor($status / 100) . 'xx' : 'transport_error']),
                    'observed_at' => Db::utc(),
                ]);
            }
        }, 10, 5);
    }

    public static function prune(): void
    {
        $retention = (int) Settings::get('security')['config']['retention'];
        $cutoffs = [
            'audit_logs' => ['occurred_at', Db::utc(time() - $retention * DAY_IN_SECONDS)],
            'monitoring_events' => ['observed_at', Db::utc(time() - 30 * DAY_IN_SECONDS)],
            '404_events' => ['day_utc', gmdate('Y-m-d', time() - 30 * DAY_IN_SECONDS)],
            'operations' => ['expires_at', Db::utc()],
            'event_sources' => ['observed_at', Db::utc(time() - 30 * DAY_IN_SECONDS)],
        ];
        foreach ($cutoffs as $table => [$column, $cutoff]) {
            Db::query('DELETE FROM %i WHERE %i < %s ORDER BY %i LIMIT 500', Db::table($table), $column, $cutoff, $column);
        }
        $yesterday = gmdate('Ymd', time() - DAY_IN_SECONDS);
        foreach (['audit_', '404_hits_', '404_paths_', 'slow_http_', 'login_alert_', 'op_', 'test_', 'rest_', 'capture_', 'content_'] as $prefix) {
            global $wpdb;
            Db::query(
                'DELETE FROM %i WHERE counter_key LIKE %s AND counter_key < %s LIMIT 200',
                Db::table('counters'), $wpdb->esc_like($prefix) . '%', $prefix . $yesterday
            );
        }
        Crm::prune();
    }

    private static function cleanupTables(): array
    {
        $days = (int) Settings::get('security', true)['config']['retention'];
        return [
            'audit_logs' => ['occurred_at', Db::utc(time() - $days * DAY_IN_SECONDS)],
            'monitoring_events' => ['observed_at', Db::utc(time() - 30 * DAY_IN_SECONDS)],
        ];
    }

    public static function previewCleanup(): string
    {
        if (!Policy::canManage('performance') || Policy::state('performance') !== 'active') {
            return 'denied';
        }
        return Db::transaction(static function () {
            if (!Audit::reserve('op_' . gmdate('YmdH') . '_' . get_current_user_id(), 30)) {
                return 'limit';
            }
            $candidates = [];
            foreach (self::cleanupTables() as $name => [$column, $cutoff]) {
                $candidates[$name] = array_column(Db::rows(
                    'SELECT id FROM %i WHERE %i < %s ORDER BY %i, id LIMIT 100',
                    Db::table($name), $column, $cutoff, $column
                ), 'id');
            }
            $id = Db::insert('operations', [
                'public_id' => wp_generate_uuid4(), 'operation_type' => 'cleanup', 'actor_user_id' => get_current_user_id(),
                'args_json' => wp_json_encode($candidates), 'state' => 'preview', 'run_at' => Db::utc(),
                'expires_at' => Db::utc(time() + 900), 'created_at' => Db::utc(),
            ]);
            return $id && Audit::record('cleanup.previewed', 'operation', $id) ? 'saved' : false;
        }) ?: 'error';
    }

    public static function cleanupPreview(): ?array
    {
        return Db::row(
            "SELECT id, revision, args_json, expires_at FROM %i WHERE operation_type = 'cleanup'
             AND actor_user_id = %d AND state = 'preview' AND expires_at > %s ORDER BY id DESC LIMIT 1",
            Db::table('operations'), get_current_user_id(), Db::utc()
        );
    }

    public static function applyCleanup(int $id, int $revision): string
    {
        if (!Policy::canManage('performance') || Policy::state('performance') !== 'active') {
            return 'denied';
        }
        return Db::locked('sweep', static fn () => Db::transaction(static function () use ($id, $revision) {
            $preview = Db::row(
                "SELECT args_json FROM %i WHERE operation_type = 'cleanup' AND actor_user_id = %d AND id = %d
                 AND revision = %d AND state = 'preview' AND expires_at > %s FOR UPDATE",
                Db::table('operations'), get_current_user_id(), $id, $revision, Db::utc()
            );
            if (!$preview) {
                return 'conflict';
            }
            $candidates = Db::decode($preview['args_json']);
            $count = 0;
            foreach (self::cleanupTables() as $name => [$column, $cutoff]) {
                $ids = $candidates[$name] ?? [];
                if (!$ids) {
                    continue;
                }
                $placeholders = implode(', ', array_fill(0, count($ids), '%d'));
                $deleted = Db::query("DELETE FROM %i WHERE %i < %s AND id IN ({$placeholders})",
                    Db::table($name), $column, $cutoff, ...$ids);
                if ($deleted === false) {
                    return false;
                }
                $count += $deleted;
            }
            return Db::query(
                "UPDATE %i SET state = 'completed', revision = revision + 1, result_code = %s,
                 args_json = '{}', expires_at = %s WHERE id = %d", Db::table('operations'),
                'removed_' . $count, Db::utc(time() + WEEK_IN_SECONDS), $id
            ) === 1 && Audit::record('cleanup.applied', 'operation', $id, null, ['result' => 'removed_' . $count]) ? 'saved' : false;
        })) ?: 'busy';
    }
}
