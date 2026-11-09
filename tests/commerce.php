<?php
/**
 * Run with wp eval-file on a disposable WooCommerce site.
 * Run once in HPOS mode without sync and once in legacy post storage.
 */

use Automattic\WooCommerce\Utilities\OrderUtil;
use Wpus\Commerce;
use Wpus\Crm;
use Wpus\Db;
use Wpus\Policy;
use Wpus\Queue;
use Wpus\Secrets;
use Wpus\Settings;

if (!defined('WP_CLI') || !WP_CLI || wp_get_environment_type() !== 'local' || !Policy::commerceReady() || !Crm::available()) {
    throw new RuntimeException('Use a disposable local site with WooCommerce and the test webhook keyring.');
}
require_once __DIR__ . '/transport-fixture.php';

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        WP_CLI::error($message);
    }
    WP_CLI::log('PASS ' . $message);
};
$hpos = OrderUtil::custom_orders_table_usage_is_enabled();
$assert(!$hpos || get_option('woocommerce_custom_orders_table_data_sync_enabled') !== 'yes', 'Storage mode is ' . ($hpos ? 'HPOS without compatibility sync' : 'legacy posts'));

$admin = get_users(['role' => 'administrator', 'number' => 1])[0];
wp_set_current_user($admin->ID);
update_option('wpus_outbound_paused', false, false);
$assert(Settings::save('woocommerce', ['orders' => true, 'stock' => true], Settings::get('woocommerce', true)['revision']) === 'saved',
    'WooCommerce event settings are enabled');
$assert(Settings::save('crm', ['capture' => true], Settings::get('crm', true)['revision']) === 'saved', 'CRM capture is enabled');
if (!has_action('woocommerce_order_status_changed', [Commerce::class, 'order'])) {
    Commerce::hooks();
}

$events = ['woocommerce.order_status_changed', 'woocommerce.stock_below_threshold'];
$label = 'WooCommerce fixture ' . wp_generate_password(8, false);
$assert(Crm::configure(0, 0, $label, 'https://example.com/woo-fixture', bin2hex(random_bytes(32)), $events, '') === 'saved',
    'WooCommerce destination is configured');
$id = (int) Db::value('SELECT id FROM %i WHERE label = %s', Db::table('webhook_destinations'), $label);
$assert(Crm::test($id, 1) === 'queued', 'Synthetic request is queued');
Queue::sweep();
$assert(Crm::changeState($id, 2, 'active') === 'saved', 'Destination was verified and enabled');

$order = wc_create_order();
$assert($order instanceof WC_Order, 'Order fixture created through WooCommerce CRUD');
$order->update_status('processing');
$order->update_status('completed');
$order->update_status('processing');
do_action('woocommerce_order_status_changed', $order->get_id(), 'completed', 'processing', $order);

$product = new WC_Product_Simple();
$product->set_name('Utility Suite stock fixture');
$product->set_regular_price('9.95');
$product->set_manage_stock(true);
$product->set_stock_quantity(10);
$product->set_low_stock_amount(2);
$product->save();
wc_update_product_stock($product, 2, 'set');
do_action('woocommerce_low_stock', wc_get_product($product->get_id()));
wc_update_product_stock($product, 8, 'set');
wc_update_product_stock($product, 1, 'set');

$rows = Db::rows(
    'SELECT e.event_type, d.delivery_uuid, d.body_key_id, d.body_ciphertext FROM %i d JOIN %i e ON e.id = d.event_id
     WHERE d.destination_id = %d AND d.is_test = 0 ORDER BY d.id', Db::table('webhook_deliveries'), Db::table('webhook_events'), $id
);
$types = array_count_values(array_column($rows, 'event_type'));
$assert(($types[$events[0]] ?? 0) === 3, 'Order status re-entry is captured and repeated hooks are deduplicated');
$assert(($types[$events[1]] ?? 0) === 2, 'Low-stock threshold crossings are captured and repeats are deduplicated');
foreach ($rows as $row) {
    $payload = json_decode(Secrets::open('delivery', $row['delivery_uuid'], $row['body_key_id'], $row['body_ciphertext']), true);
    $expected = $row['event_type'] === $events[0] ? 'order_id' : 'product_id';
    $assert($payload['data'][$expected] === ($expected === 'order_id' ? $order->get_id() : $product->get_id())
        && !isset($payload['data']['email']), 'Commerce payload contains operational identifiers only');
}

$zone = new WC_Shipping_Zone();
$zone->set_zone_name('WPUS free shipping fixture');
$zone->set_zone_order(0);
$zone->add_location('US', 'country');
$zone->save();
$zone->add_shipping_method('free_shipping');
WC()->initialize_session();
WC()->initialize_cart();
$cart = WC()->cart;
$cart->get_customer()->set_shipping_country('US');
$cart->get_customer()->set_shipping_state('CA');
$cart->get_customer()->set_shipping_postcode('90210');
$assert((bool) $cart->add_to_cart($product->get_id()), 'Shipping fixture added to WooCommerce cart');
$cart->calculate_totals();
ob_start();
Commerce::shipping();
$notice = ob_get_clean();
$assert(str_contains($notice, 'wpus-shipping-notice'), 'Classic shipping notice uses an eligible WooCommerce rate');
WC()->shipping()->packages[0]['rates'] = [];
ob_start();
Commerce::shipping();
$notice = ob_get_clean();
$assert($notice === '', 'Classic shipping notice is suppressed for an ineligible package');
$cart->empty_cart();
$zone->delete(true);
$assert(Crm::changeState($id, 3, 'revoked') === 'saved', 'Fixture destination revoked before teardown');
$order->delete(true);
$product->delete(true);
WP_CLI::success('WooCommerce integration checks passed.');
