<?php
namespace Wpus;

defined('ABSPATH') || exit;

final class Operations
{
    public static function scheduleExpiry(int $postId, string $local): string
    {
        if (!Policy::canManage('admin_tools') || !Policy::feature('admin_tools', 'expiry')
            || !Queue::available() || $postId < 1 || !Notes::access($postId)) {
            return 'denied';
        }
        if (!preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}\z/', $local)) {
            return 'invalid';
        }
        $post = get_post($postId);
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $local, wp_timezone());
        if (!$post || $post->post_status !== 'publish'
            || !in_array($post->post_type, Settings::get('admin_tools')['config']['expiry_types'], true)
            || !$date || $date->format('Y-m-d\TH:i') !== $local
            || $date->getTimestamp() <= time() || $date->getTimestamp() > time() + YEAR_IN_SECONDS) {
            return 'invalid';
        }
        return self::create('expiry', $postId, ['marker' => self::marker($post)], $date->getTimestamp(), DAY_IN_SECONDS);
    }

    public static function scheduleCrawl(): string
    {
        if (!Policy::canManage('monitoring') || !Policy::feature('monitoring', 'links')
            || !Policy::outbound() || !Queue::available() || !Outbound::available()
            || Outbound::origin(home_url('/')) === null) {
            return 'unavailable';
        }
        return self::create('crawl', 0, [], time(), HOUR_IN_SECONDS);
    }

    private static function create(string $type, int $object, array $args, int $when, int $ttl): string
    {
        return Db::locked('operations', static fn () => Db::transaction(static function () use ($type, $object, $args, $when, $ttl) {
            if (Db::value(
                "SELECT id FROM %i WHERE operation_type = %s AND object_id <=> NULLIF(%d, 0)
                 AND state = 'pending' AND expires_at > %s LIMIT 1",
                Db::table('operations'), $type, $object, Db::utc()
            )) {
                return 'conflict';
            }
            if (!Audit::reserve('op_' . gmdate('YmdH') . '_' . get_current_user_id(), 30)) {
                return 'limit';
            }
            $id = Db::insert('operations', [
                'public_id' => wp_generate_uuid4(), 'operation_type' => $type, 'actor_user_id' => get_current_user_id(),
                'object_id' => $object ?: null, 'args_json' => wp_json_encode($args), 'state' => 'pending',
                'run_at' => Db::utc($when), 'expires_at' => Db::utc($when + $ttl), 'created_at' => Db::utc(),
            ]);
            return $id && Audit::record('operation.queued', 'operation', $id) ? 'queued' : false;
        })) ?: 'error';
    }

    public static function recent(string $type): array
    {
        $rows = Db::rows(
            'SELECT id, public_id, object_id, run_at, state, result_code, revision FROM %i
             WHERE operation_type = %s ORDER BY id DESC LIMIT 30', Db::table('operations'), $type
        );
        return $type === 'expiry'
            ? array_values(array_filter($rows, static fn (array $row): bool => Notes::access((int) $row['object_id'])))
            : $rows;
    }

    public static function cancel(int $id, int $revision): string
    {
        if (!Policy::canManage('admin_tools')) {
            return 'denied';
        }
        return Db::locked('sweep', static fn () => Db::transaction(static function () use ($id, $revision) {
            $row = Db::row("SELECT object_id FROM %i WHERE id = %d AND operation_type = 'expiry'", Db::table('operations'), $id);
            if (!$row || !Notes::access((int) $row['object_id'])) {
                return 'denied';
            }
            $changed = Db::query(
                "UPDATE %i SET state = 'cancelled', revision = revision + 1, args_json = '{}', expires_at = %s
                 WHERE id = %d AND revision = %d AND state = 'pending'",
                Db::table('operations'), Db::utc(time() + WEEK_IN_SECONDS), $id, $revision
            );
            if ($changed !== 1) {
                return $changed === 0 ? 'conflict' : false;
            }
            return Audit::record('operation.cancelled', 'operation', $id) ? 'saved' : false;
        })) ?: 'busy';
    }

    public static function runDue(): void
    {
        $table = Db::table('operations');
        $now = Db::utc();
        Db::query(
            "UPDATE %i SET state = 'skipped', result_code = 'expired', revision = revision + 1,
             args_json = '{}', expires_at = %s WHERE state = 'pending' AND expires_at <= %s LIMIT 100",
            $table, Db::utc(time() + WEEK_IN_SECONDS), $now
        );
        foreach (Db::rows(
            "SELECT * FROM %i WHERE operation_type = 'expiry' AND state = 'pending' AND run_at <= %s
             AND expires_at > %s ORDER BY run_at, id LIMIT 5", $table, $now, $now
        ) as $row) {
            $code = self::expire($row);
            self::finish((int) $row['id'], $code === 'drafted' ? 'completed' : 'skipped', $code);
        }
        $crawl = Db::row(
            "SELECT * FROM %i WHERE operation_type = 'crawl' AND state = 'pending' AND run_at <= %s
             AND expires_at > %s ORDER BY run_at, id LIMIT 1", $table, $now, $now
        );
        if ($crawl) {
            self::crawl($crawl);
        }
    }

    private static function finish(int $id, string $state, string $result): void
    {
        Db::query(
            "UPDATE %i SET state = %s, result_code = %s, revision = revision + 1,
             args_json = '{}', expires_at = %s WHERE id = %d AND state = 'pending'",
            Db::table('operations'), $state, $result, Db::utc(time() + WEEK_IN_SECONDS), $id
        );
    }

    private static function marker(\WP_Post $post): string
    {
        return hash('sha256', wp_json_encode($post->to_array()));
    }

    private static function expire(array $row): string
    {
        if (!Policy::feature('admin_tools', 'expiry', true)) {
            return 'feature_disabled';
        }
        $id = (int) $row['object_id'];
        clean_post_cache($id);
        $post = get_post($id);
        $actor = get_userdata((int) $row['actor_user_id']);
        if (!$post || !$actor || (is_multisite() && !is_user_member_of_blog($actor->ID, get_current_blog_id()))
            || !user_can($actor, 'wpus_manage_notes') || !user_can($actor, 'edit_post', $id)) {
            return 'access_changed';
        }
        $args = Db::decode($row['args_json']);
        if ($post->post_status !== 'publish' || ($args['marker'] ?? '') !== self::marker($post)
            || !in_array($post->post_type, Settings::get('admin_tools')['config']['expiry_types'], true)) {
            return 'post_changed';
        }
        $previous = get_current_user_id();
        wp_set_current_user($actor->ID);
        try {
            $result = wp_update_post(['ID' => $id, 'post_status' => 'draft'], true);
            if (is_wp_error($result) || !$result || get_post_status($id) !== 'draft') {
                return 'update_failed';
            }
            Audit::record('content.expired', 'post', $id);
            return 'drafted';
        } finally {
            wp_set_current_user($previous);
        }
    }

    private static function crawl(array $row): void
    {
        $id = (int) $row['id'];
        $actor = get_userdata((int) $row['actor_user_id']);
        $origin = Outbound::origin(home_url('/'));
        if (!$actor || !user_can($actor, 'wpus_manage_monitoring')
            || (is_multisite() && !is_user_member_of_blog($actor->ID, get_current_blog_id()))
            || !Policy::feature('monitoring', 'links', true) || !Policy::outbound() || !$origin) {
            self::finish($id, 'skipped', 'policy_changed');
            return;
        }
        $args = Db::decode($row['args_json']);
        if (!$args) {
            $args = [
                'origin' => $origin, 'paths' => Monitoring::discoverLinks($origin),
                'counts' => ['ok' => 0, 'redirect' => 0, 'broken' => 0, 'other' => 0, 'unreachable' => 0],
            ];
        }
        if ($args['origin'] !== $origin) {
            self::finish($id, 'skipped', 'origin_changed');
            return;
        }
        $canConnect = static fn (): bool => Policy::feature('monitoring', 'links', true);
        foreach (array_splice($args['paths'], 0, 3) as $path) {
            if (Monitoring::path($path) === null) {
                continue;
            }
            $result = Outbound::request('HEAD', $origin . $path, $origin, [], '', 2, $canConnect);
            if ($result['code'] === 'http' && $result['status'] === 405) {
                $result = Outbound::request('GET', $origin . $path, $origin, [], '', 2, $canConnect);
            }
            if ($result['code'] === 'policy_paused') {
                self::finish($id, 'skipped', 'policy_changed');
                return;
            }
            $status = $result['status'];
            $bucket = match (true) {
                $result['code'] !== 'http' => 'unreachable',
                $status >= 200 && $status < 300 => 'ok',
                $status >= 300 && $status < 400 => 'redirect',
                in_array($status, [404, 410], true) => 'broken',
                default => 'other',
            };
            $args['counts'][$bucket]++;
        }
        if ($args['paths']) {
            Db::query('UPDATE %i SET args_json = %s WHERE id = %d', Db::table('operations'), wp_json_encode($args), $id);
        } else {
            Db::transaction(static function () use ($args, $id) {
                if (!Db::insert('monitoring_events', [
                    'check_key' => 'links', 'details_json' => wp_json_encode($args['counts']), 'observed_at' => Db::utc(),
                ])) {
                    return false;
                }
                self::finish($id, 'completed', 'reported');
                return true;
            });
        }
    }
}
