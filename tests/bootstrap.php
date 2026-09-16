<?php

declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';

// Patchwork caches preprocessed files in the directory named in patchwork.json; it must exist
// (the directory is gitignored, so a fresh checkout / CI runner does not have it).
if (! is_dir(__DIR__.'/../.patchwork-cache')) {
    mkdir(__DIR__.'/../.patchwork-cache', 0777, true);
}

// Patchwork must be loaded BEFORE any file that defines the WordPress functions Brain Monkey
// will redefine; Composer's autoloader alone does not load it.
require __DIR__.'/../vendor/antecedent/patchwork/Patchwork.php';

// WordPress / WooCommerce class signatures, so Mockery can mock WC_Order & co. The stub files also
// declare WordPress functions with empty bodies; Brain Monkey (Patchwork) redefines the ones tests stub,
// because Patchwork is loaded by the Composer autoloader before these files.
if (! class_exists('WP_Widget')) {
    require __DIR__.'/../vendor/php-stubs/wordpress-stubs/wordpress-stubs.php';
}

if (! class_exists('WC_Order')) {
    require __DIR__.'/../vendor/php-stubs/woocommerce-stubs/woocommerce-stubs.php';
}

if (! defined('ABSPATH')) {
    define('ABSPATH', __DIR__.'/');
}

define('BILLTO_WC_VERSION', '0.0.0-test');
define('BILLTO_WC_DIR', dirname(__DIR__).'/');
define('BILLTO_WC_URL', 'https://shop.test/wp-content/plugins/billto-woocommerce/');
define('BILLTO_WC_BASENAME', 'billto-woocommerce/billto-woocommerce.php');
