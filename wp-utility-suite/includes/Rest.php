<?php
namespace Wpus;

defined('ABSPATH') || exit;

final class Rest
{
    private const NS = 'wpus/v1';

    public static function register(): void
    {
        self::route('/health', 'GET', [self::class, 'health'], static fn () => current_user_can('wpus_read_health'), true);
        self::route('/modules', 'GET', [self::class, 'modules'], static fn () => (bool) Admin::visible());
        self::route('/settings/(?P<module>[a-z_]+)', 'GET, PATCH', [self::class, 'settings'],
            static fn (\WP_REST_Request $request) => Policy::canManage($request->get_url_params()['module']));
        self::route('/audit/events', 'GET', [self::class, 'audit'], static fn () => current_user_can('wpus_view_audit'));
        self::route('/performance/diagnostics', 'GET', static fn () => Performance::diagnostics(),
            static fn () => Policy::canManage('performance') && Policy::state('performance') === 'active');
        self::route('/monitoring/404s', 'GET', static fn () => Monitoring::aggregates(),
            static fn () => Policy::canManage('monitoring') && Policy::state('monitoring') === 'active');
        self::route('/notes', 'GET', static function (\WP_REST_Request $request): array {
            return Notes::list(Admin::positive($request->get_query_params(), 'before') ?: PHP_INT_MAX);
        }, static fn () => current_user_can('wpus_view_notes') && Policy::state('admin_tools') === 'active');
        self::route('/redirects', 'GET', static fn () => Monitoring::rules(),
            static fn () => Policy::canManage('monitoring') && Policy::state('monitoring') === 'active');
        self::route('/webhooks/destinations', 'GET', static fn () => Crm::destinations(),
            static fn () => Policy::canManage('crm'));
        self::route('/webhooks/deliveries', 'GET', static fn () => Crm::deliveries(),
            static fn () => current_user_can('wpus_view_deliveries'));
        self::route('/webhooks/deliveries/(?P<uuid>[a-f0-9-]{36})', 'GET', [self::class, 'delivery'],
            static fn () => current_user_can('wpus_view_deliveries'));
        self::route('/operations/(?P<uuid>[a-f0-9-]{36})', 'GET', [self::class, 'operation'], static fn () => true);

        add_filter('rest_pre_dispatch', static function ($result, \WP_REST_Server $server, \WP_REST_Request $request) {
            if (self::owns($request) && (isset($request->get_query_params()['_method']) || $request->get_header('x-http-method-override') !== null)) {
                return self::error('method', 'Method overrides are unavailable.', 400);
            }
            return $result;
        }, 10, 3);
        add_filter('rest_post_dispatch', static function ($response, \WP_REST_Server $server, \WP_REST_Request $request) {
            if (self::owns($request)) {
                $response->header('Cache-Control', 'private, no-store, max-age=0');
                if ($response->get_status() === 429) {
                    $response->header('Retry-After', '60');
                }
            }
            return $response;
        }, 10, 3);
    }

    private static function owns(\WP_REST_Request $request): bool
    {
        return str_starts_with(strtolower($request->get_route()), '/' . self::NS . '/');
    }

    private static function route(string $path, string $method, callable $callback, callable $capability, bool $probe = false): void
    {
        register_rest_route(self::NS, $path, [
            'methods' => $method,
            'callback' => $callback,
            'permission_callback' => static function (\WP_REST_Request $request) use ($capability, $probe) {
                if (!is_user_logged_in()) {
                    return self::error('authentication', 'Authentication required.', 401);
                }
                $cookie = wp_validate_auth_cookie('', 'logged_in') === get_current_user_id()
                    && wp_verify_nonce($request->get_header('x-wp-nonce') ?? '', 'wp_rest');
                $application = $probe && is_ssl() && did_action('application_password_did_authenticate');
                if ((!$cookie && !$application) || !$capability($request)) {
                    return self::error('forbidden', 'You do not have access to this endpoint.', 403);
                }
                if (!Lifecycle::ready()) {
                    return self::error('setup', 'Utility Suite data is unavailable.', 503);
                }
                return true;
            },
        ]);
    }

    private static function error(string $code, string $message, int $status): \WP_Error
    {
        return new \WP_Error('wpus_' . $code, $message, ['status' => $status, 'correlation_id' => wp_generate_uuid4()]);
    }

    public static function health(): mixed
    {
        if (!Audit::reserve('rest_' . gmdate('YmdHi') . '_health_' . get_current_user_id(), 30)) {
            return self::error('throttled', 'Health request limit reached.', 429);
        }
        return Health::snapshot();
    }

    public static function modules(): array
    {
        $modules = [];
        foreach (Admin::visible() as $module => $label) {
            $modules[$module] = ['label' => $label, 'state' => Policy::state($module)];
            if (Policy::canManage($module)) {
                $modules[$module]['revision'] = Settings::get($module)['revision'];
            }
        }
        return $modules;
    }

    public static function settings(\WP_REST_Request $request): mixed
    {
        $module = $request->get_url_params()['module'];
        if ($request->get_method() === 'PATCH') {
            $precondition = $request->get_header('if-match');
            if ($precondition === null) {
                return self::error('precondition', 'Send the current ETag in If-Match.', 428);
            }
            if (!preg_match('/\A"([1-9][0-9]{0,17})"\z/D', $precondition, $match)) {
                return self::error('precondition', 'The revision is invalid.', 412);
            }
            $body = $request->get_body();
            if (strlen($body) > 65536) {
                return self::error('too_large', 'The request exceeds 64 KiB.', 413);
            }
            $patch = json_decode($body, false, 16);
            $query = array_diff_key($request->get_query_params(), array_flip(['rest_route', '_locale']));
            if (!$request->is_json_content_type() || !$patch instanceof \stdClass || $query) {
                return self::error('invalid', 'Send a JSON object with no query parameters.', 400);
            }
            $minute = gmdate('YmdHi');
            if (!Audit::reserve('rest_' . $minute . '_actor_' . get_current_user_id(), 30)
                || !Audit::reserve('rest_' . $minute . '_site', 120)) {
                return self::error('throttled', 'Write request limit reached.', 429);
            }
            $result = Settings::save($module, get_object_vars($patch), (int) $match[1]);
            if ($result !== 'saved') {
                return self::error($result, 'The settings change was rejected.', match ($result) {
                    'conflict' => 412, 'denied' => 403, 'invalid' => 400, default => 503,
                });
            }
        }
        $settings = Settings::get($module, true);
        $response = rest_ensure_response($settings + ['state' => Policy::state($module)]);
        $response->header('ETag', '"' . $settings['revision'] . '"');
        return $response;
    }

    public static function audit(\WP_REST_Request $request): mixed
    {
        $query = $request->get_query_params();
        $filter = Admin::auditFilter($query);
        if (!$filter) {
            return self::error('invalid', 'The audit filter is invalid.', 400);
        }
        [, , $event, $actor, $from, $until] = $filter;
        $scope = wp_json_encode([get_current_blog_id(), $event, $actor, $from, $until]);
        $limit = min(100, Admin::positive($query, 'limit') ?: 25);
        $cursor = Admin::scalar($query, 'cursor', 100);
        $before = 0;
        if ($cursor !== '') {
            $token = base64_decode(strtr($cursor, '-_', '+/'), true);
            if (!$token || !preg_match('/\A([1-9][0-9]{0,17})\.([a-f0-9]{64})\z/D', $token, $match)
                || !hash_equals(hash_hmac('sha256', $scope . '|' . $match[1], wp_salt('nonce')), $match[2])) {
                return self::error('cursor', 'The cursor is invalid for this filter.', 400);
            }
            $before = (int) $match[1];
        }
        $rows = Audit::search($from, $until, $event, $actor, $limit + 1, $before);
        $next = null;
        if (count($rows) > $limit) {
            array_pop($rows);
            $id = end($rows)['id'];
            $next = rtrim(strtr(base64_encode($id . '.' . hash_hmac('sha256', $scope . '|' . $id, wp_salt('nonce'))), '+/', '-_'), '=');
        }
        foreach ($rows as &$row) {
            $row['details'] = Db::decode($row['details_json']);
            unset($row['details_json']);
        }
        return ['items' => $rows, 'next' => $next];
    }

    public static function delivery(\WP_REST_Request $request): mixed
    {
        $row = Db::row(
            'SELECT id, delivery_uuid, state, attempt_count, last_code, last_http_status, created_at FROM %i WHERE delivery_uuid = %s',
            Db::table('webhook_deliveries'), $request->get_url_params()['uuid']
        );
        if (!$row) {
            return self::error('missing', 'Delivery not found.', 404);
        }
        $row['attempts'] = Crm::attempts((int) $row['id']);
        return $row;
    }

    public static function operation(\WP_REST_Request $request): mixed
    {
        $row = Db::row(
            'SELECT public_id, operation_type, actor_user_id, object_id, state, result_code, run_at, created_at
             FROM %i WHERE public_id = %s', Db::table('operations'), $request->get_url_params()['uuid']
        );
        if (!$row) {
            return self::error('missing', 'Operation not found.', 404);
        }
        $module = match ($row['operation_type']) {
            'expiry' => 'admin_tools',
            'cleanup' => 'performance',
            default => 'monitoring',
        };
        $initiator = (int) $row['actor_user_id'] === get_current_user_id()
            && (!is_multisite() || is_user_member_of_blog(get_current_user_id(), get_current_blog_id()));
        if ((!$initiator && !Policy::canManage($module))
            || ($row['operation_type'] === 'expiry' && !Notes::access((int) $row['object_id']))) {
            return self::error('missing', 'Operation not found.', 404);
        }
        unset($row['actor_user_id']);
        return $row;
    }
}
