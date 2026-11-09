<?php
namespace Wpus;

defined('ABSPATH') || exit;

final class Audit
{
    public static function record(
        string $event,
        ?string $kind = null,
        ?int $object = null,
        ?int $subject = null,
        array $details = [],
        ?int $actor = null
    ): bool {
        $details = array_intersect_key($details, array_flip(['module', 'plugin', 'role', 'result']));
        $json = wp_json_encode($details);
        if ($json === false || strlen($json) > 2048) {
            return false;
        }
        return Db::insert('audit_logs', [
            'event_type' => $event,
            'actor_user_id' => ($actor ?? get_current_user_id()) ?: null,
            'subject_user_id' => $subject ?: null,
            'object_kind' => $kind,
            'object_id' => $object ?: null,
            'details_json' => $json,
            'occurred_at' => Db::utc(),
        ]) > 0;
    }

    public static function hooks(): void
    {
        add_action('wp_login', [self::class, 'login'], 10, 2);
        add_action('set_user_role', static function (int $user, string $role): void {
            self::role('user.role_set', $user, $role);
        }, 10, 2);
        add_action('add_user_role', static function (int $user, string $role): void {
            self::role('user.role_added', $user, $role);
        }, 10, 2);
        add_action('remove_user_role', static function (int $user, string $role): void {
            self::role('user.role_removed', $user, $role);
        }, 10, 2);
        add_action('activated_plugin', static fn (string $plugin) => self::plugin('plugin.activated', $plugin));
        add_action('deactivated_plugin', static fn (string $plugin) => self::plugin('plugin.deactivated', $plugin));
        add_action('deleted_plugin', static function (string $plugin, bool $deleted): void {
            if ($deleted) {
                self::plugin('plugin.deleted', $plugin);
            }
        }, 10, 2);
    }

    public static function login(string $ignored, \WP_User $user): void
    {
        if (!Policy::feature('security', 'logins') || !user_can($user, 'manage_options')) {
            return;
        }
        self::observe('login.privileged', 'user', null, $user->ID, [], $user->ID);
        if (Policy::feature('security', 'login_alert') && Policy::outbound()) {
            $recipient = get_option('admin_email');
            if (is_email($recipient) && self::reserve('login_alert_' . gmdate('YmdH'), 5)) {
                wp_mail(
                    $recipient,
                    __('Privileged WordPress login', 'wp-utility-suite'),
                    sprintf(__('A privileged login occurred for user ID %1$d at %2$s UTC.', 'wp-utility-suite'), $user->ID, Db::utc())
                );
            }
        }
    }

    private static function role(string $event, int $user, string $role): void
    {
        if (Policy::feature('security', 'roles')) {
            self::observe($event, 'user', null, $user, ['role' => substr(sanitize_key($role), 0, 80)]);
        }
    }

    private static function plugin(string $event, string $plugin): void
    {
        if (Policy::feature('security', 'plugins')) {
            self::observe($event, 'plugin', null, null, ['plugin' => substr(sanitize_text_field($plugin), 0, 255)]);
        }
    }

    private static function observe(string $event, string $kind, ?int $object, ?int $subject, array $details, ?int $actor = null): void
    {
        $category = strstr($event, '.', true);
        if (self::reserve('audit_' . gmdate('Ymd') . '_' . $category, 5000)) {
            self::record($event, $kind, $object, $subject, $details, $actor);
        }
    }

    public static function reserve(string $key, int $limit): bool
    {
        $table = Db::table('counters');
        if (Db::query('INSERT IGNORE INTO %i (counter_key, counter_value) VALUES (%s, 0)', $table, $key) === false) {
            return false;
        }
        return Db::query(
            'UPDATE %i SET counter_value = counter_value + 1 WHERE counter_key = %s AND counter_value < %d',
            $table, $key, $limit
        ) === 1;
    }

    public static function search(string $from, string $until, string $event, int $actor, int $limit, int $before = 0): array
    {
        $where = 'WHERE occurred_at >= %s AND occurred_at < %s';
        $args = [Db::table('audit_logs'), $from, $until];
        if ($event !== '') {
            $where .= ' AND event_type = %s';
            $args[] = $event;
        }
        if ($actor) {
            $where .= ' AND actor_user_id = %d';
            $args[] = $actor;
        }
        if ($before) {
            $where .= ' AND id < %d';
            $args[] = $before;
        }
        $args[] = $limit;
        return Db::rows("SELECT * FROM %i {$where} ORDER BY id DESC LIMIT %d", ...$args);
    }
}
