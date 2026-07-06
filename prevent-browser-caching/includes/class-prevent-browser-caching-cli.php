<?php

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
    return;
}

/**
 * Manage Prevent Browser Caching from the command line.
 *
 * Thin wrappers over Prevent_Browser_Caching — no business logic lives here.
 */
class Prevent_Browser_Caching_CLI
{
    /**
     * Bump the asset (and media) versions so every visitor fetches fresh files,
     * and purge the detected page cache when the settings option is on.
     *
     * ## OPTIONS
     *
     * [--skip-purge]
     * : Do not purge the page cache, even when the "clear page cache" option is on.
     *
     * ## EXAMPLES
     *
     *     wp pbc update
     *     wp pbc update --skip-purge
     *
     * @when after_wp_load
     *
     * @param array $args
     * @param array $assoc_args
     */
    public function update( $args, $assoc_args )
    {
        $skip_purge = ! empty( $assoc_args['skip-purge'] );

        $result = Prevent_Browser_Caching::instance()->bump_versions( $skip_purge );
        $purge = $result['purge'];

        $message = __( 'Versions updated.', 'prevent-browser-caching' );

        if ( $purge['purged'] ) {
            $message .= ' ' . sprintf(
                /* translators: %s: page cache plugin name. */
                __( 'Page cache purged (%s).', 'prevent-browser-caching' ),
                $purge['plugin']
            );
        } elseif ( '' !== $purge['plugin'] && 'purged' !== $purge['reason'] ) {
            $message .= ' ' . sprintf(
                /* translators: 1: page cache plugin name, 2: reason. */
                __( 'Page cache not purged (%1$s: %2$s).', 'prevent-browser-caching' ),
                $purge['plugin'],
                $purge['reason']
            );
        }

        WP_CLI::success( $message );
    }

    /**
     * Show how the site keeps assets fresh: mode, what is versioned, last manual
     * update, and the detected page cache plugin.
     *
     * ## OPTIONS
     *
     * [--format=<format>]
     * : Render output in a particular format.
     * ---
     * default: table
     * options:
     *   - table
     *   - json
     *   - yaml
     * ---
     *
     * ## EXAMPLES
     *
     *     wp pbc status
     *     wp pbc status --format=json
     *
     * @when after_wp_load
     *
     * @param array $args
     * @param array $assoc_args
     */
    public function status( $args, $assoc_args )
    {
        $status = Prevent_Browser_Caching::instance()->get_status();

        $format = isset( $assoc_args['format'] ) ? $assoc_args['format'] : 'table';

        if ( 'table' === $format ) {
            $rows = array();

            foreach ( $status as $key => $value ) {
                if ( is_bool( $value ) ) {
                    $value = $value ? 'yes' : 'no';
                } elseif ( '' === $value ) {
                    $value = '—';
                }

                $rows[] = array( 'field' => $key, 'value' => $value );
            }

            WP_CLI\Utils\format_items( 'table', $rows, array( 'field', 'value' ) );

            return;
        }

        WP_CLI\Utils\format_items( $format, array( $status ), array_keys( $status ) );
    }
}

WP_CLI::add_command( 'pbc', 'Prevent_Browser_Caching_CLI' );
