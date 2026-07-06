<?php

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Registers the plugin's operations as WordPress Abilities, so AI agents (via
 * the core MCP adapter), the REST API and other tools can discover and call
 * them. Thin wrappers over Prevent_Browser_Caching — no business logic here.
 *
 * Only loaded when wp_register_ability() exists (WordPress 6.9+); a no-op
 * otherwise.
 *
 * @class Prevent_Browser_Caching_Abilities
 */
class Prevent_Browser_Caching_Abilities
{
    /**
     * Register all abilities. Called on wp_abilities_api_init.
     */
    public static function register()
    {
        wp_register_ability(
            'prevent-browser-caching/bump-versions',
            array(
                'label' => __( 'Update asset versions', 'prevent-browser-caching' ),
                'description' => __( "Makes every visitor's browser fetch the current CSS, JS and images instead of cached copies. Call this after changing styles, scripts or images on the site. Also clears the server page cache when that option is enabled, so cached HTML stops referencing old file versions.", 'prevent-browser-caching' ),
                'category' => 'site',
                'input_schema' => array(
                    'type' => 'object',
                    'properties' => array(
                        'purge' => array(
                            'type' => 'boolean',
                            'description' => __( 'Set false to skip the page cache purge.', 'prevent-browser-caching' ),
                            'default' => true,
                        ),
                    ),
                ),
                'output_schema' => array(
                    'type' => 'object',
                    'properties' => array(
                        'time' => array( 'type' => 'integer' ),
                        'purged' => array( 'type' => 'boolean' ),
                        'purged_plugin' => array( 'type' => 'string' ),
                        'reason' => array( 'type' => 'string' ),
                    ),
                ),
                'execute_callback' => array( __CLASS__, 'execute_bump_versions' ),
                'permission_callback' => array( __CLASS__, 'can_manage' ),
                'meta' => array(
                    'show_in_rest' => true,
                    'annotations' => array(
                        'destructive' => false,
                        'idempotent' => false,
                    ),
                ),
            )
        );

        wp_register_ability(
            'prevent-browser-caching/status',
            array(
                'label' => __( 'Get cache-busting status', 'prevent-browser-caching' ),
                'description' => __( 'Reports how the site keeps assets fresh: versioning mode, what is versioned (CSS/JS, images, HTML freshness), last manual update time, and which page cache plugin is detected. Read-only.', 'prevent-browser-caching' ),
                'category' => 'site',
                'output_schema' => array(
                    'type' => 'object',
                    'properties' => array(
                        'mode' => array( 'type' => 'string' ),
                        'assets' => array( 'type' => 'boolean' ),
                        'media' => array( 'type' => 'boolean' ),
                        'html' => array( 'type' => 'boolean' ),
                        'version_external' => array( 'type' => 'boolean' ),
                        'purge_page_cache' => array( 'type' => 'boolean' ),
                        'last_manual_update' => array( 'type' => 'integer' ),
                        'media_time' => array( 'type' => 'integer' ),
                        'page_cache_plugin' => array( 'type' => 'string' ),
                        'plugin_version' => array( 'type' => 'string' ),
                    ),
                ),
                'execute_callback' => array( __CLASS__, 'execute_status' ),
                'permission_callback' => array( __CLASS__, 'can_manage' ),
                'meta' => array(
                    'show_in_rest' => true,
                    'annotations' => array(
                        'readonly' => true,
                    ),
                ),
            )
        );
    }

    /**
     * Permission callback: only administrators may run or read plugin operations.
     *
     * @return bool
     */
    public static function can_manage()
    {
        return current_user_can( 'manage_options' );
    }

    /**
     * Execute callback for prevent-browser-caching/bump-versions.
     *
     * @param array $input Validated input.
     * @return array
     */
    public static function execute_bump_versions( $input = array() )
    {
        $skip_purge = isset( $input['purge'] ) ? ! (bool) $input['purge'] : false;

        $result = Prevent_Browser_Caching::instance()->bump_versions( $skip_purge );

        return array(
            'time' => (int) $result['time'],
            'purged' => (bool) $result['purge']['purged'],
            'purged_plugin' => (string) $result['purge']['plugin'],
            'reason' => (string) $result['purge']['reason'],
        );
    }

    /**
     * Execute callback for prevent-browser-caching/status.
     *
     * @return array
     */
    public static function execute_status()
    {
        return Prevent_Browser_Caching::instance()->get_status();
    }
}
