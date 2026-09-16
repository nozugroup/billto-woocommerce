<?php
/**
 * Runs when the plugin is deleted from the WordPress admin (not on deactivation).
 *
 * Removes plugin options and scheduled actions. Order meta (BillTo ids, invoice numbers) is kept
 * on purpose: it documents which WooCommerce order maps to which fiscal document, and deleting
 * it would not delete anything in BillTo anyway.
 */

declare(strict_types=1);

defined('WP_UNINSTALL_PLUGIN') || exit;

global $wpdb;

// phpcs:ignore WordPress.DB.DirectDatabaseQuery
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE 'billto\\_wc\\_%'");

if (function_exists('as_unschedule_all_actions')) {
    as_unschedule_all_actions('', [], 'billto-wc');
}
