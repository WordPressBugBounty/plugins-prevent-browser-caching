<?php

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Long browser caching ("efficient cache policy") for versioned static assets.
 *
 * Because Prevent_Browser_Caching guarantees that asset URLs change whenever
 * the files change, it is safe to serve those files with a one-year
 * Cache-Control policy. Static files bypass PHP, so the headers are delivered
 * as an .htaccess block on Apache/LiteSpeed (written with core's own
 * insert_with_markers API) and as a copyable snippet everywhere else. An HTTP
 * probe of a real static file reports whether the headers actually work —
 * a host may silently lack mod_headers/mod_expires.
 *
 * @class Prevent_Browser_Caching_Cache_Policy
 */
class Prevent_Browser_Caching_Cache_Policy
{
    /**
     * Marker used for the .htaccess block: # BEGIN/END Prevent Browser Caching.
     */
    const MARKER = 'Prevent Browser Caching';

    /**
     * Option storing the last verification probe result.
     */
    const PROBE_OPTION = 'prevent_browser_caching_cache_policy_probe';

    /**
     * One year in seconds — the max-age sent for versioned static assets.
     */
    const MAX_AGE = 31536000;

    /**
     * File extensions that get the long-cache policy, per the current options.
     * CSS/JS are covered because the cache_policy option requires asset
     * versioning; fonts are filename-unique in practice and not replaceable
     * in place via WordPress; media extensions join only while media
     * versioning is on.
     *
     * @param array $options Filtered plugin options.
     * @return string Regex alternatives for FilesMatch / nginx location.
     */
    public static function get_extensions_pattern( $options )
    {
        $extensions = 'css|js|mjs|woff|woff2|ttf|otf|eot';

        if ( ! empty( $options['media_versions'] ) ) {
            $extensions .= '|jpe?g|png|gif|webp|avif|svg|ico|bmp|mp4|webm|ogv|ogg|mp3|m4a|wav|pdf';
        }

        return $extensions;
    }

    /**
     * The .htaccess block content (without the BEGIN/END markers).
     *
     * Dual <IfModule> blocks maximize host coverage: mod_expires alone still
     * yields Expires + max-age, mod_headers overrides with the full value;
     * a host missing either module can never 500 on these rules.
     *
     * @param array $options Filtered plugin options.
     * @return string[] Lines for insert_with_markers().
     */
    public static function get_rules( $options )
    {
        $pattern = self::get_extensions_pattern( $options );

        $rules = array(
            '<IfModule mod_expires.c>',
            'ExpiresActive On',
            '<FilesMatch "\.(' . $pattern . ')$">',
            'ExpiresDefault "access plus 1 year"',
            '</FilesMatch>',
            '</IfModule>',
            '<IfModule mod_headers.c>',
            '<FilesMatch "\.(' . $pattern . ')$">',
            'Header set Cache-Control "public, max-age=' . self::MAX_AGE . ', immutable"',
            '</FilesMatch>',
            '</IfModule>',
        );

        /**
         * Filters the generated .htaccess cache-policy rules.
         *
         * @param string[] $rules   Lines placed between the BEGIN/END markers.
         * @param array    $options Filtered plugin options.
         */
        return apply_filters( 'pbc_cache_policy_rules', $rules, $options );
    }

    /**
     * The same policy as a copyable nginx server-block snippet.
     *
     * @param array $options Filtered plugin options.
     * @return string
     */
    public static function get_nginx_snippet( $options )
    {
        $pattern = self::get_extensions_pattern( $options );

        return 'location ~* \.(' . $pattern . ')$ {' . "\n"
            . '    expires 1y;' . "\n"
            . '    add_header Cache-Control "public, max-age=' . self::MAX_AGE . ', immutable";' . "\n"
            . '}';
    }

    /**
     * The .htaccess block including markers, for manual copy-paste.
     *
     * @param array $options Filtered plugin options.
     * @return string
     */
    public static function get_htaccess_snippet( $options )
    {
        return '# BEGIN ' . self::MARKER . "\n"
            . implode( "\n", self::get_rules( $options ) ) . "\n"
            . '# END ' . self::MARKER;
    }

    /**
     * Path to the site's .htaccess (the same file core's permalink writer uses).
     *
     * @return string
     */
    public static function get_htaccess_path()
    {
        if ( ! function_exists( 'get_home_path' ) ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }

        return get_home_path() . '.htaccess';
    }

    /**
     * Whether this install should write .htaccess automatically.
     *
     * Multisite is excluded — .htaccess is shared by every site, a per-site
     * option must not edit it. SERVER_SOFTWARE is empty in WP-CLI, so an
     * existing .htaccess also qualifies (core detects LiteSpeed as Apache).
     *
     * @return bool
     */
    public static function should_auto_write()
    {
        if ( defined( 'PBC_DISABLE_HTACCESS_WRITE' ) && PBC_DISABLE_HTACCESS_WRITE ) {
            return false;
        }

        if ( is_multisite() ) {
            return false;
        }

        global $is_apache;

        return ! empty( $is_apache ) || file_exists( self::get_htaccess_path() );
    }

    /**
     * Write (or refresh) the cache-policy block in .htaccess.
     *
     * @param array $options Filtered plugin options.
     * @return bool
     */
    public static function write_rules( $options )
    {
        if ( ! function_exists( 'insert_with_markers' ) ) {
            require_once ABSPATH . 'wp-admin/includes/misc.php';
        }

        return insert_with_markers( self::get_htaccess_path(), self::MARKER, self::get_rules( $options ) );
    }

    /**
     * Remove the whole marker block from .htaccess, markers included.
     * insert_with_markers() with an empty insertion would leave the marker
     * pair behind, so a deactivated plugin needs its own clean removal.
     *
     * @return bool True when the file no longer contains the block.
     */
    public static function remove_rules()
    {
        $path = self::get_htaccess_path();

        if ( ! file_exists( $path ) ) {
            return true;
        }

        if ( ! wp_is_writable( $path ) ) {
            return false;
        }

        $contents = file_get_contents( $path );

        if ( false === $contents || false === strpos( $contents, '# BEGIN ' . self::MARKER ) ) {
            return true;
        }

        $lines = preg_split( '/\r\n|\r|\n/', $contents );
        $kept = array();
        $in_block = false;
        $found_end = false;

        foreach ( $lines as $line ) {
            if ( ! $in_block && false !== strpos( $line, '# BEGIN ' . self::MARKER ) ) {
                $in_block = true;
                continue;
            }

            if ( $in_block && ! $found_end ) {
                if ( false !== strpos( $line, '# END ' . self::MARKER ) ) {
                    $found_end = true;
                }
                continue;
            }

            $kept[] = $line;
        }

        // Collapse the leftover blank line where the block used to be.
        $new_contents = preg_replace( "/\n{3,}/", "\n\n", implode( "\n", $kept ) );

        return false !== file_put_contents( $path, $new_contents, LOCK_EX );
    }

    /**
     * Fetch one of the site's own static CSS files and check whether the
     * long-cache header really comes back. The only honest check: PHP-FPM
     * cannot see which Apache modules are loaded, and <IfModule> makes
     * missing ones fail silently.
     *
     * @return string 'verified' | 'not_detected' | 'unknown'.
     */
    public static function probe()
    {
        $url = includes_url( 'css/dashicons.min.css' ) . '?pbc_probe=' . time();

        $response = wp_remote_get( $url, array( 'timeout' => 5, 'redirection' => 2 ) );

        if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
            $state = 'unknown';
        } else {
            $header = wp_remote_retrieve_header( $response, 'cache-control' );
            $header = is_array( $header ) ? implode( ', ', $header ) : (string) $header;

            $state = ( false !== strpos( $header, 'max-age=' . self::MAX_AGE ) ) ? 'verified' : 'not_detected';
        }

        update_option( self::PROBE_OPTION, array( 'state' => $state, 'checked_at' => time() ), false );

        return $state;
    }

    /**
     * Last stored probe result.
     *
     * @return array { 'state' => string, 'checked_at' => int }
     */
    public static function get_probe_result()
    {
        $probe = get_option( self::PROBE_OPTION );

        if ( ! is_array( $probe ) || empty( $probe['state'] ) ) {
            return array( 'state' => 'unchecked', 'checked_at' => 0 );
        }

        return array(
            'state' => (string) $probe['state'],
            'checked_at' => isset( $probe['checked_at'] ) ? (int) $probe['checked_at'] : 0,
        );
    }

    /**
     * Single choke point keeping the server config in step with the options.
     * Called on every prevent_browser_caching_options update and from the
     * activation/deactivation hooks.
     *
     * @param array $old_options Previous options (any shape; may be empty).
     * @param array $new_options New filtered options.
     */
    public static function sync( $old_options, $new_options )
    {
        $was_on = is_array( $old_options ) && ! empty( $old_options['cache_policy'] );
        $is_on = is_array( $new_options ) && ! empty( $new_options['cache_policy'] );

        if ( $is_on ) {
            // Under a "manual only" mode with no stored version yet, assets
            // would carry no ?ver at all — unacceptable with a 1-year policy.
            if ( isset( $new_options['clear_cache_automatically'] )
                && 'never' === $new_options['clear_cache_automatically']
                && ! intval( get_option( 'prevent_browser_caching_clear_cache_time' ) ) ) {
                update_option( 'prevent_browser_caching_clear_cache_time', time() );
            }

            if ( self::should_auto_write() ) {
                // Idempotent: insert_with_markers no-ops on identical content,
                // and rewrites the block when the media toggle changed it.
                self::write_rules( $new_options );
            }

            // Runs in snippet mode too: it verifies manually-added rules.
            self::probe();

            return;
        }

        if ( $was_on ) {
            self::remove_rules();
            delete_option( self::PROBE_OPTION );
        }
    }
}
