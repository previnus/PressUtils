<?php
namespace Wpus;

defined('ABSPATH') || exit;

final class Queue
{
    public const HOOK = 'wpus_sweep_v1';
    public const GROUP = 'wpus-maintenance';

    public static function hooks(): void
    {
        add_action('action_scheduler_init', [self::class, 'ensure']);
        add_action('action_scheduler_ensure_recurring_actions', [self::class, 'ensure']);
        add_action(self::HOOK, [self::class, 'sweep']);
        // This shared runner can forward the current admin session in its loopback.
        add_filter('as_async_request_queue_runner_post_args', static function (array $args): array {
            $args['sslverify'] = true;
            $args['redirection'] = 0;
            return $args;
        }, PHP_INT_MAX);
        add_filter('action_scheduler_allow_async_request_runner', static function ($allowed): bool {
            return $allowed && (!is_user_logged_in() || wp_parse_url(admin_url(), PHP_URL_SCHEME) === 'https');
        }, PHP_INT_MAX);
    }

    public static function available(): bool
    {
        return did_action('action_scheduler_init') && function_exists('as_has_scheduled_action');
    }

    public static function ensure(): void
    {
        if (Lifecycle::ready() && self::available() && !as_has_scheduled_action(self::HOOK, [], self::GROUP)) {
            as_schedule_recurring_action(time() + 30, 60, self::HOOK, [], self::GROUP, true);
        }
    }

    public static function sweep(): void
    {
        if (!Lifecycle::ready()) {
            return;
        }
        Db::locked('sweep', static function (): bool {
            Operations::runDue();
            $last = Db::row(
                'SELECT observed_at FROM %i WHERE check_key = %s ORDER BY observed_at DESC LIMIT 1',
                Db::table('monitoring_events'), 'maintenance'
            );
            if (!$last || strcmp($last['observed_at'], Db::utc(time() - 600)) < 0) {
                Performance::prune();
                Db::insert('monitoring_events', [
                    'check_key' => 'maintenance', 'details_json' => '{}', 'observed_at' => Db::utc(),
                ]);
            }
            return true;
        });
        Db::locked('delivery', [Crm::class, 'sweep']);
    }

    public static function clear(bool $purge = false): void
    {
        global $wpdb;
        if (!$purge && self::available() && (!is_multisite() || !ms_is_switched())) {
            as_unschedule_all_actions(self::HOOK, [], self::GROUP);
        }
        // Use the site prefix directly during network lifecycle work, where the scheduler may belong to another site.
        $actions = $wpdb->prefix . 'actionscheduler_actions';
        $groups = $wpdb->prefix . 'actionscheduler_groups';
        if (!Db::row('SHOW TABLE STATUS WHERE Name = %s', $actions)
            || !Db::row('SHOW TABLE STATUS WHERE Name = %s', $groups)) {
            return;
        }
        if (!$purge) {
            Db::query(
                'UPDATE %i a JOIN %i g ON a.group_id = g.group_id SET a.status = %s
                 WHERE a.hook = %s AND g.slug = %s AND a.status = %s',
                $actions, $groups, 'canceled', self::HOOK, self::GROUP, 'pending'
            );
            return;
        }
        $logs = $wpdb->prefix . 'actionscheduler_logs';
        if (Db::row('SHOW TABLE STATUS WHERE Name = %s', $logs)) {
            Db::query(
                'DELETE l FROM %i l JOIN %i a ON a.action_id = l.action_id JOIN %i g ON a.group_id = g.group_id
                 WHERE a.hook = %s AND g.slug = %s',
                $logs, $actions, $groups, self::HOOK, self::GROUP
            );
        }
        Db::query(
            'DELETE a FROM %i a JOIN %i g ON a.group_id = g.group_id WHERE a.hook = %s AND g.slug = %s',
            $actions, $groups, self::HOOK, self::GROUP
        );
    }
}
