<?php
/** Must only be installed as a must-use plugin on disposable CI sites. */
if (!defined('ABSPATH') || wp_get_environment_type() !== 'local') {
    exit;
}
if (defined('WP_CLI') && WP_CLI) {
    return;
}
$fixtureTrace = ($_SERVER['HTTP_X_FIXTURE_PROFILE'] ?? '') === '1';
if ($fixtureTrace) {
    define('SAVEQUERIES', true);
}
$fixtureCalls = 0;
add_filter('pre_http_request', static function () use (&$fixtureCalls): WP_Error {
    $fixtureCalls++;
    return new WP_Error('fixture_http', 'Network calls are disabled in the passive fixture.');
});
ob_start();
add_action('shutdown', static function () use (&$fixtureCalls, $fixtureTrace): void {
    global $wpdb;
    if (!headers_sent()) {
        header('X-Fixture-Reads: ' . $wpdb->num_queries);
        header('X-Fixture-Calls: ' . $fixtureCalls);
        header('X-Fixture-Time: ' . (1000 * (microtime(true) - $_SERVER['REQUEST_TIME_FLOAT'])));
        if ($fixtureTrace) {
            $callers = array_map(static fn (array $query): string => implode(' → ', array_slice(explode(', ', $query[2]), -4)),
                $wpdb->queries ?? []);
            header('X-Fixture-SQL-Callers: ' . base64_encode(wp_json_encode($callers)));
        }
    }
}, 0);
