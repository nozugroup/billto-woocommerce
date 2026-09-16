<?php
/**
 * Plugin Name:       BillTo for WooCommerce
 * Plugin URI:        https://github.com/nozugroup/billto-woocommerce
 * Update URI:        https://billto.pl/integracje/woocommerce
 * Description:       Wystawia faktury VAT w BillTo dla zamówień WooCommerce: zamówienie trafia do BillTo, po opłaceniu powstaje faktura, opcjonalnie wysyłana do KSeF. Pole NIP w checkout, PDF w panelu i w koncie klienta, korekty przy zwrotach.
 * Version:           1.0.0
 * Requires at least: 6.4
 * Requires PHP:      8.0
 * Requires Plugins:  woocommerce
 * WC requires at least: 8.2
 * WC tested up to:   9.9
 * Author:            BillTo
 * Author URI:        https://billto.pl
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       billto-woocommerce
 * Domain Path:       /languages
 *
 * @package BillTo\WooCommerce
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

define('BILLTO_WC_VERSION', '1.0.0');
define('BILLTO_WC_FILE', __FILE__);
define('BILLTO_WC_DIR', plugin_dir_path(__FILE__));
define('BILLTO_WC_URL', plugin_dir_url(__FILE__));
define('BILLTO_WC_BASENAME', plugin_basename(__FILE__));

/**
 * Minimal PSR-4 autoloader for the plugin's own classes. No Composer at runtime: WordPress hosts
 * commonly run several plugins that each bundle their own vendor/ and clash on shared libraries.
 */
spl_autoload_register(static function (string $class): void {
    // Plugin classes and the vendored shared core (billto/shop-integration-core, lib/).
    $roots = [
        'BillTo\\WooCommerce\\' => BILLTO_WC_DIR.'includes/',
        'BillTo\\Shop\\' => BILLTO_WC_DIR.'lib/shop-integration-core/src/',
    ];

    foreach ($roots as $prefix => $dir) {
        if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
            continue;
        }

        $file = $dir.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';

        if (is_file($file)) {
            require $file;
        }

        return;
    }
});

/**
 * Declare compatibility with WooCommerce High-Performance Order Storage (custom order tables).
 */
add_action('before_woocommerce_init', static function (): void {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('cart_checkout_blocks', __FILE__, true);
    }
});

add_action('plugins_loaded', static function (): void {
    if (! class_exists('WooCommerce')) {
        add_action('admin_notices', static function (): void {
            echo '<div class="notice notice-error"><p>'
                .esc_html__('BillTo for WooCommerce wymaga aktywnej wtyczki WooCommerce.', 'billto-woocommerce')
                .'</p></div>';
        });

        return;
    }

    \BillTo\WooCommerce\Plugin::instance()->boot();
}, 20);

register_activation_hook(__FILE__, [\BillTo\WooCommerce\Plugin::class, 'activate']);
