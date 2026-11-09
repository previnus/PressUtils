<?php
namespace Wpus;

defined('ABSPATH') || exit;

final class Plugin
{
    public static function boot(): void
    {
        add_action('init', static function (): void {
            if (!Lifecycle::ready() && is_admin() && current_user_can('manage_options')) {
                Lifecycle::provision();
            }
        }, 0);
        add_action('wpus_network_provision', [Lifecycle::class, 'provisionNetwork']);
        add_action('wp_initialize_site', [Lifecycle::class, 'newSite'], 100);
        add_filter('wpmu_drop_tables', [Lifecycle::class, 'dropSiteTables'], 10, 2);
        Queue::hooks();
        add_action('rest_api_init', [Rest::class, 'register']);
        if (is_admin()) {
            Admin::hooks();
        }
        Privacy::hooks();
        if (!Lifecycle::ready()) {
            return;
        }
        Audit::hooks();
        if (Policy::feature('performance', 'slow_http')) {
            Performance::httpHooks();
        }
        if (Policy::feature('monitoring', 'redirects') || Policy::feature('monitoring', 'record_404')) {
            add_action('template_redirect', [Monitoring::class, 'frontend'], 20);
        }
        if (Policy::feature('crm', 'capture')) {
            add_action('transition_post_status', [self::class, 'postStatus'], 10, 3);
        }
        if (Policy::state('woocommerce') === 'active') {
            Commerce::hooks();
        }
    }

    public static function postStatus(string $new, string $old, \WP_Post $post): void
    {
        if (!in_array($post->post_type, ['post', 'page'], true) || $new === $old || $post->post_password !== ''
            || get_post_status($post->ID) !== $new) {
            return;
        }
        Crm::observe('content.published', $post->ID, $new, $new === 'publish' ? [
            'content_id' => $post->ID, 'content_type' => $post->post_type,
        ] : null);
    }
}
