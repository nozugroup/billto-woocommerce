<?php

declare(strict_types=1);

namespace BillTo\WooCommerce\Support;

use WC_Product;

/**
 * Goods vs. service classification of a product - decides between 0% WDT / export (goods)
 * and "np" (services) on foreign B2B invoices.
 *
 * Explicit per-product choice (`_billto_kind` meta: goods|service) wins; otherwise virtual
 * products count as services and everything else as goods.
 */
final class ProductKind
{
    public const META = '_billto_kind';

    public const GOODS = 'goods';

    public const SERVICE = 'service';

    public const AUTO = '';

    public static function isService(?WC_Product $product): bool
    {
        if ($product === null) {
            return false;
        }

        $explicit = (string) $product->get_meta(self::META);

        if ($explicit === self::SERVICE) {
            return true;
        }

        if ($explicit === self::GOODS) {
            return false;
        }

        $auto = $product->is_virtual();

        /** Lets a site override the automatic goods/service detection. */
        return (bool) apply_filters('billto_wc_product_is_service', $auto, $product);
    }
}
