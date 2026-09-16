<?php

declare(strict_types=1);

namespace BillTo\WooCommerce\Support;

/**
 * Update channel for a plugin that is not hosted on wordpress.org.
 *
 * The `Update URI:` header opts the plugin out of the wordpress.org lookup; WordPress then calls
 * the `update_plugins_{hostname}` filter for it and never matches the slug against the directory.
 *
 * The latest version is read from BillTo (`/api/v1/plugins/woocommerce/latest`), which relays the
 * latest published GitHub release, and cached in a site transient. The lookup always goes to
 * production billto.pl, also when the shop talks to the sandbox API.
 */
final class UpdateChecker
{
    /**
     * Host of the `Update URI:` header. WordPress derives the filter name from it, so the two
     * must stay in sync with the plugin header.
     */
    public const UPDATE_HOST = 'billto.pl';

    private const ENDPOINT = 'https://billto.pl/api/v1/plugins/woocommerce/latest';

    private const TRANSIENT = 'billto_wc_update_payload';

    /** 12 hours, matching how often WordPress runs its own update check. */
    private const TTL = 43200;

    /** Short cache after a failed lookup, so an outage does not mean a request per page load. */
    private const TTL_FAILURE = 1800;

    public function register(): void
    {
        add_filter('update_plugins_'.self::UPDATE_HOST, [$this, 'check'], 10, 3);
    }

    /**
     * @param  array<string, mixed>|false  $update
     * @param  array<string, mixed>  $pluginData
     * @return array<string, mixed>|false
     */
    public function check($update, array $pluginData, string $pluginFile)
    {
        if ($pluginFile !== BILLTO_WC_BASENAME) {
            return $update;
        }

        $release = $this->latestRelease();

        if ($release === null) {
            return $update;
        }

        $version = (string) ($release['version'] ?? '');
        $package = (string) ($release['download_url'] ?? '');

        // No version, no installable ZIP, or nothing newer than what is running: offer nothing.
        // A release without an attached ZIP is a broken release, and pointing WordPress at a
        // A GitHub source archive installs a directory named after the commit, so it is skipped.
        if ($version === '' || $package === '' || version_compare($version, BILLTO_WC_VERSION, '<=')) {
            return $update;
        }

        $requirements = is_array($release['requirements'] ?? null) ? $release['requirements'] : [];

        return [
            'id' => 'billto.pl/billto-woocommerce',
            'slug' => (string) ($release['slug'] ?? 'billto-woocommerce'),
            'plugin' => BILLTO_WC_BASENAME,
            'version' => $version,
            'url' => (string) ($release['changelog_url'] ?? $release['homepage'] ?? 'https://billto.pl/integracje/woocommerce'),
            'package' => $package,
            'tested' => (string) ($requirements['platform_max'] ?? ''),
            'requires_php' => (string) ($requirements['php_min'] ?? ''),
        ];
    }

    /**
     * Cached release payload, or null when BillTo is unreachable or has published nothing yet.
     *
     * @return array<string, mixed>|null
     */
    private function latestRelease(): ?array
    {
        $cached = get_site_transient(self::TRANSIENT);

        if (is_array($cached)) {
            return $cached;
        }

        // 'none' marks a recent failed or empty lookup - distinct from "nothing cached yet".
        if ($cached === 'none') {
            return null;
        }

        $response = wp_remote_get(self::ENDPOINT, [
            'timeout' => 10,
            'headers' => [
                'Accept' => 'application/json',
                'User-Agent' => 'billto-woocommerce/'.BILLTO_WC_VERSION.' WordPress/'.get_bloginfo('version'),
            ],
        ]);

        if (is_wp_error($response) || (int) wp_remote_retrieve_response_code($response) !== 200) {
            set_site_transient(self::TRANSIENT, 'none', self::TTL_FAILURE);

            return null;
        }

        $payload = json_decode((string) wp_remote_retrieve_body($response), true);

        // `version` is null until the first release is published - cache that as a miss.
        if (! is_array($payload) || ! isset($payload['version'])) {
            set_site_transient(self::TRANSIENT, 'none', self::TTL_FAILURE);

            return null;
        }

        set_site_transient(self::TRANSIENT, $payload, self::TTL);

        return $payload;
    }
}
