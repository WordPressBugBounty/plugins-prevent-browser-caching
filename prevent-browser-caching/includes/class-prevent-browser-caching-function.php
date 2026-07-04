<?php

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Prevent_Browser_Caching_Function
{
    /**
     * Single instance of the class.
     *
     * @var Prevent_Browser_Caching_Function
     */
    protected static $_instance = null;

    /**
     * The version of CSS and JS files.
     *
     * @var string
     */
    public $assets_version = '';

    /**
     * Prevent_Browser_Caching_Function instance.
     *
     * @static
     * @var array $args
     * @return Prevent_Browser_Caching_Function - Main instance
     */
    public static function instance( $args = array() )
    {
        if (is_null(self::$_instance)) {
            self::$_instance = new self();

            $default_args = array(
                'assets_version' => time()
            );
        } else {
            $default_args = array(
                'assets_version' => self::$_instance->assets_version
            );
        }

        self::$_instance->assets_version = isset( $args['assets_version'] ) ? $args['assets_version'] : $default_args['assets_version'];

        return self::$_instance;
    }

    /**
     * Prevent_Browser_Caching_Function constructor.
     * @param $assets_version
     */
    public function __construct()
    {
        add_filter( 'style_loader_src', array( $this, 'add_query_arg' ), 10000 );
        add_filter( 'script_loader_src', array( $this, 'add_query_arg' ), 10000 );
    }

    /**
     * Adds query parameters to CSS and JS files.
     * @param $src
     * @return string
     */
    public function add_query_arg( $src )
    {
        if ( $this->assets_version ) {
            $src = $this->set_ver_query_arg( $src, $this->assets_version );
        } else {
            $src = $this->remove_ver_query_arg( $src );
        }

        return $src;
    }

    /**
     * Sets the "ver" query param, keeping the rest of the URL untouched.
     * Parsing and rebuilding the query string (e.g. with add_query_arg) would collapse
     * repeated params, such as the "family" params in a Google Fonts URL.
     *
     * @param string $src
     * @param string $ver
     * @return string
     */
    public function set_ver_query_arg( $src, $ver )
    {
        $ver = urlencode( $ver );

        $fragment = '';

        if ( false !== ( $fragment_pos = strpos( $src, '#' ) ) ) {
            $fragment = substr( $src, $fragment_pos );
            $src = substr( $src, 0, $fragment_pos );
        }

        $src_parts = explode( '?', $src, 2 );

        if ( ! isset( $src_parts[1] ) || $src_parts[1] === '' ) {
            return $src_parts[0] . '?ver=' . $ver . $fragment;
        }

        $pairs = explode( '&', $src_parts[1] );
        $found = false;

        foreach ( $pairs as $i => $pair ) {
            if ( 'ver' === $pair || 0 === strpos( $pair, 'ver=' ) ) {
                $pairs[ $i ] = 'ver=' . $ver;
                $found = true;
                break;
            }
        }

        if ( ! $found ) {
            $pairs[] = 'ver=' . $ver;
        }

        return $src_parts[0] . '?' . implode( '&', $pairs ) . $fragment;
    }

    /**
     * Removes the "ver" query param, keeping the rest of the URL untouched.
     *
     * @param string $src
     * @return string
     */
    public function remove_ver_query_arg( $src )
    {
        $fragment = '';

        if ( false !== ( $fragment_pos = strpos( $src, '#' ) ) ) {
            $fragment = substr( $src, $fragment_pos );
            $src = substr( $src, 0, $fragment_pos );
        }

        $src_parts = explode( '?', $src, 2 );

        if ( ! isset( $src_parts[1] ) ) {
            return $src . $fragment;
        }

        $pairs = array();

        foreach ( explode( '&', $src_parts[1] ) as $pair ) {
            if ( 'ver' !== $pair && 0 !== strpos( $pair, 'ver=' ) ) {
                $pairs[] = $pair;
            }
        }

        return $src_parts[0] . ( $pairs ? '?' . implode( '&', $pairs ) : '' ) . $fragment;
    }

}