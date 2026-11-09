<?php
namespace Wpus;

defined('ABSPATH') || exit;

final class Commerce
{
    public static function hooks(): void
    {
        if (Policy::feature('woocommerce', 'orders')) {
            add_action('woocommerce_order_status_changed', [self::class, 'order'], 10, 3);
        }
        if (Policy::feature('woocommerce', 'stock')) {
            add_action('woocommerce_product_set_stock', [self::class, 'stock']);
            add_action('woocommerce_variation_set_stock', [self::class, 'stock']);
            add_action('woocommerce_low_stock', [self::class, 'stock']);
        }
        if (Policy::feature('woocommerce', 'free_shipping')) {
            add_action('woocommerce_cart_totals_after_shipping', [self::class, 'shipping']);
            add_action('woocommerce_review_order_after_shipping', [self::class, 'shipping']);
        }
    }

    public static function order(int $id, string $previous, string $current): void
    {
        $order = wc_get_order($id);
        if (!$order || $order->get_type() !== 'shop_order' || $previous === $current || $order->get_status() !== $current
            || !preg_match('/\A[a-z0-9_-]{1,24}\z/D', $previous) || !preg_match('/\A[a-z0-9_-]{1,24}\z/D', $current)) {
            return;
        }
        Crm::observe('woocommerce.order_status_changed', $id, $current, [
            'order_id' => $id, 'previous_status' => $previous, 'current_status' => $current,
        ], $previous);
    }

    public static function stock(\WC_Product $product): void
    {
        if (!$product->managing_stock() || $product->get_stock_quantity() === null) {
            return;
        }
        $below = $product->get_stock_quantity() <= wc_get_low_stock_amount($product);
        $id = $product->get_id();
        Crm::observe('woocommerce.stock_below_threshold', $id, $below ? 'below' : 'above', $below ? [
            'product_id' => $id, 'stock_status' => $product->is_in_stock() ? 'instock' : 'outofstock',
        ] : null);
    }

    public static function shipping(): void
    {
        if (!WC()->cart || !WC()->cart->needs_shipping() || !WC()->cart->show_shipping()) {
            return;
        }
        $packages = WC()->shipping()->get_packages();
        if (!$packages) {
            return;
        }
        foreach ($packages as $package) {
            $eligible = false;
            foreach ($package['rates'] ?? [] as $rate) {
                if ($rate instanceof \WC_Shipping_Rate && $rate->get_method_id() === 'free_shipping'
                    && \WC_Shipping_Zones::get_shipping_method($rate->get_instance_id()) instanceof \WC_Shipping_Free_Shipping) {
                    $eligible = true;
                    break;
                }
            }
            if (!$eligible) {
                return;
            }
        }
        echo '<tr class="wpus-shipping-notice"><td colspan="2">' .
            esc_html__('Free shipping is available for your current shipping packages.', 'wp-utility-suite') . '</td></tr>';
    }
}
