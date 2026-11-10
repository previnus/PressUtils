<?php
namespace Wpus;

defined('ABSPATH') || exit;

final class Db
{
    public const TABLES = [
        'settings', 'audit_logs', 'admin_notes', 'operations', '404_events', 'redirects',
        'monitoring_events', 'webhook_destinations', 'webhook_events', 'webhook_deliveries',
        'webhook_attempts', 'event_sources', 'secrets', 'counters',
    ];

    public static function table(string $name): string
    {
        global $wpdb;
        return $wpdb->prefix . 'wpus_' . $name;
    }

    public static function utc(?int $time = null): string
    {
        return gmdate('Y-m-d H:i:s', $time ?? time());
    }

    public static function prepare(string $sql, mixed ...$args): string
    {
        global $wpdb;
        return $wpdb->prepare($sql, ...$args);
    }

    public static function query(string $sql, mixed ...$args): int|bool
    {
        global $wpdb;
        return $wpdb->query(self::prepare($sql, ...$args));
    }

    public static function row(string $sql, mixed ...$args): ?array
    {
        global $wpdb;
        return $wpdb->get_row(self::prepare($sql, ...$args), ARRAY_A);
    }

    public static function rows(string $sql, mixed ...$args): array
    {
        global $wpdb;
        return $wpdb->get_results(self::prepare($sql, ...$args), ARRAY_A) ?: [];
    }

    public static function value(string $sql, mixed ...$args): mixed
    {
        global $wpdb;
        return $wpdb->get_var(self::prepare($sql, ...$args));
    }

    public static function insert(string $table, array $record): int
    {
        global $wpdb;
        return $wpdb->insert(self::table($table), $record) === 1 ? (int) $wpdb->insert_id : 0;
    }

    public static function transaction(callable $write): mixed
    {
        global $wpdb;
        if ($wpdb->query('START TRANSACTION') === false) {
            return false;
        }
        $committed = false;
        try {
            $result = $write();
            if ($result !== false && $wpdb->query('COMMIT') !== false) {
                $committed = true;
                return $result;
            }
            return false;
        } finally {
            if (!$committed) {
                $wpdb->query('ROLLBACK');
            }
        }
    }

    public static function locked(string $operation, callable $run, int $wait = 0): mixed
    {
        $key = 'wpus_' . substr(hash('sha256', DB_NAME . '|' . self::table($operation)), 0, 56);
        if ((int) self::value('SELECT GET_LOCK(%s, %d)', $key, $wait) !== 1) {
            return false;
        }
        try {
            return $run();
        } finally {
            self::value('SELECT RELEASE_LOCK(%s)', $key);
        }
    }

    public static function decode(?string $json): array
    {
        $data = json_decode($json ?? '', true, 16);
        return is_array($data) ? $data : [];
    }
}
