<?php
/**
 * Removes all options of Prevent Browser Caching when the plugin is deleted.
 */

// Exit if accessed directly.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

/**
 * Deletes the plugin options of the current site.
 */
function prevent_browser_caching_uninstall_site()
{
    $pbc_options = array(
        'prevent_browser_caching_options',
        'prevent_browser_caching_clear_cache_time',
        'prevent_browser_caching_media_time',
        'prevent_browser_caching_previous_options',
    );

    foreach ( $pbc_options as $pbc_option ) {
        delete_option( $pbc_option );
    }

    delete_transient( 'pbc_activation_redirect' );
}

if ( is_multisite() ) {
    $pbc_site_ids = get_sites( array( 'fields' => 'ids' ) );

    foreach ( $pbc_site_ids as $pbc_site_id ) {
        switch_to_blog( $pbc_site_id );
        prevent_browser_caching_uninstall_site();
        restore_current_blog();
    }
} else {
    prevent_browser_caching_uninstall_site();
}
