<?php

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Lists the plugin's abilities as first-class tools on the WordPress MCP
 * Adapter's default server.
 *
 * The adapter's default server starts with three meta-tools only
 * (discover-abilities, get-ability-info, execute-ability) and never adds
 * ability tools by itself: an agent has to call discover-abilities and read
 * a list before it knows the plugin exists, and most never do. Through the
 * adapter's own `mcp_adapter_default_server_config` filter this class asks
 * the default server to also list `prevent-browser-caching/bump-versions`
 * and `prevent-browser-caching/status` as tools (named
 * `prevent-browser-caching-bump-versions` and
 * `prevent-browser-caching-status`) with the abilities' own label,
 * description, input schema and annotations. The server description is left
 * alone: the two descriptions already say when to call what.
 *
 * Nothing here executes or authorizes anything: a tool call still runs the
 * ability's permission callback (manage_options) and execute callback
 * exactly as a REST call does, and the adapter re-checks `meta.mcp.public`
 * for every listed name. Other plugins use the same filter, so existing
 * items are kept and only missing names are appended; because a callback is
 * allowed to return just its overrides, the adapter's defaults are
 * remembered at the earliest priority and merged at the latest.
 *
 * Only loaded when an adapter fires the filter (see prevent-browser-caching.php).
 * Verified against MCP Adapter 0.7.0 and the copy bundled with WooCommerce.
 *
 * @since 3.2.2
 * @class Prevent_Browser_Caching_MCP
 */
class Prevent_Browser_Caching_MCP
{
    /**
     * The adapter's default-server configuration filter (since adapter 0.3.0).
     */
    const CONFIG_FILTER = 'mcp_adapter_default_server_config';

    /**
     * Name prefix of this plugin's abilities.
     */
    const ABILITY_PREFIX = 'prevent-browser-caching/';

    /**
     * The adapter's defaults as handed to the earliest filter callback.
     *
     * @var array|null
     */
    protected static $defaults = null;

    /**
     * Filter callback (earliest priority): keeps a copy of the defaults.
     *
     * @param mixed $config
     * @return mixed Unchanged.
     */
    public static function remember_defaults( $config )
    {
        if ( is_array( $config ) ) {
            self::$defaults = $config;
        }

        return $config;
    }

    /**
     * Filter callback (latest priority): appends the plugin's MCP-public
     * abilities to the default server's tool list. Appends, never
     * overwrites; every other key is left as received.
     *
     * @param mixed $config
     * @return mixed
     */
    public static function extend_config( $config )
    {
        if ( ! is_array( $config ) ) {
            return $config;
        }

        /**
         * Whether the plugin's abilities are listed as first-class tools on
         * the MCP Adapter's default server (default true). False keeps a
         * site on the adapter's meta-tools only; the abilities stay
         * reachable through execute-ability.
         *
         * @since 3.2.2
         *
         * @param bool $direct
         */
        if ( ! apply_filters( 'prevent_browser_caching_mcp_direct_tools', true ) ) {
            return $config;
        }

        $names = self::tool_names();
        if ( array() === $names ) {
            return $config;
        }

        $tools = isset( $config['tools'] ) && is_array( $config['tools'] )
            ? $config['tools']
            : self::default_item( 'tools', array() );
        $config['tools'] = self::merge_names( $tools, $names );

        return $config;
    }

    /**
     * The registered `prevent-browser-caching/*` abilities the adapter may
     * expose, read from the live registry with the adapter's own rule
     * (meta.mcp.public when set, else meta.public).
     *
     * @return string[] Ability names, registration order.
     */
    public static function tool_names()
    {
        if ( ! function_exists( 'wp_get_abilities' ) ) {
            return array();
        }

        $names = array();
        foreach ( (array) wp_get_abilities() as $ability ) {
            if ( ! is_object( $ability ) || ! method_exists( $ability, 'get_name' ) || ! method_exists( $ability, 'get_meta' ) ) {
                continue;
            }
            $name = (string) $ability->get_name();
            if ( 0 !== strpos( $name, self::ABILITY_PREFIX ) ) {
                continue;
            }
            if ( ! self::is_meta_public( $ability->get_meta() ) ) {
                continue;
            }
            $names[] = $name;
        }

        return $names;
    }

    /**
     * Mirror of the adapter's exposure rule (McpAbilityExposure::is_meta_public):
     * `mcp.public` wins when set, else `public`; anything malformed fails closed.
     *
     * @param mixed $meta
     * @return bool
     */
    public static function is_meta_public( $meta )
    {
        if ( ! is_array( $meta ) ) {
            return false;
        }
        $mcp = isset( $meta['mcp'] ) ? $meta['mcp'] : array();
        if ( ! is_array( $mcp ) ) {
            return false;
        }
        if ( isset( $mcp['public'] ) ) {
            return (bool) $mcp['public'];
        }

        return isset( $meta['public'] ) && true === $meta['public'];
    }

    /**
     * Appends ability names not already listed. Existing items are kept as
     * they are (the adapter also accepts tool objects there).
     *
     * @param array    $tools
     * @param string[] $names
     * @return array
     */
    public static function merge_names( $tools, $names )
    {
        $tools = array_values( (array) $tools );
        foreach ( $names as $name ) {
            if ( ! in_array( $name, $tools, true ) ) {
                $tools[] = $name;
            }
        }

        return $tools;
    }

    /**
     * One remembered default, or the fallback when the filter never passed
     * through remember_defaults().
     *
     * @param string $key
     * @param mixed  $fallback
     * @return mixed
     */
    protected static function default_item( $key, $fallback )
    {
        return is_array( self::$defaults ) && isset( self::$defaults[ $key ] ) ? self::$defaults[ $key ] : $fallback;
    }

    /**
     * Test hook: forgets the remembered defaults.
     */
    public static function reset()
    {
        self::$defaults = null;
    }
}
