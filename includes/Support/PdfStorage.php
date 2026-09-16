<?php

declare(strict_types=1);

namespace BillTo\WooCommerce\Support;

/**
 * Stores invoice PDFs under wp-content/uploads/billto-invoices/ with direct access blocked,
 * so they can be attached to e-mails and served through a permission-checked endpoint.
 */
final class PdfStorage
{
    private const DIRECTORY = 'billto-invoices';

    public function ensureDirectory(): string
    {
        $dir = $this->directory();

        if (! is_dir($dir)) {
            wp_mkdir_p($dir);
        }

        if (! file_exists($dir.'/.htaccess')) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
            file_put_contents($dir.'/.htaccess', "Order deny,allow\nDeny from all\n");
        }

        if (! file_exists($dir.'/index.html')) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
            file_put_contents($dir.'/index.html', '');
        }

        return $dir;
    }

    /** Writes the PDF for an order and returns the absolute path. */
    public function store(int $orderId, string $suffix, string $content): string
    {
        $dir = $this->ensureDirectory();
        $name = sprintf('%d-%s-%s.pdf', $orderId, sanitize_file_name($suffix), substr(wp_hash((string) $orderId.$suffix), 0, 12));
        $path = $dir.'/'.$name;

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
        file_put_contents($path, $content);

        return $path;
    }

    public function exists(?string $path): bool
    {
        return is_string($path) && $path !== '' && str_starts_with($path, $this->directory()) && is_file($path);
    }

    private function directory(): string
    {
        $uploads = wp_upload_dir();

        return rtrim((string) $uploads['basedir'], '/\\').'/'.self::DIRECTORY;
    }
}
