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
     * Whether the post-update auto-bump already ran in this request
     * (sequential single updates must produce one bump, not several).
     *
     * @var bool
     */
    protected $auto_bump_done = false;

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
                add_action( 'admin_notices', array( $this, 'maybe_print_bump_report' ) );
            } else {
                add_action( 'template_redirect', array( $this, 'update_css_js' ), 10000 );
                add_action( 'wp_footer', array( $this, 'maybe_print_bump_report' ), 10000 );
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

        if ( $options['auto_bump'] ) {
            add_action( 'upgrader_process_complete', array( $this, 'handle_upgrader_complete' ), 10000, 2 );
        }

        // Keeps the .htaccess cache-policy block in step with the settings,
        // whichever surface saved them (form, ajax, WP-CLI).
        add_action( 'update_option_prevent_browser_caching_options', array( $this, 'on_options_updated' ), 10, 2 );
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
            'purge_page_cache' => false,
            'cache_policy' => false,
            'auto_bump' => false,
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
            'purge_page_cache' => false,
            'cache_policy' => false,
            'auto_bump' => false,
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

        foreach ( array( 'assets', 'version_external', 'admin_area', 'media_versions', 'html_freshness', 'show_on_toolbar', 'purge_page_cache', 'cache_policy', 'auto_bump' ) as $key ) {
            if ( isset( $options[ $key ] ) ) {
                $filtered[ $key ] = (bool) $options[ $key ];
            } elseif ( $is_form ) {
                $filtered[ $key ] = false;
            } else {
                $filtered[ $key ] = $defaults[ $key ];
            }
        }

        // A one-year cache policy is only safe while CSS/JS URLs are versioned.
        if ( ! $filtered['assets'] ) {
            $filtered['cache_policy'] = false;
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
     * Machine-readable status of the plugin's freshness configuration.
     * Single source for `wp pbc status` and the `status` ability — the keys
     * are a stable developer contract, do not rename.
     *
     * @return array
     */
    public function get_status()
    {
        $options = $this->get_options();

        $cache_policy_state = '';

        if ( $options['cache_policy'] ) {
            self::require_cache_policy_class();
            $probe = Prevent_Browser_Caching_Cache_Policy::get_probe_result();
            $cache_policy_state = $probe['state'];
        }

        $last_auto_bump = get_option( 'prevent_browser_caching_last_auto_bump' );

        return array(
            'mode' => $options['clear_cache_automatically'],
            'assets' => (bool) $options['assets'],
            'media' => (bool) $options['media_versions'],
            'html' => (bool) $options['html_freshness'],
            'version_external' => (bool) $options['version_external'],
            'purge_page_cache' => (bool) $options['purge_page_cache'],
            'cache_policy' => (bool) $options['cache_policy'],
            'cache_policy_state' => $cache_policy_state,
            'auto_bump' => (bool) $options['auto_bump'],
            'last_auto_bump' => is_array( $last_auto_bump ) && isset( $last_auto_bump['time'] ) ? intval( $last_auto_bump['time'] ) : 0,
            'last_manual_update' => $this->get_clear_cache_time(),
            'media_time' => $this->get_media_time(),
            'page_cache_plugin' => self::get_active_page_cache_plugin(),
            'plugin_version' => defined( 'PREVENT_BROWSER_CACHING_VERSION' ) ? PREVENT_BROWSER_CACHING_VERSION : '',
        );
    }

    /**
     * Load the cache-policy class on demand (settings save, status, hooks).
     *
     * @static
     */
    public static function require_cache_policy_class()
    {
        if ( ! class_exists( 'Prevent_Browser_Caching_Cache_Policy' ) ) {
            include_once __DIR__ . '/class-prevent-browser-caching-cache-policy.php';
        }
    }

    /**
     * Runs whenever prevent_browser_caching_options is updated, from any
     * surface: refreshes the in-memory copy and syncs the .htaccess block.
     *
     * @param mixed $old_value
     * @param mixed $value
     */
    public function on_options_updated( $old_value, $value )
    {
        // Later reads in this request must see the new values.
        $this->options = array();

        self::require_cache_policy_class();

        Prevent_Browser_Caching_Cache_Policy::sync(
            is_array( $old_value ) ? $old_value : array(),
            $this->filter_options( $value )
        );
    }

    /**
     * Auto-bump after plugin/theme/core updates (incl. auto-updates), using
     * the least invalidation the current mode allows:
     * - "auto" mode: updated files already self-bust via their new mtimes, so
     *   only the page cache needs purging (its HTML still references old vers);
     * - other modes: bump the assets version and purge per the option.
     * The media version is never touched here — updates don't change uploads.
     *
     * @param WP_Upgrader $upgrader
     * @param array $hook_extra
     */
    public function handle_upgrader_complete( $upgrader, $hook_extra )
    {
        if ( $this->auto_bump_done || ! is_array( $hook_extra ) ) {
            return;
        }

        $action = isset( $hook_extra['action'] ) ? $hook_extra['action'] : '';
        $type = isset( $hook_extra['type'] ) ? $hook_extra['type'] : '';

        // Fresh installs create new URLs (nothing stale); translations don't
        // change assets at all.
        if ( 'update' !== $action || ! in_array( $type, array( 'plugin', 'theme', 'core' ), true ) ) {
            return;
        }

        $this->auto_bump_done = true;

        $options = $this->get_options();
        $time = $this->get_time_code();
        $bumped = false;

        if ( 'auto' !== $options['clear_cache_automatically'] ) {
            update_option( 'prevent_browser_caching_clear_cache_time', $time );
            $this->clear_cache_time = $time;
            $bumped = true;
        }

        $purge = array(
            'purged' => false,
            'plugin' => self::get_active_page_cache_plugin(),
            'reason' => 'page cache purge disabled',
        );

        if ( ! empty( $options['purge_page_cache'] )
            && apply_filters( 'pbc_purge_page_cache', true, $purge['plugin'] ) ) {

            if ( ! class_exists( 'Prevent_Browser_Caching_Integrations' ) ) {
                include_once __DIR__ . '/class-prevent-browser-caching-integrations.php';
            }

            $purge = Prevent_Browser_Caching_Integrations::purge_page_cache();
        }

        // Proof-of-life for the settings page ("Last automatic refresh: …").
        update_option( 'prevent_browser_caching_last_auto_bump', array( 'time' => $time, 'type' => $type ), false );

        /**
         * Fires after an automatic post-update refresh.
         *
         * @param array $context { 'type' => string, 'bumped' => bool, 'purge' => array }
         */
        do_action( 'pbc_after_auto_bump', array( 'type' => $type, 'bumped' => $bumped, 'purge' => $purge ) );
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
     * Never touch URLs handed to editors/APIs or syndicated in feeds — only
     * rendered front-end output.
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

        // Feed readers treat a changed URL as a changed item; a site-wide media
        // bump must not churn every subscriber's feed.
        if ( function_exists( 'is_feed' ) && is_feed() ) {
            return false;
        }

        return true;
    }

    /**
     * Whether the URL already carries a "ver" query parameter. Matches only a
     * real param boundary, so "server=" / "driver=" don't count as versioned.
     *
     * @param string $url
     * @return bool
     */
    protected function url_has_ver_arg( $url )
    {
        return (bool) preg_match( '/[?&]ver=/', (string) $url );
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

        if ( $this->url_has_ver_arg( $image[0] ) || $this->is_excluded_src( $image[0] ) ) {
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
            if ( isset( $source['url'] ) && ! $this->url_has_ver_arg( $source['url'] ) && ! $this->is_excluded_src( $source['url'] ) ) {
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
     * Detects a page-cache plugin whose page caching is actually switched on,
     * so HTML freshness headers don't fight a server-side cache.
     *
     * Presence alone is not enough: multi-purpose plugins (WP-Optimize,
     * W3 Total Cache, ...) are often installed with their page cache disabled.
     *
     * @static
     * @return string Plugin name, or '' when none detected.
     */
    public static function get_active_page_cache_plugin()
    {
        // The underlying plugin state cannot change mid-request; detection is
        // called from hooks setup, the settings page, purge and status.
        static $detected = null;

        if ( null !== $detected ) {
            return $detected;
        }

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

        $detected = '';

        foreach ( $known as $name => $active ) {
            if ( $active && self::is_page_cache_feature_enabled( $name ) ) {
                $detected = $name;
                break;
            }
        }

        return $detected;
    }

    /**
     * Whether the detected plugin's page-cache feature is switched on.
     *
     * Each check mirrors the host plugin's own "should I serve cache" gate:
     * the same option row and the same emptiness semantics (signals verified
     * against wp.org trunk sources, see docs/v3.2.1-planning). Indeterminate
     * state — a missing API, an unexpected value shape, an exception — fails
     * open to true, so the worst case is the pre-3.2.1 presence behavior and
     * PBC never fights a page cache that is actually serving.
     *
     * @static
     * @param string $plugin Name as listed in get_active_page_cache_plugin().
     * @return bool
     */
    private static function is_page_cache_feature_enabled( $plugin )
    {
        try {
            switch ( $plugin ) {
                case 'LiteSpeed Cache':
                    // Option 'litespeed.conf.cache'; 2 means multisite
                    // "use network setting" — treated as enabled (fail open).
                    $value = get_option( 'litespeed.conf.cache', null );
                    return null === $value || ! empty( $value );

                case 'W3 Total Cache':
                    if ( function_exists( 'w3tc_config' ) ) {
                        $config = w3tc_config();

                        if ( is_object( $config ) && method_exists( $config, 'get_boolean' ) ) {
                            return (bool) $config->get_boolean( 'pgcache.enabled' );
                        }
                    }
                    return true;

                case 'WP Super Cache':
                    // wp-cache-config.php is loaded by the plugin itself;
                    // "Caching Off" writes $cache_enabled = false.
                    if ( isset( $GLOBALS['cache_enabled'] ) ) {
                        return (bool) $GLOBALS['cache_enabled'];
                    }
                    return true;

                case 'WP Fastest Cache':
                    // Serves cache only when wpFastestCacheStatus exists in
                    // the settings JSON; no saved settings means no caching.
                    $wpfc = json_decode( (string) get_option( 'WpFastestCache', '' ) );
                    return is_object( $wpfc ) && isset( $wpfc->wpFastestCacheStatus );

                case 'WP-Optimize':
                    // Mirrors its advanced-cache.php gate; the default (and a
                    // never-configured cache) is disabled.
                    $config = is_multisite() ? get_site_option( 'wpo_cache_config', array() ) : get_option( 'wpo_cache_config', array() );
                    return is_array( $config ) && ! empty( $config['enable_page_caching'] );

                case 'Breeze':
                    $settings = get_option( 'breeze_basic_settings', null );

                    if ( ! is_array( $settings ) || ! array_key_exists( 'breeze-active', $settings ) ) {
                        return true; // Breeze defaults to active.
                    }
                    return ! empty( $settings['breeze-active'] );

                case 'Hummingbird':
                    $settings = get_option( 'wphb_settings', null );

                    if ( ! is_array( $settings ) || ! isset( $settings['page_cache']['enabled'] ) ) {
                        return true;
                    }
                    return ! empty( $settings['page_cache']['enabled'] );

                case 'SiteGround Optimizer':
                    // Dynamic (NGINX) cache and file-based cache are separate
                    // toggles; either one means a page cache is serving.
                    $dynamic = get_option( 'siteground_optimizer_enable_cache', null );
                    $file_cache = get_option( 'siteground_optimizer_file_caching', null );

                    if ( null === $dynamic && null === $file_cache ) {
                        return true;
                    }
                    return 1 === (int) $dynamic || 1 === (int) $file_cache;

                case 'Swift Performance':
                    $swift = get_option( 'swift_performance_options', null );

                    if ( ! is_array( $swift ) || ! array_key_exists( 'enable-caching', $swift ) ) {
                        return true;
                    }
                    return ! empty( $swift['enable-caching'] );

                case 'Comet Cache':
                    // Stored via update_site_option even on single site;
                    // the default is disabled ('enable' => '0').
                    $comet = get_site_option( 'comet_cache_options', null );

                    if ( ! is_array( $comet ) ) {
                        return true;
                    }
                    return ! empty( $comet['enable'] );
            }
        } catch ( \Throwable $e ) {
            return true;
        }

        // WP Rocket, Cache Enabler: page caching is the plugin's core
        // function — active means caching.
        return true;
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
                // A valueless "ver"/"ver=" takes the time directly — appending
                // would produce a stray leading dot (ver=.123).
                if ( 'ver' === $pair || 'ver=' === $pair ) {
                    $pairs[ $i ] = 'ver=' . $time;
                } else {
                    $pairs[ $i ] = $pair . '.' . $time;
                }
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

        $result = $this->bump_versions();

        // Stored per user and printed once on the page we redirect to,
        // so the click gets visible feedback (admin notice or front toast).
        set_transient( 'pbc_bump_report_' . get_current_user_id(), $this->describe_bump_result_parts( $result ), 2 * MINUTE_IN_SECONDS );

        $current_url = $this->get_current_url();
        $redirect_url = remove_query_arg( 'pbc_update_css_js', $current_url );

        wp_safe_redirect( $redirect_url );
        exit;
    }

    /**
     * Structured summary of a bump_versions() result for the UI report:
     * a success line (what got a new version, per the current options) and a
     * separate page-cache line with its own severity, so the report can show
     * the purge outcome on its own visually distinct row.
     *
     * @param array $result Return value of bump_versions().
     * @return array {
     *     @type string $versions    "New versions are set for …" success sentence.
     *     @type string $purge       Page-cache outcome sentence ('' when no plugin detected).
     *     @type string $purge_line  The purge sentence with its "✓ " / "Note: " / "Warning: " prefix.
     *     @type string $purge_state 'purged' | 'disabled' | 'skipped' | 'failed' | ''.
     * }
     */
    public function describe_bump_result_parts( $result )
    {
        $options = $this->get_options();

        if ( $options['assets'] && $options['media_versions'] ) {
            $versions = __( 'New versions are set for styles & scripts and images.', 'prevent-browser-caching' );
        } elseif ( $options['assets'] ) {
            $versions = __( 'New versions are set for styles & scripts.', 'prevent-browser-caching' );
        } elseif ( $options['media_versions'] ) {
            $versions = __( 'New versions are set for images.', 'prevent-browser-caching' );
        } else {
            $versions = __( 'Versions updated.', 'prevent-browser-caching' );
        }

        $parts = array(
            'versions' => $versions,
            'purge' => '',
            'purge_line' => '',
            'purge_state' => '',
        );

        $purge = isset( $result['purge'] ) && is_array( $result['purge'] ) ? $result['purge'] : array();
        $plugin = isset( $purge['plugin'] ) ? $purge['plugin'] : '';

        if ( '' === $plugin ) {
            return $parts;
        }

        $reason = isset( $purge['reason'] ) ? $purge['reason'] : '';

        if ( ! empty( $purge['purged'] ) ) {
            $parts['purge_state'] = 'purged';
            $parts['purge'] = sprintf(
                /* translators: %s: page cache plugin name. */
                __( 'The %s page cache was also cleared, so every visitor sees the changes immediately.', 'prevent-browser-caching' ),
                $plugin
            );
            $parts['purge_line'] = '✓ ' . $parts['purge'];
        } elseif ( 'page cache purge disabled' === $reason ) {
            $parts['purge_state'] = 'disabled';
            $parts['purge'] = sprintf(
                /* translators: %s: page cache plugin name. */
                __( 'The %s page cache was NOT cleared (the option is off), so visitors may keep seeing cached pages with the old versions until it expires.', 'prevent-browser-caching' ),
                $plugin
            );
            $parts['purge_line'] = __( 'Note:', 'prevent-browser-caching' ) . ' ' . $parts['purge'];
        } elseif ( 'page cache purge skipped' === $reason ) {
            $parts['purge_state'] = 'skipped';
            $parts['purge'] = sprintf(
                /* translators: %s: page cache plugin name. */
                __( 'The %s page cache was left untouched (purge skipped for this run).', 'prevent-browser-caching' ),
                $plugin
            );
            $parts['purge_line'] = __( 'Note:', 'prevent-browser-caching' ) . ' ' . $parts['purge'];
        } else {
            $parts['purge_state'] = 'failed';
            $parts['purge'] = sprintf(
                /* translators: %s: page cache plugin name. */
                __( 'The %s page cache could not be cleared — you may need to clear it manually.', 'prevent-browser-caching' ),
                $plugin
            );
            $parts['purge_line'] = __( 'Warning:', 'prevent-browser-caching' ) . ' ' . $parts['purge'];
        }

        return $parts;
    }

    /**
     * Flat one-string variant of describe_bump_result_parts(), for contexts
     * that can't render two lines.
     *
     * @param array $result Return value of bump_versions().
     * @return string
     */
    public function describe_bump_result( $result )
    {
        $parts = $this->describe_bump_result_parts( $result );

        return '' === $parts['purge'] ? $parts['versions'] : $parts['versions'] . ' ' . $parts['purge'];
    }

    /**
     * Text color for a purge_state of describe_bump_result_parts().
     *
     * @param string $state
     * @return string CSS color.
     */
    public static function bump_report_color( $state )
    {
        if ( 'purged' === $state ) {
            return '#00a32a';
        }

        if ( 'failed' === $state ) {
            return '#b32d2e';
        }

        return '#996800';
    }

    /**
     * Prints the one-time report stored by update_css_js(): a standard admin
     * notice in wp-admin, a small self-dismissing toast under the toolbar on
     * the front end. Shown only to the user who pressed the button.
     */
    public function maybe_print_bump_report()
    {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $key = 'pbc_bump_report_' . get_current_user_id();
        $report = get_transient( $key );

        if ( ! is_array( $report ) || empty( $report['versions'] ) ) {
            return;
        }

        delete_transient( $key );

        $purge_line = isset( $report['purge_line'] ) ? $report['purge_line'] : '';
        $purge_color = self::bump_report_color( isset( $report['purge_state'] ) ? $report['purge_state'] : '' );

        if ( is_admin() ) {
            ?>
            <div class="notice notice-success is-dismissible">
                <p><?php echo esc_html( '✓ ' . $report['versions'] ); ?></p>
                <?php if ( '' !== $purge_line ): ?>
                    <p style="color: <?php echo esc_attr( $purge_color ); ?>;"><?php echo esc_html( $purge_line ); ?></p>
                <?php endif; ?>
            </div>
            <?php
            return;
        }
        ?>
        <div id="pbc-bump-report" style="position: fixed; top: 40px; right: 12px; z-index: 99999; max-width: 380px; padding: 10px 14px; background: #fff; border-left: 4px solid #00a32a; box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15); color: #1d2327; font: 13px/1.5 -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;">
            <div style="color: #00a32a;"><?php echo esc_html( '✓ ' . $report['versions'] ); ?></div>
            <?php if ( '' !== $purge_line ): ?>
                <div style="margin-top: 6px; color: <?php echo esc_attr( $purge_color ); ?>;"><?php echo esc_html( $purge_line ); ?></div>
            <?php endif; ?>
        </div>
        <script id="pbc-bump-report-script">
            setTimeout( function() {
                var pbcReport = document.getElementById( 'pbc-bump-report' );
                if ( pbcReport && pbcReport.parentNode ) {
                    pbcReport.parentNode.removeChild( pbcReport );
                }
            }, 8000 );
        </script>
        <?php
    }

    /**
     * Bumps the site-wide assets version, and the media version when media
     * versioning is on, then purges the detected page cache when enabled.
     * Used by the toolbar button, the settings page, WP-CLI and the ability.
     *
     * @param bool $skip_purge Force-skip the page-cache purge for this call
     *                         (the settings option remains the master switch).
     * @return array {
     *     @type int   $time  The new version timestamp.
     *     @type array $purge Result of the page-cache purge (see
     *                        Prevent_Browser_Caching_Integrations::purge_page_cache()).
     * }
     */
    public function bump_versions( $skip_purge = false ) {
        $time = $this->get_time_code();

        update_option( 'prevent_browser_caching_clear_cache_time', $time );

        $options = $this->get_options();

        if ( $options['media_versions'] ) {
            update_option( 'prevent_browser_caching_media_time', $time );
        }

        // Bump first, purge second: a purge failure must never lose the bump.
        $purge = array(
            'purged' => false,
            'plugin' => self::get_active_page_cache_plugin(),
            'reason' => $skip_purge ? 'page cache purge skipped' : 'page cache purge disabled',
        );

        if ( ! $skip_purge
            && ! empty( $options['purge_page_cache'] )
            && apply_filters( 'pbc_purge_page_cache', true, $purge['plugin'] ) ) {

            if ( ! class_exists( 'Prevent_Browser_Caching_Integrations' ) ) {
                include_once __DIR__ . '/class-prevent-browser-caching-integrations.php';
            }

            $purge = Prevent_Browser_Caching_Integrations::purge_page_cache();
        }

        $result = array( 'time' => $time, 'purge' => $purge );

        /**
         * Fires after the versions are bumped (and the page cache purge attempted).
         *
         * @param array $result { 'time' => int, 'purge' => array }
         */
        do_action( 'pbc_after_bump', $result );

        return $result;
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
