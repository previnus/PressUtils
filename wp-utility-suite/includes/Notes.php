<?php
namespace Wpus;

defined('ABSPATH') || exit;

final class Notes
{
    public static function access(int $postId): bool
    {
        if (!$postId) {
            return true;
        }
        $post = get_post($postId);
        return $post && in_array($post->post_type, ['post', 'page'], true) && current_user_can('edit_post', $postId);
    }

    public static function list(int $before = PHP_INT_MAX): array
    {
        if (!current_user_can('wpus_view_notes') || Policy::state('admin_tools') !== 'active') {
            return ['items' => [], 'next' => 0];
        }
        $rows = Db::rows('SELECT * FROM %i WHERE id < %d ORDER BY id DESC LIMIT 50', Db::table('admin_notes'), $before);
        $next = count($rows) === 50 ? (int) end($rows)['id'] : 0;
        return [
            'items' => array_values(array_filter($rows, static fn (array $row): bool => self::access((int) $row['post_id']))),
            'next' => $next,
        ];
    }

    public static function save(string $title, string $body, int $post, int $id, int $revision): string
    {
        if (!Policy::canManage('admin_tools') || Policy::state('admin_tools') !== 'active' || !self::access($post)) {
            return 'denied';
        }
        if ($title === '' || $body === '' || strlen($title) > 160 || strlen($body) > 16384) {
            return 'invalid';
        }
        return Db::transaction(static function () use ($title, $body, $post, $id, $revision) {
            if (!$id) {
                $id = Db::insert('admin_notes', [
                    'post_id' => $post ?: null, 'author_user_id' => get_current_user_id(),
                    'title' => $title, 'body_text' => $body, 'created_at' => Db::utc(), 'updated_at' => Db::utc(),
                ]);
            } else {
                $old = Db::row('SELECT post_id FROM %i WHERE id = %d FOR UPDATE', Db::table('admin_notes'), $id);
                if (!$old || !self::access((int) $old['post_id'])) {
                    return 'denied';
                }
                $changed = Db::query(
                    'UPDATE %i SET post_id = NULLIF(%d, 0), title = %s, body_text = %s,
                     revision = revision + 1, updated_at = %s WHERE id = %d AND revision = %d',
                    Db::table('admin_notes'), $post, $title, $body, Db::utc(), $id, $revision
                );
                if ($changed !== 1) {
                    return $changed === 0 ? 'conflict' : false;
                }
            }
            return $id && Audit::record('note.saved', 'note', $id) ? 'saved' : false;
        }) ?: 'error';
    }

    public static function remove(int $id, int $revision): string
    {
        if (!Policy::canManage('admin_tools') || Policy::state('admin_tools') !== 'active') {
            return 'denied';
        }
        return Db::transaction(static function () use ($id, $revision) {
            $old = Db::row('SELECT post_id FROM %i WHERE id = %d FOR UPDATE', Db::table('admin_notes'), $id);
            if (!$old || !self::access((int) $old['post_id'])) {
                return 'denied';
            }
            $changed = Db::query('DELETE FROM %i WHERE id = %d AND revision = %d', Db::table('admin_notes'), $id, $revision);
            if ($changed !== 1) {
                return $changed === 0 ? 'conflict' : false;
            }
            return Audit::record('note.deleted', 'note', $id) ? 'saved' : false;
        }) ?: 'error';
    }
}
