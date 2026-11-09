<?php
namespace Wpus;

defined('ABSPATH') || exit;

final class Lifecycle
{
    public static function ready(): bool
    {
        return (int) get_option('wpus_schema_version', 0) === Schema::VERSION;
    }

    public static function provision(): bool
    {
        if (self::ready()) {
            return true;
        }
        return (bool) Db::locked('schema', static function (): bool {
            if (self::ready()) {
                return true;
            }
            if (!Schema::install() || (is_multisite() && !NetworkPolicy::provision())) {
                return false;
            }
            self::grant();
            add_option('wpus_site_uuid', wp_generate_uuid4(), '', false);
            update_option('wpus_schema_version', Schema::VERSION, false);
            return self::ready();
        });
    }

    public static function activate(bool $networkWide): void
    {
        if (!self::provision()) {
            wp_die(esc_html__('WP Utility Suite could not initialize its InnoDB tables. Check database permissions and retry activation.', 'wp-utility-suite'));
        }
        if ($networkWide && !wp_next_scheduled('wpus_network_provision', [0])) {
            wp_schedule_single_event(time() + 15, 'wpus_network_provision', [0]);
        }
    }

    public static function provisionNetwork(int $offset): void
    {
        if (!is_multisite() || !self::networkActive(get_current_network_id())) {
            return;
        }
        $sites = self::sites($offset);
        foreach ($sites as $site) {
            self::onSite((int) $site, [self::class, 'provision']);
        }
        if (count($sites) === 20) {
            wp_schedule_single_event(time() + 15, 'wpus_network_provision', [$offset + 20]);
        }
    }

    public static function newSite(\WP_Site $site): void
    {
        if (self::networkActive((int) $site->network_id)) {
            self::onSite((int) $site->blog_id, [self::class, 'provision']);
        }
    }

    private static function networkActive(int $network): bool
    {
        return isset(get_network_option($network, 'active_sitewide_plugins', [])[plugin_basename(WPUS_FILE)]);
    }

    public static function deactivate(bool $networkWide): void
    {
        self::stop();
        if ($networkWide) {
            for ($offset = 0; $sites = self::sites($offset); $offset += 20) {
                foreach ($sites as $site) {
                    if ((int) $site !== get_current_blog_id()) {
                        self::onSite((int) $site, [self::class, 'stop']);
                    }
                }
            }
        }
    }

    private static function stop(): void
    {
        if (self::ready()) {
            update_option('wpus_outbound_paused', true, false);
        }
        wp_unschedule_hook('wpus_network_provision');
        Queue::clear();
    }

    public static function dropSiteTables(array $tables, int $siteId): array
    {
        global $wpdb;
        $prefix = $wpdb->get_blog_prefix($siteId);
        foreach (Db::TABLES as $table) {
            $tables[] = $prefix . 'wpus_' . $table;
        }
        return $tables;
    }

    public static function purge(): void
    {
        foreach (Db::TABLES as $table) {
            Db::query('DROP TABLE IF EXISTS %i', Db::table($table));
        }
        Queue::clear(true);
        wp_unschedule_hook('wpus_network_provision');
        foreach (['schema_version', 'site_uuid', 'purge', 'outbound_paused', 'queue_ready', 'capture_dropped'] as $option) {
            delete_option('wpus_' . $option);
        }
        $roles = wp_roles();
        foreach (array_merge(array_values(Policy::MANAGE), Policy::ADDITIONAL) as $capability) {
            foreach ($roles->role_objects as $role) {
                if ($role->has_cap($capability)) {
                    $role->remove_cap($capability);
                }
            }
        }
    }

    public static function grant(): void
    {
        if ($admin = get_role('administrator')) {
            foreach (array_merge(array_values(Policy::MANAGE), Policy::ADDITIONAL) as $capability) {
                if (!$admin->has_cap($capability)) {
                    $admin->add_cap($capability);
                }
            }
        }
    }

    public static function onSite(int $siteId, callable $run): void
    {
        switch_to_blog($siteId);
        try {
            $run();
        } finally {
            restore_current_blog();
        }
    }

    public static function sites(int $offset): array
    {
        return get_sites([
            'network_id' => get_current_network_id(),
            'fields' => 'ids',
            'number' => 20,
            'offset' => $offset,
            'orderby' => 'id',
            'order' => 'ASC',
        ]);
    }
}
