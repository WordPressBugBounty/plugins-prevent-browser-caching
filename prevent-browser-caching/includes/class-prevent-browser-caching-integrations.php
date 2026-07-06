<?php

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Purge adapters for the page-cache plugins that Prevent_Browser_Caching detects.
 *
 * Each adapter calls the host plugin's own public purge-all API, so the page
 * cache stops serving HTML that still references old asset versions. Every call
 * is guarded (function/class/action must exist) and wrapped so a failure in a
 * third-party plugin can never fatal or block the version bump.
 *
 * @class Prevent_Browser_Caching_Integrations
 */
class Prevent_Browser_Caching_Integrations
{
    /**
     * Purge the detected page cache, if any.
     *
     * @return array {
     *     @type bool   $purged Whether a purge call ran successfully.
     *     @type string $plugin Detected page-cache plugin name ('' if none).
     *     @type string $reason Machine-readable outcome.
     * }
     */
    public static function purge_page_cache()
    {
        $plugin = Prevent_Browser_Caching::get_active_page_cache_plugin();

        if ( '' === $plugin ) {
            return array(
                'purged' => false,
                'plugin' => '',
                'reason' => 'no supported page cache plugin detected',
            );
        }

        try {
            $purged = self::dispatch_purge( $plugin );
        } catch ( \Throwable $e ) {
            // A misbehaving cache plugin must never break the bump.
            $purged = false;

            return array(
                'purged' => false,
                'plugin' => $plugin,
                'reason' => 'purge failed',
            );
        }

        return array(
            'purged' => $purged,
            'plugin' => $plugin,
            'reason' => $purged ? 'purged' : 'purge function unavailable',
        );
    }

    /**
     * Dispatch to the verified purge call for a detected plugin name.
     *
     * Plugin names match exactly what get_active_page_cache_plugin() returns.
     * Calls verified against current wp.org releases (see docs/v3.1-planning).
     *
     * @param string $plugin
     * @return bool True when a purge call ran.
     */
    private static function dispatch_purge( $plugin )
    {
        switch ( $plugin ) {
            case 'WP Rocket':
                if ( function_exists( 'rocket_clean_domain' ) ) {
                    rocket_clean_domain();
                    return true;
                }
                break;

            case 'LiteSpeed Cache':
                if ( has_action( 'litespeed_purge_all' ) ) {
                    do_action( 'litespeed_purge_all', 'Prevent Browser Caching' );
                    return true;
                }
                break;

            case 'W3 Total Cache':
                // Page cache only; flush_all would also wipe minify/object/CDN.
                if ( function_exists( 'w3tc_flush_posts' ) ) {
                    w3tc_flush_posts();
                    return true;
                }
                break;

            case 'WP Super Cache':
                if ( function_exists( 'wp_cache_clear_cache' ) ) {
                    wp_cache_clear_cache();
                    return true;
                }
                break;

            case 'WP Fastest Cache':
                if ( has_action( 'wpfc_clear_all_cache' ) ) {
                    do_action( 'wpfc_clear_all_cache' );
                    return true;
                }
                break;

            case 'WP-Optimize':
                if ( function_exists( 'WP_Optimize' ) ) {
                    $wpo = WP_Optimize();

                    if ( is_object( $wpo ) && method_exists( $wpo, 'get_page_cache' ) ) {
                        $cache = $wpo->get_page_cache();

                        if ( is_object( $cache ) && method_exists( $cache, 'purge' ) ) {
                            $cache->purge();
                            return true;
                        }
                    }
                }
                break;

            case 'Breeze':
                if ( has_action( 'breeze_clear_all_cache' ) ) {
                    do_action( 'breeze_clear_all_cache' );
                    return true;
                }
                break;

            case 'Cache Enabler':
                if ( method_exists( 'Cache_Enabler', 'clear_complete_cache' ) ) {
                    Cache_Enabler::clear_complete_cache();
                    return true;
                }
                break;

            case 'Hummingbird':
                if ( has_action( 'wphb_clear_page_cache' ) ) {
                    do_action( 'wphb_clear_page_cache' );
                    return true;
                }
                break;

            case 'SiteGround Optimizer':
                if ( function_exists( 'sg_cachepress_purge_cache' ) ) {
                    sg_cachepress_purge_cache();
                    return true;
                }
                break;

            case 'Swift Performance':
                if ( class_exists( 'Swift_Performance_Cache' ) && method_exists( 'Swift_Performance_Cache', 'clear_all_cache' ) ) {
                    Swift_Performance_Cache::clear_all_cache();
                    return true;
                }
                break;

            case 'Comet Cache':
                // clear() = current site; wipe() = whole network (don't).
                if ( method_exists( 'comet_cache', 'clear' ) ) {
                    comet_cache::clear();
                    return true;
                }
                break;
        }

        return false;
    }
}
