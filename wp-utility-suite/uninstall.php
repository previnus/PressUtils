<?php
if (!defined('WP_UNINSTALL_PLUGIN') || !defined('ABSPATH') || version_compare(PHP_VERSION, '8.3', '<')) {
    exit;
}

require_once __DIR__ . '/includes/autoload.php';

if (is_multisite()) {
    if (!Wpus\NetworkPolicy::get(true)['purge'] || get_sites(['network_id' => get_current_network_id(), 'number' => 1, 'offset' => 20])) {
        return;
    }
    foreach (Wpus\Lifecycle::sites(0) as $site) {
        Wpus\Lifecycle::onSite((int) $site, [Wpus\Lifecycle::class, 'purge']);
    }
    Wpus\NetworkPolicy::purge();
} elseif (get_option('wpus_purge', false)) {
    Wpus\Lifecycle::purge();
}
