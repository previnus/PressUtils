<?php
namespace Wpus;

defined('ABSPATH') || exit;

final class Schema
{
    public const VERSION = 2;

    private static function definitions(): array
    {
        return [
            'settings' => "
                module_key varchar(32) NOT NULL,
                revision bigint unsigned NOT NULL DEFAULT 1,
                config_json longtext NOT NULL,
                updated_by bigint unsigned NULL,
                updated_at datetime NOT NULL,
                PRIMARY KEY  (module_key)",
            'audit_logs' => "
                id bigint unsigned NOT NULL AUTO_INCREMENT,
                event_type varchar(40) NOT NULL,
                actor_user_id bigint unsigned NULL,
                subject_user_id bigint unsigned NULL,
                object_kind varchar(20) NULL,
                object_id bigint unsigned NULL,
                details_json text NOT NULL,
                occurred_at datetime NOT NULL,
                PRIMARY KEY  (id),
                KEY occurred (occurred_at,id),
                KEY event_type (event_type,occurred_at,id),
                KEY actor (actor_user_id,id),
                KEY subject (subject_user_id,id)",
            'admin_notes' => "
                id bigint unsigned NOT NULL AUTO_INCREMENT,
                post_id bigint unsigned NULL,
                author_user_id bigint unsigned NULL,
                title varchar(160) NOT NULL,
                body_text text NOT NULL,
                revision bigint unsigned NOT NULL DEFAULT 1,
                created_at datetime NOT NULL,
                updated_at datetime NOT NULL,
                PRIMARY KEY  (id),
                KEY author (author_user_id,id),
                KEY post_id (post_id,id)",
            'operations' => "
                id bigint unsigned NOT NULL AUTO_INCREMENT,
                public_id char(36) NOT NULL,
                operation_type varchar(32) NOT NULL,
                actor_user_id bigint unsigned NULL,
                object_id bigint unsigned NULL,
                args_json text NOT NULL,
                state varchar(16) NOT NULL,
                result_code varchar(32) NULL,
                revision bigint unsigned NOT NULL DEFAULT 1,
                run_at datetime NOT NULL,
                expires_at datetime NOT NULL,
                created_at datetime NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY public_id (public_id),
                KEY due (state,run_at,id),
                KEY expiry (expires_at,id),
                KEY object_state (operation_type,object_id,state),
                KEY actor (actor_user_id,id)",
            '404_events' => "
                id bigint unsigned NOT NULL AUTO_INCREMENT,
                day_utc date NOT NULL,
                path_digest char(64) NOT NULL,
                hit_count bigint unsigned NOT NULL DEFAULT 1,
                last_seen_at datetime NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY day_path (day_utc,path_digest)",
            'redirects' => "
                id bigint unsigned NOT NULL AUTO_INCREMENT,
                source_hash char(64) NOT NULL,
                source_path varchar(1024) NOT NULL,
                target_path varchar(1024) NOT NULL,
                status_code smallint unsigned NOT NULL,
                revision bigint unsigned NOT NULL DEFAULT 1,
                updated_at datetime NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY source_hash (source_hash)",
            'monitoring_events' => "
                id bigint unsigned NOT NULL AUTO_INCREMENT,
                check_key varchar(32) NOT NULL,
                details_json text NOT NULL,
                observed_at datetime NOT NULL,
                PRIMARY KEY  (id),
                KEY check_time (check_key,observed_at,id),
                KEY observed (observed_at,id)",
            'webhook_destinations' => "
                id bigint unsigned NOT NULL AUTO_INCREMENT,
                public_id char(36) NOT NULL,
                revision bigint unsigned NOT NULL DEFAULT 1,
                generation bigint unsigned NOT NULL DEFAULT 1,
                verified_generation bigint unsigned NOT NULL DEFAULT 0,
                state varchar(16) NOT NULL,
                label varchar(80) NOT NULL,
                endpoint_origin varchar(255) NOT NULL,
                event_types_json text NOT NULL,
                mapping_json text NOT NULL,
                created_at datetime NOT NULL,
                updated_at datetime NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY public_id (public_id),
                KEY state (state,id)",
            'secrets' => "
                owner_id bigint unsigned NOT NULL,
                purpose varchar(20) NOT NULL,
                key_id varchar(32) NOT NULL,
                encrypted text NOT NULL,
                PRIMARY KEY  (owner_id,purpose)",
            'event_sources' => "
                source_type varchar(40) NOT NULL,
                object_id bigint unsigned NOT NULL,
                state_code varchar(32) NOT NULL,
                observed_at datetime NOT NULL,
                PRIMARY KEY  (source_type,object_id),
                KEY observed (observed_at,object_id)",
            'webhook_events' => "
                id bigint unsigned NOT NULL AUTO_INCREMENT,
                event_uuid char(36) NOT NULL,
                event_type varchar(40) NOT NULL,
                order_id bigint unsigned NULL,
                occurred_at datetime NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY event_uuid (event_uuid),
                KEY occurred (occurred_at,id),
                KEY order_event (order_id,event_type)",
            'webhook_deliveries' => "
                id bigint unsigned NOT NULL AUTO_INCREMENT,
                delivery_uuid char(36) NOT NULL,
                event_id bigint unsigned NOT NULL,
                destination_id bigint unsigned NOT NULL,
                destination_generation bigint unsigned NOT NULL,
                body_ciphertext longtext NULL,
                body_key_id varchar(32) NULL,
                is_test tinyint unsigned NOT NULL DEFAULT 0,
                state varchar(16) NOT NULL,
                attempt_count int unsigned NOT NULL DEFAULT 0,
                cycle_count tinyint unsigned NOT NULL DEFAULT 1,
                cycle_attempts tinyint unsigned NOT NULL DEFAULT 0,
                cycle_started_at datetime NULL,
                next_attempt_at datetime NOT NULL,
                lease_token char(36) NULL,
                lease_until datetime NULL,
                last_code varchar(24) NULL,
                last_http_status smallint unsigned NULL,
                created_at datetime NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY delivery_uuid (delivery_uuid),
                UNIQUE KEY event_destination (event_id,destination_id),
                KEY due (state,next_attempt_at,id),
                KEY lease (state,lease_until,id),
                KEY destination (destination_id,state,id),
                KEY created (created_at,id)",
            'webhook_attempts' => "
                id bigint unsigned NOT NULL AUTO_INCREMENT,
                delivery_id bigint unsigned NOT NULL,
                attempt_no int unsigned NOT NULL,
                started_at datetime NOT NULL,
                finished_at datetime NULL,
                result varchar(24) NOT NULL,
                http_status smallint unsigned NULL,
                latency_ms int unsigned NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY delivery_attempt (delivery_id,attempt_no),
                KEY started (started_at,id)",
            'counters' => "
                counter_key varchar(80) NOT NULL,
                counter_value bigint unsigned NOT NULL DEFAULT 0,
                PRIMARY KEY  (counter_key)",
        ];
    }

    public static function install(): bool
    {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        foreach (self::definitions() as $name => $definition) {
            $table = Db::table($name);
            dbDelta("CREATE TABLE {$table} (" . trim($definition) . ") ENGINE=InnoDB {$charset};");
            $status = Db::row('SHOW TABLE STATUS WHERE Name = %s', $table);
            if (strtolower($status['Engine'] ?? '') !== 'innodb') {
                return false;
            }
            preg_match_all('/^\s*([a-z_0-9]+) [a-z]/m', $definition, $columns);
            preg_match_all('/^\s*(PRIMARY KEY|(?:UNIQUE )?KEY [a-z_0-9]+) +\(/m', $definition, $indexes);
            $expectedIndexes = array_map(
                static fn (string $key): string => $key === 'PRIMARY KEY' ? 'PRIMARY' : substr(strrchr($key, ' '), 1),
                $indexes[1]
            );
            if (array_diff($columns[1], array_column(Db::rows('SHOW COLUMNS FROM %i', $table), 'Field'))
                || array_diff($expectedIndexes, array_column(Db::rows('SHOW INDEX FROM %i', $table), 'Key_name'))) {
                return false;
            }
        }
        if (Db::query(
            "UPDATE %i d JOIN %i e ON e.id = d.event_id SET d.state = 'cancelled', d.body_ciphertext = NULL,
             d.body_key_id = NULL, d.lease_token = NULL, d.lease_until = NULL, d.last_code = 'legacy_order_unlinked'
             WHERE e.event_type = %s AND e.order_id IS NULL AND d.body_ciphertext IS NOT NULL",
            Db::table('webhook_deliveries'), Db::table('webhook_events'), Crm::ORDER_EVENT
        ) === false) {
            return false;
        }
        foreach (Settings::defaults() as $module => $config) {
            if (Db::query(
                'INSERT INTO %i (module_key, config_json, updated_at) VALUES (%s, %s, %s)
                 ON DUPLICATE KEY UPDATE module_key = module_key',
                Db::table('settings'), $module, wp_json_encode($config), Db::utc()
            ) === false) {
                return false;
            }
        }
        return true;
    }
}
