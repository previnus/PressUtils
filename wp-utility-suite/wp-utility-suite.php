<?php
/**
 * Plugin Name: WP Utility Suite
 * Description: Site auditing, performance diagnostics, admin tools, monitoring and optional integrations.
 * Version: 0.2.1
 * Author: Previn Kalisetty Appadu
 * Author URI: https://previn.me
 * Author Email: hi@previn.me
 * Requires at least: 7.0
 * Requires PHP: 8.3
 * Update URI: false
 * License: GPL-3.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain: wp-utility-suite
 */

if (!defined('ABSPATH')) {
    exit;
}

if (version_compare(PHP_VERSION, '8.3', '<') || version_compare($GLOBALS['wp_version'], '7.0', '<')) {
    add_action('admin_notices', static function () {
        echo '<div class="notice notice-error"><p>' .
            esc_html__('WP Utility Suite requires PHP 8.3 and WordPress 7.0 or newer.', 'wp-utility-suite') .
            '</p></div>';
    });
    return;
}

define('WPUS_FILE', __FILE__);
define('WPUS_VERSION', '0.2.1');
require_once __DIR__ . '/includes/autoload.php';

if (is_readable(__DIR__ . '/vendor/action-scheduler/action-scheduler.php')) {
    require_once __DIR__ . '/vendor/action-scheduler/action-scheduler.php';
}

register_activation_hook(__FILE__, [Wpus\Lifecycle::class, 'activate']);
register_deactivation_hook(__FILE__, [Wpus\Lifecycle::class, 'deactivate']);
add_action('plugins_loaded', [Wpus\Plugin::class, 'boot'], 10);
