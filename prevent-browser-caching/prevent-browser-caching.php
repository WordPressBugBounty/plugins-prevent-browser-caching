<?php
/**
 * Plugin Name: Prevent Browser Caching
 * Description: Prevents browser cache problems: visitors always get the current version of your CSS, JS, images and pages, while caching keeps working.
 * Version: 3.2.0
 * Requires at least: 4.7
 * Requires PHP: 7.2
 * Author: Kostya Tereshchuk
 * Author URI: https://tutori.org/kostya/
 * License: GPLv2 or later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain: prevent-browser-caching
 * Domain Path: /lang/
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! defined( 'PREVENT_BROWSER_CACHING_VERSION' ) ) {
    define( 'PREVENT_BROWSER_CACHING_VERSION', '3.2.0' );
}

if ( defined( 'WP_CLI' ) && WP_CLI ) {
    include_once dirname( __FILE__ ) . '/includes/class-prevent-browser-caching-cli.php';
}

add_action( 'wp_abilities_api_init', 'prevent_browser_caching_register_abilities' );

if ( ! function_exists( 'prevent_browser_caching_register_abilities' ) ) {
    /**
     * Register the plugin's abilities (no-op on WordPress without the Abilities API).
     */
    function prevent_browser_caching_register_abilities()
    {
        if ( ! function_exists( 'wp_register_ability' ) ) {
            return;
        }

        include_once dirname( __FILE__ ) . '/includes/class-prevent-browser-caching-abilities.php';

        Prevent_Browser_Caching_Abilities::register();
    }
}

if ( ! function_exists( 'prevent_browser_caching_plugin_actions' ) ) {
    /**
     * Add settings to plugin links.
     * @param $actions
     * @return mixed
     */
    function prevent_browser_caching_plugin_actions( $actions )
    {
        array_unshift( $actions, "<a href=\"" . esc_url( menu_page_url( 'prevent-browser-caching', false ) ) . "\">" . esc_html__( "Settings", "prevent-browser-caching" ) . "</a>" );
        return $actions;
    }
    add_filter( 'plugin_action_links_' . plugin_basename(__FILE__), 'prevent_browser_caching_plugin_actions', 10, 1 );
}

if ( ! function_exists( 'prevent_browser_caching_load_textdomain' ) ) {
    /**
     * Set languages directory.
     */
    function prevent_browser_caching_load_textdomain()
    {
        load_plugin_textdomain( 'prevent-browser-caching', false, dirname( plugin_basename( __FILE__ ) ) . '/lang/' );
    }
    add_action( 'plugins_loaded', 'prevent_browser_caching_load_textdomain' );
}

if ( ! function_exists( 'prevent_browser_caching' ) ) {
    /**
     * Changes the version of CSS and JS files.
     * Disables loading Prevent_Browser_Caching class if this function is used before setup theme.
     *
     * @param array $args
     */
    function prevent_browser_caching( $args = array() ) {
        if ( ! class_exists( 'Prevent_Browser_Caching_Function' ) ) {
            include_once 'includes/class-prevent-browser-caching-function.php';
        }

        Prevent_Browser_Caching_Function::instance( $args );
    }
}

if ( ! function_exists( 'maybe_load_class_prevent_browser_caching' ) ) {
    /**
     * Load Prevent_Browser_Caching class if the function prevent_browser_caching is not used before setup theme.
     */
    function maybe_load_class_prevent_browser_caching()
    {
        if ( ! class_exists( 'Prevent_Browser_Caching' ) && ! class_exists( 'Prevent_Browser_Caching_Function' ) ) {
            include_once 'includes/class-prevent-browser-caching.php';
        }
    }

    add_action( 'after_setup_theme', 'maybe_load_class_prevent_browser_caching' );
}

if ( ! function_exists( 'prevent_browser_caching_activate' ) ) {
    /**
     * On activation: store the recommended defaults for fresh installs (an existing
     * option is never overwritten) and remember to open the settings page.
     */
    function prevent_browser_caching_activate( $network_wide = false )
    {
        if ( ! class_exists( 'Prevent_Browser_Caching' ) ) {
            include_once dirname( __FILE__ ) . '/includes/class-prevent-browser-caching.php';
        }

        add_option( 'prevent_browser_caching_options', Prevent_Browser_Caching::get_fresh_defaults() );

        // Fresh installs never see the "what's new" notice; upgraded sites
        // (option row already exists, no seen-version yet) see it once.
        add_option( 'prevent_browser_caching_seen_version', PREVENT_BROWSER_CACHING_VERSION, '', false );

        // A re-activation with the cache-policy option on must restore the
        // .htaccess block that deactivation removed.
        $pbc_existing = get_option( 'prevent_browser_caching_options' );

        if ( is_array( $pbc_existing ) && ! empty( $pbc_existing['cache_policy'] ) ) {
            Prevent_Browser_Caching::require_cache_policy_class();
            Prevent_Browser_Caching_Cache_Policy::sync( array(), Prevent_Browser_Caching::instance()->filter_options( $pbc_existing ) );
        }

        if ( ! $network_wide ) {
            set_transient( 'pbc_activation_redirect', 1, MINUTE_IN_SECONDS );
        }
    }
    register_activation_hook( __FILE__, 'prevent_browser_caching_activate' );
}

if ( ! function_exists( 'prevent_browser_caching_deactivate' ) ) {
    /**
     * On deactivation: a disabled plugin must leave no server-config footprint,
     * so the .htaccess cache-policy block is removed (options are kept).
     */
    function prevent_browser_caching_deactivate()
    {
        if ( ! class_exists( 'Prevent_Browser_Caching' ) ) {
            include_once dirname( __FILE__ ) . '/includes/class-prevent-browser-caching.php';
        }

        Prevent_Browser_Caching::require_cache_policy_class();

        Prevent_Browser_Caching_Cache_Policy::remove_rules();
        delete_option( Prevent_Browser_Caching_Cache_Policy::PROBE_OPTION );
    }
    register_deactivation_hook( __FILE__, 'prevent_browser_caching_deactivate' );
}

if ( ! function_exists( 'prevent_browser_caching_activation_redirect' ) ) {
    /**
     * Open the settings page right after a single-plugin activation.
     */
    function prevent_browser_caching_activation_redirect()
    {
        if ( ! get_transient( 'pbc_activation_redirect' ) ) {
            return;
        }

        delete_transient( 'pbc_activation_redirect' );

        if ( isset( $_GET['activate-multi'] ) || wp_doing_ajax() || ! current_user_can( 'manage_options' ) ) {
            return;
        }

        wp_safe_redirect( admin_url( 'options-general.php?page=prevent-browser-caching' ) );
        exit;
    }
    add_action( 'admin_init', 'prevent_browser_caching_activation_redirect' );
}

if ( is_admin() ) {
    include_once 'includes/admin/class-prevent-browser-caching-admin-settings.php';
}
