<?php
namespace Wpus;

defined('ABSPATH') || exit;

final class Privacy
{
    public static function hooks(): void
    {
        add_filter('wp_privacy_personal_data_exporters', static function (array $callbacks): array {
            $callbacks['wp-utility-suite'] = ['exporter_friendly_name' => 'WP Utility Suite', 'callback' => [self::class, 'export']];
            return $callbacks;
        });
        add_filter('wp_privacy_personal_data_erasers', static function (array $callbacks): array {
            $callbacks['wp-utility-suite'] = ['eraser_friendly_name' => 'WP Utility Suite', 'callback' => [self::class, 'erase']];
            return $callbacks;
        });
        add_action('admin_init', static function (): void {
            wp_add_privacy_policy_content('WP Utility Suite', '<p>' . esc_html__(
                'WP Utility Suite keeps site-local audit metadata such as account identifiers and roles, and can store technical notes and scheduled operations. Optional 404 monitoring stores daily counts and keyed path hashes. When enabled, configured webhook receivers may receive content, product or order identifiers and status codes.',
                'wp-utility-suite'
            ) . '</p>');
        });
    }

    public static function export(string $email, int $page = 1): array
    {
        $user = get_user_by('email', $email);
        if (!$user || !Lifecycle::ready()) {
            return ['data' => [], 'done' => true];
        }
        $data = [];
        $done = true;
        $offset = (max(1, $page) - 1) * 100;
        $sources = [
            'audit_logs' => Db::rows(
                'SELECT id, event_type, occurred_at FROM %i WHERE actor_user_id = %d OR subject_user_id = %d ORDER BY id LIMIT 100 OFFSET %d',
                Db::table('audit_logs'), $user->ID, $user->ID, $offset
            ),
            'admin_notes' => Db::rows(
                'SELECT id, title, body_text, created_at FROM %i WHERE author_user_id = %d ORDER BY id LIMIT 100 OFFSET %d',
                Db::table('admin_notes'), $user->ID, $offset
            ),
            'operations' => Db::rows(
                'SELECT id, operation_type, object_id, state, created_at FROM %i WHERE actor_user_id = %d ORDER BY id LIMIT 100 OFFSET %d',
                Db::table('operations'), $user->ID, $offset
            ),
            'settings' => Db::rows(
                'SELECT module_key, updated_at FROM %i WHERE updated_by = %d ORDER BY module_key LIMIT 100 OFFSET %d',
                Db::table('settings'), $user->ID, $offset
            ),
            'network_policy' => $page <= 1 ? NetworkPolicy::attribution($user->ID) : [],
        ];
        foreach ($sources as $name => $rows) {
            $done = $done && count($rows) < 100;
            foreach ($rows as $row) {
                $id = $row['id'] ?? $row['module_key'];
                unset($row['id']);
                $data[] = [
                    'group_id' => 'wpus-' . $name, 'group_label' => 'WP Utility Suite — ' . str_replace('_', ' ', $name),
                    'item_id' => 'wpus-' . $name . '-' . $id,
                    'data' => array_map(
                        static fn ($key, $value): array => ['name' => str_replace('_', ' ', $key), 'value' => esc_html((string) $value)],
                        array_keys($row), array_values($row)
                    ),
                ];
            }
        }
        return ['data' => $data, 'done' => $done];
    }

    public static function erase(string $email): array
    {
        $user = get_user_by('email', $email);
        $result = ['items_removed' => false, 'items_retained' => false, 'messages' => [], 'done' => true];
        if (!$user || !Lifecycle::ready()) {
            return $result;
        }
        $id = $user->ID;
        $writes = Db::locked('sweep', static fn () => Db::transaction(static function () use ($id) {
            $changes = [
                Db::query(
                    "UPDATE %i SET actor_user_id = IF(actor_user_id = %d, NULL, actor_user_id),
                     subject_user_id = IF(subject_user_id = %d, NULL, subject_user_id)
                     WHERE actor_user_id = %d OR subject_user_id = %d LIMIT 100",
                    Db::table('audit_logs'), $id, $id, $id, $id
                ),
                Db::query("UPDATE %i SET author_user_id = NULL, revision = revision + 1 WHERE author_user_id = %d LIMIT 100", Db::table('admin_notes'), $id),
                Db::query(
                    "UPDATE %i SET actor_user_id = NULL, state = IF(state = 'pending', 'cancelled', state),
                     args_json = '{}', revision = revision + 1 WHERE actor_user_id = %d LIMIT 100",
                    Db::table('operations'), $id
                ),
                Db::query('UPDATE %i SET updated_by = NULL WHERE updated_by = %d LIMIT 100', Db::table('settings'), $id),
                NetworkPolicy::eraseAttribution($id),
            ];
            if (in_array(false, $changes, true)
                || (array_sum($changes) > 0 && !Audit::record('privacy.redacted', null, null, null, [], 0))) {
                return false;
            }
            return $changes;
        }));
        if ($writes === false) {
            $result['items_retained'] = true;
            $result['messages'][] = __('WP Utility Suite records are busy or unavailable. Retry erasure.', 'wp-utility-suite');
        } else {
            $result['items_removed'] = array_sum($writes) > 0;
            $result['done'] = !in_array(100, $writes, true);
            if ($writes[1] > 0) {
                $result['items_retained'] = true;
                $result['messages'][] = __('Technical note text was retained. Review it separately if it contains personal information.', 'wp-utility-suite');
            }
        }
        return $result;
    }
}
