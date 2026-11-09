<?php
namespace Wpus;

defined('ABSPATH') || exit;

final class NetworkPolicy
{
    private static array $cache = [];

    private static function table(): string
    {
        global $wpdb;
        return $wpdb->base_prefix . 'wpus_network_policy';
    }

    public static function attribution(int $user): array
    {
        if (!self::canProcessAttribution()) {
            return [];
        }
        return Db::rows('SELECT network_id AS id, updated_at FROM %i WHERE network_id = %d AND updated_by = %d',
            self::table(), get_current_network_id(), $user);
    }

    public static function eraseAttribution(int $user): int|bool
    {
        if (!self::canProcessAttribution()) {
            return 0;
        }
        return Db::query('UPDATE %i SET updated_by = NULL WHERE network_id = %d AND updated_by = %d',
            self::table(), get_current_network_id(), $user);
    }

    private static function canProcessAttribution(): bool
    {
        return is_multisite() && is_main_site() && get_network_option(null, 'wpus_network_schema', false);
    }

    public static function provision(): bool
    {
        global $wpdb;
        $table = self::table();
        if (get_network_option(null, 'wpus_network_schema', false)) {
            return true;
        }
        $charset = $wpdb->get_charset_collate();
        if (Db::query(
            "CREATE TABLE IF NOT EXISTS %i (network_id bigint unsigned NOT NULL, revision bigint unsigned NOT NULL DEFAULT 1,
             policy_json text NOT NULL, updated_by bigint unsigned NULL, updated_at datetime NOT NULL,
             PRIMARY KEY (network_id)) ENGINE=InnoDB {$charset}", $table
        ) === false || strtolower(Db::row('SHOW TABLE STATUS WHERE Name = %s', $table)['Engine'] ?? '') !== 'innodb') {
            return false;
        }
        update_network_option(null, 'wpus_network_schema', true);
        return true;
    }

    public static function get(bool $fresh = false): array
    {
        global $wpdb;
        $id = get_current_network_id();
        if (!$fresh && isset(self::$cache[$id])) {
            return self::$cache[$id];
        }
        $schema = get_network_option($id, 'wpus_network_schema', false);
        $row = $schema ? Db::row('SELECT revision, policy_json FROM %i WHERE network_id = %d', self::table(), $id) : null;
        if (!$schema || $wpdb->last_error !== '') {
            return self::$cache[$id] = ['revision' => 0, 'disabled' => array_keys(Policy::MANAGE), 'outbound' => true, 'purge' => false];
        }
        $config = Db::decode($row['policy_json'] ?? null);
        return self::$cache[$id] = [
            'revision' => (int) ($row['revision'] ?? 0),
            'disabled' => array_values(array_intersect(array_keys(Policy::MANAGE), is_array($config['disabled'] ?? null) ? $config['disabled'] : [])),
            'outbound' => !empty($config['outbound']),
            'purge' => !empty($config['purge']),
        ];
    }

    public static function save(array $policy, int $revision): string
    {
        if (!is_multisite() || !current_user_can('manage_network_options')) {
            return 'denied';
        }
        $network = get_current_network_id();
        $main = get_main_site_id($network);
        $actor = get_current_user_id();
        if (!self::provision() || !$main) {
            return 'error';
        }
        $result = 'error';
        Lifecycle::onSite($main, static function () use ($network, $actor, $policy, $revision, &$result): void {
            if (!Lifecycle::provision()) {
                return;
            }
            $result = Db::transaction(static function () use ($network, $actor, $policy, $revision) {
                $json = wp_json_encode($policy);
                if ($revision === 0) {
                    $written = Db::query(
                        'INSERT INTO %i (network_id, policy_json, updated_by, updated_at) VALUES (%d, %s, %d, %s)
                         ON DUPLICATE KEY UPDATE revision = revision', self::table(), $network, $json, $actor, Db::utc()
                    );
                } else {
                    $written = Db::query(
                        'UPDATE %i SET revision = revision + 1, policy_json = %s, updated_by = %d, updated_at = %s
                         WHERE network_id = %d AND revision = %d', self::table(), $json, $actor, Db::utc(), $network, $revision
                    );
                }
                if ($written !== 1) {
                    return $written === 0 ? 'conflict' : false;
                }
                return Audit::record('network.policy_changed', 'network', null, null, [], $actor) ? 'saved' : false;
            }) ?: 'error';
        });
        unset(self::$cache[$network]);
        return $result;
    }

    public static function purge(): void
    {
        Db::query('DELETE FROM %i WHERE network_id = %d', self::table(), get_current_network_id());
        delete_network_option(null, 'wpus_network_schema');
    }
}
