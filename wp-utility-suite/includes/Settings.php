<?php
namespace Wpus;

defined('ABSPATH') || exit;

final class Settings
{
    private static array $cache = [];

    public static function defaults(): array
    {
        return [
            'security' => ['enabled' => true, 'logins' => true, 'roles' => true, 'plugins' => true, 'retention' => 90, 'login_alert' => false],
            'performance' => ['enabled' => true, 'slow_http' => false],
            'crm' => ['enabled' => true, 'capture' => false],
            'admin_tools' => ['enabled' => true, 'dashboard' => true, 'expiry' => false, 'expiry_types' => ['post', 'page']],
            'woocommerce' => ['enabled' => true, 'orders' => false, 'stock' => false, 'free_shipping' => false],
            'monitoring' => ['enabled' => true, 'record_404' => false, 'redirects' => false, 'links' => false],
        ];
    }

    public static function get(string $module, bool $fresh = false): array
    {
        $site = get_current_blog_id();
        if (!$fresh && isset(self::$cache[$site][$module])) {
            return self::$cache[$site][$module];
        }
        if (!$fresh && empty(self::$cache[$site])) {
            foreach (Db::rows('SELECT module_key, revision, config_json FROM %i', Db::table('settings')) as $row) {
                self::cache($site, $row['module_key'], $row);
            }
            if (isset(self::$cache[$site][$module])) {
                return self::$cache[$site][$module];
            }
        }
        $row = Db::row('SELECT revision, config_json FROM %i WHERE module_key = %s', Db::table('settings'), $module);
        return self::cache($site, $module, $row);
    }

    private static function cache(int $site, string $module, ?array $row): array
    {
        return self::$cache[$site][$module] = [
            'revision' => (int) ($row['revision'] ?? 0),
            'config' => array_replace(self::defaults()[$module] ?? [], Db::decode($row['config_json'] ?? null)),
        ];
    }

    public static function save(string $module, array $patch, int $expected): string
    {
        if (!Lifecycle::ready() || !Policy::canManage($module)) {
            return 'denied';
        }
        $previous = self::get($module, true);
        if ($expected < 1 || $previous['revision'] !== $expected) {
            return 'conflict';
        }
        $defaults = self::defaults()[$module];
        $config = $previous['config'];
        foreach ($patch as $key => $value) {
            if (!array_key_exists($key, $defaults) || gettype($defaults[$key]) !== gettype($value)) {
                return 'invalid';
            }
            if (is_bool($value) && $value !== $config[$key] && !current_user_can('wpus_manage_modules')) {
                return 'denied';
            }
            if ($key === 'retention' && ($value < 1 || $value > 90)) {
                return 'invalid';
            }
            if ($key === 'expiry_types') {
                if (!array_is_list($value) || count($value) > 2) {
                    return 'invalid';
                }
                foreach ($value as $type) {
                    if (!in_array($type, ['post', 'page'], true)) {
                        return 'invalid';
                    }
                }
                $value = array_values(array_unique($value));
            }
            $config[$key] = $value;
        }
        if ($config === $previous['config']) {
            return 'saved';
        }
        $result = Db::transaction(static function () use ($module, $config, $expected) {
            $changed = Db::query(
                'UPDATE %i SET config_json = %s, revision = revision + 1, updated_by = %d, updated_at = %s
                 WHERE module_key = %s AND revision = %d',
                Db::table('settings'), wp_json_encode($config), get_current_user_id(), Db::utc(), $module, $expected
            );
            if ($changed === 0) {
                return 'conflict';
            }
            return $changed === 1 && Audit::record('settings.changed', 'module', null, null, ['module' => $module])
                ? 'saved' : false;
        });
        unset(self::$cache[get_current_blog_id()]);
        return $result ?: 'error';
    }
}
