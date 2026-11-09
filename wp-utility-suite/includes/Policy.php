<?php
namespace Wpus;

defined('ABSPATH') || exit;

final class Policy
{
    public const MANAGE = [
        'security' => 'wpus_manage_security',
        'performance' => 'wpus_manage_performance',
        'crm' => 'wpus_manage_integrations',
        'admin_tools' => 'wpus_manage_notes',
        'woocommerce' => 'wpus_manage_commerce',
        'monitoring' => 'wpus_manage_monitoring',
    ];

    public const ADDITIONAL = [
        'wpus_manage_modules', 'wpus_view_audit', 'wpus_export_audit', 'wpus_view_notes',
        'wpus_read_health', 'wpus_rotate_secrets', 'wpus_view_deliveries',
    ];

    public static function canManage(string $module): bool
    {
        return isset(self::MANAGE[$module]) && current_user_can(self::MANAGE[$module])
            && ($module !== 'woocommerce' || current_user_can('manage_woocommerce'));
    }

    public static function canView(string $module): bool
    {
        return self::canManage($module)
            || match ($module) {
                'security' => current_user_can('wpus_view_audit') || current_user_can('wpus_export_audit'),
                'admin_tools' => current_user_can('wpus_view_notes'),
                'crm' => current_user_can('wpus_view_deliveries'),
                'woocommerce' => current_user_can('wpus_manage_commerce'),
                'monitoring' => current_user_can('wpus_read_health'),
                default => false,
            };
    }

    public static function commerceReady(): bool
    {
        return defined('WC_VERSION') && version_compare(WC_VERSION, '11.1', '>=')
            && function_exists('wc_get_order');
    }

    public static function state(string $module, bool $fresh = false): string
    {
        if (!Lifecycle::ready()) {
            return 'setup';
        }
        if ((defined('WPUS_DISABLED_MODULES') && is_array(WPUS_DISABLED_MODULES)
                && in_array($module, WPUS_DISABLED_MODULES, true))
            || (is_multisite() && in_array($module, NetworkPolicy::get($fresh)['disabled'], true))) {
            return 'paused';
        }
        if (!Settings::get($module, $fresh)['config']['enabled']) {
            return 'off';
        }
        if (($module === 'woocommerce' && !self::commerceReady())
            || ($module === 'crm' && !Crm::available())) {
            return 'unavailable';
        }
        return 'active';
    }

    public static function feature(string $module, string $feature, bool $fresh = false): bool
    {
        return self::state($module, $fresh) === 'active'
            && !empty(Settings::get($module)['config'][$feature]);
    }

    public static function outbound(bool $fresh = false): bool
    {
        return !(defined('WPUS_STOP_OUTBOUND') && WPUS_STOP_OUTBOUND)
            && !(is_multisite() && NetworkPolicy::get($fresh)['outbound'])
            && self::sitePaused($fresh) === false;
    }

    public static function sitePaused(bool $fresh = false): ?bool
    {
        if (!$fresh) {
            return (bool) get_option('wpus_outbound_paused', false);
        }
        global $wpdb;
        $value = Db::value('SELECT option_value FROM %i WHERE option_name = %s', $wpdb->options, 'wpus_outbound_paused');
        return $wpdb->last_error === '' ? (bool) $value : null;
    }
}
