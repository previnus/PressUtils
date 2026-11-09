<?php
namespace Wpus;

defined('ABSPATH') || exit;

final class Monitoring
{
    public static function path(string $path): ?string
    {
        if (strlen($path) > 1024 || !preg_match('#\A/(?:[A-Za-z0-9._~/-]*)\z#D', $path)
            || str_contains($path, '//') || preg_match('#(?:^|/)\.{1,2}(?:/|$)#D', $path)) {
            return null;
        }
        $base = rtrim((string) wp_parse_url(home_url('/'), PHP_URL_PATH), '/');
        if ($base !== '' && $path !== $base && !str_starts_with($path, $base . '/')) {
            return null;
        }
        $relative = substr($path, strlen($base));
        $rest = '/' . trim(rest_get_url_prefix(), '/');
        if (preg_match('#(?:^|/)(?:wp-admin|wp-login\.php|wp-json|wp-content|wp-includes|feed|cart|checkout|my-account)(?:/|$)#iD', $relative)
            || preg_match('#\.php(?:/|$)#iD', $relative)
            || ($rest !== '/' && (rtrim($relative, '/') === $rest || str_starts_with($relative, $rest . '/')))) {
            return null;
        }
        if (function_exists('wc_get_page_id')) {
            foreach (['cart', 'checkout', 'myaccount'] as $page) {
                $id = wc_get_page_id($page);
                $slug = $id > 0 ? rtrim((string) wp_parse_url(get_permalink($id), PHP_URL_PATH), '/') : '';
                if ($slug !== '' && (rtrim($path, '/') === $slug || str_starts_with($path, $slug . '/'))) {
                    return null;
                }
            }
        }
        return $path;
    }

    public static function conflict(): bool
    {
        return defined('WPSEO_PREMIUM_VERSION') || class_exists('RankMath\Redirections\Redirections')
            || (bool) apply_filters('wpus_redirect_conflict', false);
    }

    public static function frontend(): void
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? '';
        if (!is_404() || is_admin() || is_feed() || wp_is_serving_rest_request()
            || !in_array($method, ['GET', 'HEAD'], true)) {
            return;
        }
        $request = $_SERVER['REQUEST_URI'] ?? '';
        if (!is_string($request) || strlen($request) > 4096) {
            return;
        }
        $path = self::path(explode('?', wp_unslash($request), 2)[0]);
        if ($path === null) {
            return;
        }
        if (Policy::feature('monitoring', 'redirects') && !self::conflict()) {
            $rule = Db::row('SELECT source_path, target_path, status_code FROM %i WHERE source_hash = %s', Db::table('redirects'), hash('sha256', $path));
            if ($rule && $rule['source_path'] === $path && self::path($rule['target_path']) !== null) {
                $home = wp_parse_url(home_url('/'));
                $target = $home['scheme'] . '://' . $home['host'] . (isset($home['port']) ? ':' . $home['port'] : '') . $rule['target_path'];
                if (wp_safe_redirect($target, (int) $rule['status_code'], 'WP Utility Suite')) {
                    exit;
                }
            }
        }
        if (Policy::feature('monitoring', 'record_404')) {
            self::hit($path);
        }
    }

    private static function hit(string $path): void
    {
        $day = gmdate('Y-m-d');
        if (!Audit::reserve('404_hits_' . gmdate('Ymd'), 50000)) {
            return;
        }
        $table = Db::table('404_events');
        $digest = hash_hmac('sha256', get_current_blog_id() . '|' . $path, wp_salt('auth'));
        if (Db::query(
            'UPDATE %i SET hit_count = hit_count + 1, last_seen_at = %s WHERE day_utc = %s AND path_digest = %s',
            $table, Db::utc(), $day, $digest
        ) === 1) {
            return;
        }
        if (!Audit::reserve('404_paths_' . gmdate('Ymd'), 2000)) {
            $digest = str_repeat('0', 64);
        }
        Db::query(
            'INSERT INTO %i (day_utc, path_digest, last_seen_at) VALUES (%s, %s, %s)
             ON DUPLICATE KEY UPDATE hit_count = hit_count + 1, last_seen_at = VALUES(last_seen_at)',
            $table, $day, $digest, Db::utc()
        );
    }

    public static function rules(): array
    {
        return Db::rows('SELECT * FROM %i ORDER BY id DESC LIMIT 100', Db::table('redirects'));
    }

    public static function saveRule(string $source, string $target, int $status, int $id, int $revision): string
    {
        if (!Policy::canManage('monitoring') || Policy::state('monitoring') !== 'active') {
            return 'denied';
        }
        if (self::path($source) === null || self::path($target) === null || rtrim($source, '/') === rtrim($target, '/')
            || !in_array($status, [301, 302], true)) {
            return 'invalid';
        }
        return Db::locked('redirects', static function () use ($source, $target, $status, $id, $revision) {
            $rules = self::rules();
            if (!$id && count($rules) >= 100) {
                return 'limit';
            }
            foreach ($rules as $rule) {
                if ((int) $rule['id'] !== $id && (
                    rtrim($rule['source_path'], '/') === rtrim($source, '/')
                    || rtrim($rule['source_path'], '/') === rtrim($target, '/')
                    || rtrim($rule['target_path'], '/') === rtrim($source, '/')
                )) {
                    return 'invalid';
                }
            }
            return Db::transaction(static function () use ($source, $target, $status, $id, $revision) {
                $hash = hash('sha256', $source);
                if (!$id) {
                    $id = Db::insert('redirects', [
                        'source_hash' => $hash, 'source_path' => $source, 'target_path' => $target,
                        'status_code' => $status, 'updated_at' => Db::utc(),
                    ]);
                } else {
                    $changed = Db::query(
                        'UPDATE %i SET source_hash = %s, source_path = %s, target_path = %s, status_code = %d,
                         revision = revision + 1, updated_at = %s WHERE id = %d AND revision = %d',
                        Db::table('redirects'), $hash, $source, $target, $status, Db::utc(), $id, $revision
                    );
                    if ($changed !== 1) {
                        return $changed === 0 ? 'conflict' : false;
                    }
                }
                return $id && Audit::record('redirect.saved', 'redirect', $id) ? 'saved' : false;
            });
        }) ?: 'error';
    }

    public static function removeRule(int $id, int $revision): string
    {
        if (!Policy::canManage('monitoring')) {
            return 'denied';
        }
        return Db::locked('redirects', static fn () => Db::transaction(static function () use ($id, $revision) {
            $changed = Db::query('DELETE FROM %i WHERE id = %d AND revision = %d', Db::table('redirects'), $id, $revision);
            if ($changed !== 1) {
                return $changed === 0 ? 'conflict' : false;
            }
            return Audit::record('redirect.deleted', 'redirect', $id) ? 'saved' : false;
        })) ?: 'error';
    }

    public static function aggregates(): array
    {
        return Db::rows(
            "SELECT day_utc, SUM(hit_count) AS hits, COUNT(*) AS buckets,
             MAX(path_digest = REPEAT('0', 64)) AS path_overflow, SUM(hit_count) >= 50000 AS hit_limit_reached
             FROM %i GROUP BY day_utc ORDER BY day_utc DESC LIMIT 30", Db::table('404_events')
        );
    }

    public static function discoverLinks(string $origin): array
    {
        $posts = get_posts([
            'post_type' => ['post', 'page'], 'post_status' => 'publish', 'numberposts' => 15,
            'no_found_rows' => true, 'update_post_meta_cache' => false, 'update_post_term_cache' => false,
        ]);
        $paths = [];
        foreach ($posts as $post) {
            if ($post->post_password !== '') {
                continue;
            }
            $html = new \WP_HTML_Tag_Processor(substr($post->post_content, 0, 100000));
            while ($html->next_tag('A')) {
                $url = $html->get_attribute('href');
                if (!is_string($url)) {
                    continue;
                }
                if (Outbound::origin($url) === $origin) {
                    $url = substr($url, strlen($origin)) ?: '/';
                }
                $path = self::path($url);
                if ($path !== null && strlen($path) <= 255) {
                    $paths[$path] = $path;
                }
                if (count($paths) >= 20) {
                    return array_values($paths);
                }
            }
        }
        return array_values($paths);
    }
}
