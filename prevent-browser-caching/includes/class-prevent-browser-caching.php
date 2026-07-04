<?php

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * @class Prevent_Browser_Caching
 */
class Prevent_Browser_Caching
{
    /**
     * Single instance of the class.
     *
     * @var Prevent_Browser_Caching
     */
    protected static $_instance = null;

    /**
     * Value of prevent_browser_caching_options option.
     *
     * @var array
     */
    public $options = array();

    /**
     * Value of prevent_browser_caching_clear_cache_time option.
     *
     * @var string
     */
    public $clear_cache_time = '';

    /**
     * Show "Update CSS/JS" button on the toolbar.
     *
     * @var bool
     */
    public $show_on_toolbar = false;

    /**
     * Url parameter "time" which will be added to styles and scripts.
     *
     * @var string
     */
    public $time_query_arg = '';

    /**
     * Per-request cache of resolved file modification times, keyed by src.
     *
     * @var array
     */
    protected $mtime_cache = array();

    /**
     * Prevent_Browser_Caching instance.
     *
     * @static
     * @return Prevent_Browser_Caching - Main instance
     */
    public static function instance()
    {
        if (is_null(self::$_instance)) {
            self::$_instance = new self();
        }

        return self::$_instance;
    }

    /**
     * Prevent_Browser_Caching Constructor.
     */
    public function __construct()
    {
        $this->init_params();

        $options = $this->get_options();

        if ( $this->show_on_toolbar && current_user_can( 'manage_options' ) ) {
            add_action( 'admin_bar_menu', array( $this, 'admin_bar_menu' ), 10000 );

            if ( is_admin() ) {
                add_action( 'admin_init', array( $this, 'update_css_js' ), 10000 );
            } else {
                add_action( 'template_redirect', array( $this, 'update_css_js' ), 10000 );
            }
        }

        if ( $options['assets'] && ( ! is_admin() || $options['admin_area'] ) ) {
            add_filter( 'style_loader_src', array( $this, 'add_query_arg' ), 10000, 2 );
            add_filter( 'script_loader_src', array( $this, 'add_query_arg' ), 10000, 2 );
        }

        if ( $options['media_versions'] ) {
            add_filter( 'wp_update_attachment_metadata', array( $this, 'maybe_bump_media_time' ), 10000, 2 );

            if ( ! is_admin() ) {
                add_filter( 'wp_get_attachment_url', array( $this, 'add_media_query_arg_to_attachment_url' ), 10000, 2 );
                add_filter( 'wp_get_attachment_image_src', array( $this, 'add_media_query_arg_to_image_src' ), 10000, 2 );
                add_filter( 'wp_calculate_image_srcset', array( $this, 'add_media_query_arg_to_srcset' ), 10000, 5 );
                add_filter( 'the_content', array( $this, 'add_media_query_arg_to_content' ), 10000 );
            }
        }

        if ( $options['html_freshness'] && ! is_admin() && ! self::get_active_page_cache_plugin() ) {
            add_action( 'send_headers', array( $this, 'send_html_freshness_header' ) );
            add_action( 'wp_footer', array( $this, 'print_bfcache_guard_script' ), 10000 );
        }
    }

    /**
     * Initialize Prevent_Browser_Caching parameters.
     */
    public function init_params()
    {
        $options = $this->get_options();

        $clear_cache_automatically = $options['clear_cache_automatically'];

        $time = '';

        if ( $clear_cache_automatically == 'every_time' ) {
            $time = $this->get_time_code();
        } elseif ( $clear_cache_automatically == 'every_period' ) {
            $update_time = true;

            if ( isset( $_COOKIE['prevent_browser_caching_time'] ) ) {
                $time = intval( $_COOKIE['prevent_browser_caching_time'] );
                $time = max( $time, $this->get_clear_cache_time() );
                $current_time = $this->get_time_code();
                $cached_minutes = round( ( $current_time - $time ) / 60 );

                if ( $cached_minutes > $options['clear_cache_automatically_minutes'] ) {
                    $update_time = true;
                } else {
                    $update_time = false;
                }
            }

            if ( $update_time ) {
                $time = $this->get_time_code();
                $expiration_time = $time + 60 * $options['clear_cache_automatically_minutes'];

                if ( ! headers_sent() ) {
                    setcookie( 'prevent_browser_caching_time', $time, $expiration_time, '/' );
                }
            }
        } elseif ( $clear_cache_automatically == 'never' ) {
            $time = $this->get_clear_cache_time();
        }

        $this->time_query_arg = $time;

        $this->show_on_toolbar = $options['show_on_toolbar'];
    }

    /**
     * Default options for a fresh install (recommended setup).
     *
     * @static
     * @return array
     */
    public static function get_fresh_defaults()
    {
        return array(
            'settings_version' => 3,
            'assets' => true,
            'clear_cache_automatically' => 'auto',
            'clear_cache_automatically_minutes' => 10,
            'version_external' => false,
            'exclusions' => '',
            'admin_area' => false,
            'media_versions' => true,
            'html_freshness' => true,
            'show_on_toolbar' => true,
            'legacy_defaults' => false,
        );
    }

    /**
     * Default options for a site upgrading from 2.x with saved settings:
     * every behavior stays exactly as it was, new features are opt-in.
     *
     * @static
     * @return array
     */
    public static function get_legacy_defaults()
    {
        return array(
            'settings_version' => 3,
            'assets' => true,
            'clear_cache_automatically' => 'every_time',
            'clear_cache_automatically_minutes' => 10,
            'version_external' => true,
            'exclusions' => '',
            'admin_area' => false,
            'media_versions' => false,
            'html_freshness' => false,
            'show_on_toolbar' => false,
            'legacy_defaults' => true,
        );
    }

    /**
     * Sanitize and return the options in the right form.
     *
     * Handles three shapes of input: an empty value (fresh install — recommended
     * defaults), a 2.x options array without "settings_version" (legacy site —
     * behavior-preserving defaults), and a 3.x array from the DB or settings form.
     *
     * @param $options
     * @return array
     */
    public function filter_options( $options )
    {
        if ( ! is_array( $options ) || empty( $options ) ) {
            return self::get_fresh_defaults();
        }

        $is_legacy = empty( $options['settings_version'] );
        $defaults = $is_legacy ? self::get_legacy_defaults() : self::get_fresh_defaults();

        $valid_modes = array( 'auto', 'every_time', 'every_period', 'never' );

        if ( isset( $options['clear_cache_automatically'] ) && in_array( $options['clear_cache_automatically'], $valid_modes, true ) ) {
            $clear_cache_automatically = $options['clear_cache_automatically'];
        } else {
            $clear_cache_automatically = $defaults['clear_cache_automatically'];
        }

        if ( isset( $options['clear_cache_automatically_minutes'] ) ) {
            $clear_cache_automatically_minutes = intval( $options['clear_cache_automatically_minutes'] );
            $clear_cache_automatically_minutes = min( $clear_cache_automatically_minutes, 99999 );
            $clear_cache_automatically_minutes = max( $clear_cache_automatically_minutes, 1 );
        } else {
            $clear_cache_automatically_minutes = $defaults['clear_cache_automatically_minutes'];
        }

        $exclusions = isset( $options['exclusions'] ) ? $this->sanitize_exclusions( $options['exclusions'] ) : $defaults['exclusions'];

        // A form submission carries every checkbox state (unchecked = absent = false),
        // while a DB array from an older version simply doesn't know the new keys —
        // there the defaults apply.
        $is_form = isset( $options['_form'] );

        $filtered = array(
            'settings_version' => 3,
            'clear_cache_automatically' => $clear_cache_automatically,
            'clear_cache_automatically_minutes' => $clear_cache_automatically_minutes,
            'exclusions' => $exclusions,
        );

        foreach ( array( 'assets', 'version_external', 'admin_area', 'media_versions', 'html_freshness', 'show_on_toolbar' ) as $key ) {
            if ( isset( $options[ $key ] ) ) {
                $filtered[ $key ] = (bool) $options[ $key ];
            } elseif ( $is_form ) {
                $filtered[ $key ] = false;
            } else {
                $filtered[ $key ] = $defaults[ $key ];
            }
        }

        // Saving the settings form means the user has seen the new interface —
        // stop showing the "enable recommended settings" banner.
        if ( $is_form ) {
            $filtered['legacy_defaults'] = false;
        } else {
            $filtered['legacy_defaults'] = isset( $options['legacy_defaults'] ) ? (bool) $options['legacy_defaults'] : $defaults['legacy_defaults'];
        }

        return $filtered;
    }

    /**
     * Normalize the exclusions textarea value: one pattern per line.
     *
     * @param string $value
     * @return string
     */
    public function sanitize_exclusions( $value )
    {
        $lines = preg_split( '/[\r\n]+/', (string) $value );
        $clean = array();

        foreach ( $lines as $line ) {
            $line = trim( sanitize_text_field( $line ) );

            if ( '' !== $line ) {
                $clean[] = $line;
            }
        }

        return implode( "\n", $clean );
    }

    /**
     * Get value of prevent_browser_caching_options option.
     */
    public function get_options()
    {
        if ( empty( $this->options ) ) {
            $this->options = $this->filter_options( get_option('prevent_browser_caching_options') );
        }

        return $this->options;
    }

    /**
     * Get values of prevent_browser_caching_clear_cache_time option.
     */
    public function get_clear_cache_time()
    {
        if ( ! $this->clear_cache_time ) {
            $this->clear_cache_time = intval( get_option('prevent_browser_caching_clear_cache_time') );
        }

        return $this->clear_cache_time;
    }

    /**
     * Get value of prevent_browser_caching_media_time option (site-wide media version).
     *
     * @return int
     */
    public function get_media_time()
    {
        return intval( get_option( 'prevent_browser_caching_media_time' ) );
    }

    /**
     * Adds query parameters to CSS and JS files.
     *
     * @param string $src
     * @param string $handle
     * @return string
     */
    public function add_query_arg( $src, $handle = '' )
    {
        if ( $this->should_skip_src( $src, $handle ) ) {
            return $src;
        }

        $options = $this->get_options();

        if ( 'auto' === $options['clear_cache_automatically'] ) {
            // Version = the file's own modification time, so browsers keep the
            // cached copy until the file actually changes. A manual "Update"
            // (toolbar/settings button) still wins if pressed later.
            $ver = max( $this->get_file_mtime( $src ), $this->get_clear_cache_time() );
        } else {
            $ver = $this->time_query_arg;
        }

        $ver = apply_filters( 'pbc_assets_version', $ver, $src, $handle );

        if ( $ver ) {
            $src = $this->append_time_to_ver( $src, $ver );
        }

        return $src;
    }

    /**
     * Whether the plugin should leave this src untouched (external URL or exclusion).
     *
     * @param string $src
     * @param string $handle
     * @return bool
     */
    public function should_skip_src( $src, $handle = '' )
    {
        $options = $this->get_options();

        $skip = false;

        if ( ! $options['version_external'] && ! $this->is_local_src( $src ) ) {
            $skip = true;
        }

        if ( ! $skip && $this->is_excluded_src( $src, $handle ) ) {
            $skip = true;
        }

        return (bool) apply_filters( 'pbc_skip_src', $skip, $src, $handle );
    }

    /**
     * Whether the src points to this site (or its content/CDN host) rather than
     * a third-party service.
     *
     * @param string $src
     * @return bool
     */
    public function is_local_src( $src )
    {
        $src_host = wp_parse_url( $src, PHP_URL_HOST );

        if ( empty( $src_host ) ) {
            return true; // Relative URL.
        }

        $src_host = strtolower( $src_host );

        foreach ( array( home_url(), site_url(), content_url() ) as $base ) {
            $base_host = wp_parse_url( $base, PHP_URL_HOST );

            if ( $base_host && strtolower( $base_host ) === $src_host ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the src or handle matches a line of the exclusions option.
     *
     * @param string $src
     * @param string $handle
     * @return bool
     */
    public function is_excluded_src( $src, $handle = '' )
    {
        $options = $this->get_options();

        if ( '' === $options['exclusions'] ) {
            return false;
        }

        foreach ( explode( "\n", $options['exclusions'] ) as $line ) {
            if ( '' === $line ) {
                continue;
            }

            if ( $line === $handle || false !== stripos( $src, $line ) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Modification time of the local file behind the src, or 0 when unresolvable.
     *
     * @param string $src
     * @return int
     */
    public function get_file_mtime( $src )
    {
        if ( isset( $this->mtime_cache[ $src ] ) ) {
            return $this->mtime_cache[ $src ];
        }

        $mtime = 0;
        $path = $this->src_to_path( $src );

        if ( $path && false === strpos( $path, '..' ) && is_file( $path ) ) {
            $filemtime = @filemtime( $path );

            if ( $filemtime ) {
                $mtime = $filemtime;
            }
        }

        $this->mtime_cache[ $src ] = $mtime;

        return $mtime;
    }

    /**
     * Map a local asset URL to its filesystem path. Returns '' when the URL
     * doesn't belong to a known local base.
     *
     * @param string $src
     * @return string
     */
    public function src_to_path( $src )
    {
        list( $src ) = explode( '#', $src, 2 );
        list( $src ) = explode( '?', $src, 2 );

        if ( '' === $src ) {
            return '';
        }

        // Make relative URLs absolute so they match the bases below.
        if ( 0 === strpos( $src, '//' ) ) {
            $src = 'http:' . $src;
        } elseif ( 0 === strpos( $src, '/' ) ) {
            $site = wp_parse_url( site_url() );

            if ( empty( $site['host'] ) ) {
                return '';
            }

            $src = 'http://' . $site['host'] . ( isset( $site['port'] ) ? ':' . $site['port'] : '' ) . $src;
        }

        $src = set_url_scheme( $src, 'http' );

        $bases = array(
            array( content_url(), WP_CONTENT_DIR ),
            array( includes_url(), ABSPATH . WPINC ),
            array( site_url(), ABSPATH ),
        );

        foreach ( $bases as $base ) {
            $base_url = set_url_scheme( untrailingslashit( $base[0] ), 'http' );

            if ( 0 === strpos( $src, $base_url . '/' ) ) {
                $relative = substr( $src, strlen( $base_url ) );

                return wp_normalize_path( untrailingslashit( $base[1] ) . $relative );
            }
        }

        return '';
    }

    /**
     * Version for a specific attachment: its last modification time, or the
     * site-wide media time if a manual "Update" happened later.
     *
     * @param int $attachment_id
     * @return int
     */
    public function get_attachment_version( $attachment_id )
    {
        $version = $this->get_media_time();

        $attachment = get_post( $attachment_id );

        if ( $attachment && ! empty( $attachment->post_modified_gmt ) ) {
            $modified = strtotime( $attachment->post_modified_gmt . ' +0000' );

            if ( $modified ) {
                $version = max( $version, $modified );
            }
        }

        return $version;
    }

    /**
     * Whether media query args may be added in the current request context.
     * Never touch URLs handed to editors/APIs — only rendered front-end output.
     *
     * @return bool
     */
    protected function is_media_versioning_context()
    {
        if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
            return false;
        }

        if ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) {
            return false;
        }

        return true;
    }

    /**
     * Adds the "ver" query param to attachment URLs on the front end.
     *
     * @param string $url
     * @param int $attachment_id
     * @return string
     */
    public function add_media_query_arg_to_attachment_url( $url, $attachment_id )
    {
        if ( ! $this->is_media_versioning_context() || $this->is_excluded_src( $url ) ) {
            return $url;
        }

        $version = $this->get_attachment_version( $attachment_id );

        if ( $version ) {
            $url = $this->append_time_to_ver( $url, $version );
        }

        return $url;
    }

    /**
     * Adds the "ver" query param to intermediate-size image URLs.
     * image_downsize() builds them by replacing the file name in the attachment
     * URL, which strips the query string added by the wp_get_attachment_url
     * filter — so sized <img> src URLs need their own pass.
     *
     * @param array|false $image
     * @param int $attachment_id
     * @return array|false
     */
    public function add_media_query_arg_to_image_src( $image, $attachment_id )
    {
        if ( ! is_array( $image ) || empty( $image[0] ) || ! $this->is_media_versioning_context() ) {
            return $image;
        }

        if ( false !== strpos( $image[0], 'ver=' ) || $this->is_excluded_src( $image[0] ) ) {
            return $image;
        }

        $version = $this->get_attachment_version( $attachment_id );

        if ( $version ) {
            $image[0] = $this->append_time_to_ver( $image[0], $version );
        }

        return $image;
    }

    /**
     * Adds the "ver" query param to responsive image srcset URLs.
     *
     * @param array $sources
     * @param array $size_array
     * @param string $image_src
     * @param array $image_meta
     * @param int $attachment_id
     * @return array
     */
    public function add_media_query_arg_to_srcset( $sources, $size_array, $image_src, $image_meta, $attachment_id )
    {
        if ( ! is_array( $sources ) || ! $this->is_media_versioning_context() ) {
            return $sources;
        }

        $version = $this->get_attachment_version( $attachment_id );

        if ( ! $version ) {
            return $sources;
        }

        foreach ( $sources as $key => $source ) {
            if ( isset( $source['url'] ) && false === strpos( $source['url'], 'ver=' ) && ! $this->is_excluded_src( $source['url'] ) ) {
                $sources[ $key ]['url'] = $this->append_time_to_ver( $source['url'], $version );
            }
        }

        return $sources;
    }

    /**
     * Adds the "ver" query param to media URLs hardcoded in post content
     * (classic images, galleries, inline backgrounds). Uses the site-wide
     * media time, so it only kicks in after the first manual/automatic bump.
     *
     * @param string $content
     * @return string
     */
    public function add_media_query_arg_to_content( $content )
    {
        $media_time = $this->get_media_time();

        if ( ! $media_time || ! is_string( $content ) || '' === $content || ! $this->is_media_versioning_context() ) {
            return $content;
        }

        $uploads = wp_get_upload_dir();
        $base_path = wp_parse_url( $uploads['baseurl'], PHP_URL_PATH );

        if ( empty( $base_path ) ) {
            return $content;
        }

        if ( false === strpos( $content, $base_path ) ) {
            return $content;
        }

        $extensions = 'jpe?g|png|gif|webp|avif|svg|bmp|ico|mp4|webm|ogv|mp3|m4a|ogg|wav|pdf';

        $pattern = '#(?:(?:https?:)?//[^/"\'\s<>]+)?' . preg_quote( $base_path, '#' )
            . '/[^\s"\'<>\\\\)\\]]+?\.(?:' . $extensions . ')(\?[^\s"\'<>\\\\)\\]]*)?#i';

        return preg_replace_callback( $pattern, array( $this, 'append_media_time_to_content_url' ), $content );
    }

    /**
     * preg_replace_callback helper for add_media_query_arg_to_content().
     *
     * @param array $matches
     * @return string
     */
    public function append_media_time_to_content_url( $matches )
    {
        $url = $matches[0];
        $query = isset( $matches[1] ) ? $matches[1] : '';

        if ( '' !== $query ) {
            // Leave URLs that already carry a version or any query string:
            // dynamically rendered attachment URLs are versioned upstream.
            return $url;
        }

        if ( $this->is_excluded_src( $url ) ) {
            return $url;
        }

        return $url . '?ver=' . $this->get_media_time();
    }

    /**
     * Bumps the site-wide media time when an existing attachment is edited or
     * replaced (Enable Media Replace etc.). Fresh uploads don't bump: their
     * URLs are new, so invalidating every image on the site would only hurt.
     *
     * @param array $data
     * @param int $attachment_id
     * @return array
     */
    public function maybe_bump_media_time( $data, $attachment_id )
    {
        $attachment = get_post( $attachment_id );

        if ( $attachment && ! empty( $attachment->post_date_gmt ) ) {
            $created = strtotime( $attachment->post_date_gmt . ' +0000' );

            if ( $created && $this->get_time_code() - $created > MINUTE_IN_SECONDS ) {
                update_option( 'prevent_browser_caching_media_time', $this->get_time_code() );
            }
        }

        return $data;
    }

    /**
     * Detects an active page-cache plugin, so HTML freshness headers don't
     * fight a server-side cache.
     *
     * @static
     * @return string Plugin name, or '' when none detected.
     */
    public static function get_active_page_cache_plugin()
    {
        $known = array(
            'WP Rocket' => defined( 'WP_ROCKET_VERSION' ),
            'LiteSpeed Cache' => defined( 'LSCWP_V' ),
            'W3 Total Cache' => defined( 'W3TC' ),
            'WP Super Cache' => function_exists( 'wp_cache_serve_cache_file' ) || defined( 'WPCACHEHOME' ),
            'WP Fastest Cache' => class_exists( 'WpFastestCache' ),
            'WP-Optimize' => class_exists( 'WP_Optimize' ),
            'Breeze' => defined( 'BREEZE_VERSION' ),
            'Cache Enabler' => class_exists( 'Cache_Enabler' ),
            'Hummingbird' => class_exists( 'Hummingbird\\WP_Hummingbird' ) || class_exists( 'WP_Hummingbird' ),
            'SiteGround Optimizer' => defined( 'SiteGround_Optimizer\\VERSION' ),
            'Swift Performance' => class_exists( 'Swift_Performance' ) || class_exists( 'Swift_Performance_Lite' ),
            'Comet Cache' => class_exists( 'comet_cache' ),
        );

        foreach ( $known as $name => $active ) {
            if ( $active ) {
                return $name;
            }
        }

        return '';
    }

    /**
     * Asks browsers to revalidate cached HTML before showing it.
     * WP core already sends stronger no-cache headers for logged-in users.
     */
    public function send_html_freshness_header()
    {
        if ( headers_sent() || is_user_logged_in() ) {
            return;
        }

        if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
            return;
        }

        header( 'Cache-Control: no-cache' );
    }

    /**
     * Reloads pages restored from the back/forward cache when the snapshot is
     * older than 5 minutes — Cache-Control can't reach bfcache restores.
     */
    public function print_bfcache_guard_script()
    {
        ?>
        <script id="pbc-bfcache-guard">
            ( function() {
                var pbcLoadedAt = Date.now();
                window.addEventListener( 'pageshow', function( event ) {
                    if ( event.persisted && Date.now() - pbcLoadedAt > 300000 ) {
                        window.location.reload();
                    }
                } );
            } )();
        </script>
        <?php
    }

    /**
     * Appends the time to the "ver" query param, keeping the rest of the URL untouched.
     * Parsing and rebuilding the query string (e.g. with add_query_arg) would collapse
     * repeated params, such as the "family" params in a Google Fonts URL.
     *
     * @param string $src
     * @param int|string $time
     * @return string
     */
    public function append_time_to_ver( $src, $time )
    {
        $fragment = '';

        if ( false !== ( $fragment_pos = strpos( $src, '#' ) ) ) {
            $fragment = substr( $src, $fragment_pos );
            $src = substr( $src, 0, $fragment_pos );
        }

        $src_parts = explode( '?', $src, 2 );

        if ( ! isset( $src_parts[1] ) || $src_parts[1] === '' ) {
            return $src_parts[0] . '?ver=' . $time . $fragment;
        }

        $pairs = explode( '&', $src_parts[1] );
        $found = false;

        foreach ( $pairs as $i => $pair ) {
            if ( 'ver' === $pair || 0 === strpos( $pair, 'ver=' ) ) {
                $pairs[ $i ] = ( 'ver' === $pair ? 'ver=' : $pair ) . '.' . $time;
                $found = true;
                break;
            }
        }

        if ( ! $found ) {
            $pairs[] = 'ver=' . $time;
        }

        return $src_parts[0] . '?' . implode( '&', $pairs ) . $fragment;
    }

    /**
     * Get the current page url.
     *
     * @return string
     */
    public function get_current_url() {
        $is_https = strpos( site_url(), 'https://' ) === 0;

        $host = isset( $_SERVER['HTTP_HOST'] ) ? wp_unslash( $_SERVER['HTTP_HOST'] ) : '';
        $request_uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '';

        return esc_url_raw( ( $is_https ? 'https' : 'http' ) . '://' . $host . $request_uri );
    }

    /**
     * Adds item(s) to the toolbar.
     *
     * @param WP_Admin_Bar $wp_admin_bar
     */
    public function admin_bar_menu( $wp_admin_bar ) {
        $current_url = $this->get_current_url();

        $update_url = add_query_arg( 'pbc_update_css_js', wp_create_nonce( 'pbc_update_css_js' ), $current_url );

        $wp_admin_bar->add_menu(
            array(
                'id' => 'pbc_update_css_js',
                'title' => __( 'Update versions', 'prevent-browser-caching' ),
                'parent' => false,
                'href' => $update_url,
                'group' => false,
                'meta' => array(),
            )
        );
    }

    /**
     * Update CSS and JS files using toolbar button.
     */
    public function update_css_js() {
        if ( ! isset( $_GET['pbc_update_css_js'] ) ) {
            return;
        }

        if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['pbc_update_css_js'] ) ), 'pbc_update_css_js') ) {
            return;
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $this->bump_versions();

        $current_url = $this->get_current_url();
        $redirect_url = remove_query_arg( 'pbc_update_css_js', $current_url );

        wp_safe_redirect( $redirect_url );
        exit;
    }

    /**
     * Bumps the site-wide assets version, and the media version when media
     * versioning is on. Used by the toolbar button and the settings page.
     */
    public function bump_versions() {
        $time = $this->get_time_code();

        update_option( 'prevent_browser_caching_clear_cache_time', $time );

        $options = $this->get_options();

        if ( $options['media_versions'] ) {
            update_option( 'prevent_browser_caching_media_time', $time );
        }
    }

    /**
     * Get the current time number.
     *
     * @return int
     */
    public function get_time_code() {
        return time();
    }

}

Prevent_Browser_Caching::instance();
