<?php

declare(strict_types=1);

namespace BillTo\WooCommerce\Admin;

use BillTo\WooCommerce\Support\ProductKind;

/**
 * "Rodzaj dla faktur BillTo" select on the product edit screen (General tab).
 */
final class ProductKindField
{
    public function register(): void
    {
        add_action('woocommerce_product_options_general_product_data', [$this, 'render']);
        add_action('woocommerce_admin_process_product_object', [$this, 'save']);
    }

    public function render(): void
    {
        woocommerce_wp_select([
            'id' => ProductKind::META,
            'label' => __('Rodzaj dla faktur BillTo', 'billto-woocommerce'),
            'description' => __('Decyduje o stawce na fakturach dla nabywców zagranicznych: towar - 0% WDT / eksport, usługa - "np" (art. 28b). Automatycznie: produkt wirtualny = usługa.', 'billto-woocommerce'),
            'desc_tip' => true,
            'options' => [
                ProductKind::AUTO => __('Automatycznie (wirtualny = usługa)', 'billto-woocommerce'),
                ProductKind::GOODS => __('Towar', 'billto-woocommerce'),
                ProductKind::SERVICE => __('Usługa', 'billto-woocommerce'),
            ],
        ]);
    }

    public function save(\WC_Product $product): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verified the product save nonce.
        $value = isset($_POST[ProductKind::META]) ? sanitize_key(wp_unslash((string) $_POST[ProductKind::META])) : '';

        if (! in_array($value, [ProductKind::AUTO, ProductKind::GOODS, ProductKind::SERVICE], true)) {
            $value = ProductKind::AUTO;
        }

        if ($value === ProductKind::AUTO) {
            $product->delete_meta_data(ProductKind::META);
        } else {
            $product->update_meta_data(ProductKind::META, $value);
        }
    }
}
