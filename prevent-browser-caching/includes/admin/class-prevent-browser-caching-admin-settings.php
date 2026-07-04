<?php

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Prevent_Browser_Caching_Admin_Settings
{

    /**
     * Prevent_Browser_Caching_Admin_Settings Constructor.
     */
    public function __construct()
    {
        add_action( 'admin_menu', array( $this, 'add_plugin_page' ) );
        add_action( 'admin_init', array( $this, 'page_init' ) );
        add_action( 'admin_init', array( $this, 'maybe_migrate_options' ), 5 );
        add_action( 'wp_ajax_pbc_update_clear_cache_time', array( $this, 'update_clear_cache_time' ) );
        add_action( 'wp_ajax_pbc_apply_recommended', array( $this, 'apply_recommended' ) );
        add_action( 'wp_ajax_pbc_dismiss_recommended', array( $this, 'dismiss_recommended' ) );
        add_action( 'wp_ajax_pbc_restore_previous', array( $this, 'restore_previous' ) );
    }

    /**
     * Add options page.
     */
    public function add_plugin_page()
    {
        add_options_page(
            __( 'Prevent Browser Caching', 'prevent-browser-caching' ),
            __( 'Prevent Browser Caching', 'prevent-browser-caching' ),
            'manage_options',
            'prevent-browser-caching',
            array( $this, 'create_admin_page' )
        );
    }

    /**
     * Persist the one-time 2.x → 3.x options migration: same values, new shape.
     */
    public function maybe_migrate_options()
    {
        if ( ! class_exists( 'Prevent_Browser_Caching' ) ) {
            return;
        }

        $raw = get_option( 'prevent_browser_caching_options' );

        if ( is_array( $raw ) && ! empty( $raw ) && empty( $raw['settings_version'] ) ) {
            update_option( 'prevent_browser_caching_options', Prevent_Browser_Caching::instance()->filter_options( $raw ) );
        }
    }

    /**
     * Register the settings.
     */
    public function page_init()
    {
        register_setting(
            'prevent_browser_caching_options_group', // Option group
            'prevent_browser_caching_options', // Option name
            array( $this, 'sanitize' ) // Sanitize
        );
    }

    /**
     * Sanitize the settings form input.
     *
     * @param $input
     * @return mixed
     */
    public function sanitize( $input )
    {
        if ( ! class_exists( 'Prevent_Browser_Caching' ) ) {
            return $input;
        }

        return Prevent_Browser_Caching::instance()->filter_options( $input );
    }

    /**
     * Options page callback.
     */
    public function create_admin_page()
    {
        ?>
        <div class="wrap pbc-wrap">
            <h1><?php esc_html_e( 'Prevent Browser Caching', 'prevent-browser-caching' ); ?></h1>
            <?php if ( class_exists( 'Prevent_Browser_Caching' ) ): ?>
                <?php

                $options = Prevent_Browser_Caching::instance()->get_options();
                $page_cache_plugin = Prevent_Browser_Caching::get_active_page_cache_plugin();
                $has_backup = (bool) get_option( 'prevent_browser_caching_previous_options' );
                $ajax_nonce = wp_create_nonce( 'pbc_settings_actions' );
                $has_legacy_period = ( 'every_period' === $options['clear_cache_automatically'] );
                $has_advanced = $options['version_external'] || $options['admin_area'];

                ?>
                <?php if ( $options['legacy_defaults'] ): ?>
                    <div class="notice notice-info pbc-recommended-banner">
                        <p>
                            <strong><?php esc_html_e( 'New in 3.0: smarter cache busting.', 'prevent-browser-caching' ); ?></strong><br>
                            <?php esc_html_e( 'Your current settings still work exactly as before. The recommended setup updates the version only when a file really changes (browser caching keeps working), stops touching external URLs (payment scripts, CDNs), and adds image cache busting.', 'prevent-browser-caching' ); ?>
                        </p>
                        <p>
                            <button type="button" class="button button-primary" onclick="pbc_ajax_action(this, 'pbc_apply_recommended')"><?php esc_html_e( 'Enable recommended settings', 'prevent-browser-caching' ); ?></button>
                            <button type="button" class="button" onclick="pbc_ajax_action(this, 'pbc_dismiss_recommended')"><?php esc_html_e( 'Keep my current settings', 'prevent-browser-caching' ); ?></button>
                        </p>
                    </div>
                <?php endif; ?>

                <?php if ( $has_backup ): ?>
                    <div class="notice notice-success">
                        <p>
                            <?php esc_html_e( 'Recommended settings are active.', 'prevent-browser-caching' ); ?>
                            <button type="button" class="button-link" onclick="pbc_ajax_action(this, 'pbc_restore_previous')"><?php esc_html_e( 'Restore my previous settings', 'prevent-browser-caching' ); ?></button>
                        </p>
                    </div>
                <?php endif; ?>

                <form method="post" action="options.php">
                    <?php settings_fields( 'prevent_browser_caching_options_group' ); ?>
                    <input type="hidden" name="prevent_browser_caching_options[_form]" value="1" />
                    <input type="hidden" name="prevent_browser_caching_options[settings_version]" value="3" />

                    <div class="pbc-section">
                        <p class="pbc-heading"><?php esc_html_e( 'Keep fresh:', 'prevent-browser-caching' ); ?></p>

                        <label>
                            <input type="checkbox" id="pbc-assets" name="prevent_browser_caching_options[assets]" value="1"<?php checked( $options['assets'] ); ?> />
                            <strong><?php esc_html_e( 'Styles & scripts (CSS, JS)', 'prevent-browser-caching' ); ?></strong>
                        </label>
                        <p class="description pbc-indent"><?php esc_html_e( 'Visitors get the current files instead of cached ones, thanks to the "ver" URL parameter.', 'prevent-browser-caching' ); ?></p>

                        <label>
                            <input type="checkbox" id="pbc-media" name="prevent_browser_caching_options[media_versions]" value="1"<?php checked( $options['media_versions'] ); ?> />
                            <strong><?php esc_html_e( 'Images', 'prevent-browser-caching' ); ?></strong>
                        </label>
                        <p class="description pbc-indent"><?php esc_html_e( 'When a file in the Media Library is edited or replaced, visitors get the new image instead of a cached one.', 'prevent-browser-caching' ); ?></p>

                        <label>
                            <input type="checkbox" id="pbc-html" name="prevent_browser_caching_options[html_freshness]" value="1"<?php checked( $options['html_freshness'] ); ?> />
                            <strong><?php esc_html_e( 'Pages (HTML)', 'prevent-browser-caching' ); ?></strong>
                        </label>
                        <p class="description pbc-indent"><?php esc_html_e( 'Browsers check for a newer version of the page before showing a cached one. Fixes "I still see the old page on my phone" without disabling caching.', 'prevent-browser-caching' ); ?></p>
                        <?php if ( $page_cache_plugin ): ?>
                            <p class="description pbc-indent pbc-page-cache-note">
                                <?php
                                echo esc_html( sprintf(
                                    /* translators: %s: page cache plugin name. */
                                    __( '%s is active, so page caching headers are left to it and the "Pages (HTML)" option currently has no effect.', 'prevent-browser-caching' ),
                                    $page_cache_plugin
                                ) );
                                ?>
                            </p>
                        <?php endif; ?>

                        <p class="pbc-idle-note" style="display: none; color: #996800;">
                            <?php esc_html_e( 'Nothing is selected — the plugin currently does nothing.', 'prevent-browser-caching' ); ?>
                        </p>
                    </div>

                    <div class="pbc-section" id="pbc-update-versions-section">
                        <p class="pbc-heading"><?php esc_html_e( 'When to update:', 'prevent-browser-caching' ); ?></p>

                        <label>
                            <input type="radio" class="pbc-mode" name="prevent_browser_caching_options[clear_cache_automatically]" value="auto"<?php checked( $options['clear_cache_automatically'], 'auto' ); ?> />
                            <?php esc_html_e( 'Automatically, when a file changes — recommended', 'prevent-browser-caching' ); ?>
                        </label>
                        <p class="description pbc-indent"><?php esc_html_e( 'Browser caching keeps working at full strength; files are re-downloaded only after they really change.', 'prevent-browser-caching' ); ?></p>

                        <label>
                            <input type="radio" class="pbc-mode" name="prevent_browser_caching_options[clear_cache_automatically]" value="every_time"<?php checked( $options['clear_cache_automatically'], 'every_time' ); ?> />
                            <?php esc_html_e( 'On every page load (development mode; styles & scripts only)', 'prevent-browser-caching' ); ?>
                        </label>

                        <?php if ( $has_legacy_period ): ?>
                            <label>
                                <input type="radio" class="pbc-mode" name="prevent_browser_caching_options[clear_cache_automatically]" value="every_period"<?php checked( true ); ?> />
                                <?php esc_html_e( 'Every', 'prevent-browser-caching' ); ?>
                                <input type="number" name="prevent_browser_caching_options[clear_cache_automatically_minutes]" value="<?php echo esc_attr( $options['clear_cache_automatically_minutes'] ); ?>" step="1" min="1" max="99999" style="width: 65px">
                                <?php esc_html_e( 'minutes (legacy)', 'prevent-browser-caching' ); ?>
                            </label>
                        <?php endif; ?>

                        <label>
                            <input type="radio" class="pbc-mode" name="prevent_browser_caching_options[clear_cache_automatically]" value="never"<?php checked( $options['clear_cache_automatically'], 'never' ); ?> />
                            <?php esc_html_e( 'Only manually — with the "Update versions" button', 'prevent-browser-caching' ); ?>
                        </label>

                        <p class="pbc-toolbar-option">
                            <label>
                                <input type="checkbox" id="pbc-toolbar" name="prevent_browser_caching_options[show_on_toolbar]" value="1"<?php checked( $options['show_on_toolbar'] ); ?> />
                                <?php esc_html_e( 'Show the "Update versions" button on the toolbar', 'prevent-browser-caching' ); ?>
                            </label>
                        </p>

                        <p class="pbc-manual-warning" style="display: none; color: #b32d2e;">
                            <?php esc_html_e( 'The toolbar button is disabled, so nothing will ever update the versions. Enable the toolbar button above, or use the "Update versions now" button on this page.', 'prevent-browser-caching' ); ?>
                        </p>
                    </div>

                    <div class="pbc-section">
                        <details<?php echo '' !== $options['exclusions'] ? ' open' : ''; ?>>
                            <summary><?php esc_html_e( 'Exclusions', 'prevent-browser-caching' ); ?></summary>
                            <p class="description"><?php esc_html_e( 'One per line: a part of a URL or an exact script/style handle. Matching styles, scripts and images are never versioned.', 'prevent-browser-caching' ); ?></p>
                            <textarea name="prevent_browser_caching_options[exclusions]" rows="4" cols="50" class="large-text code" placeholder="example-part-of-url.js&#10;some-script-handle"><?php echo esc_textarea( $options['exclusions'] ); ?></textarea>
                        </details>

                        <details class="pbc-advanced"<?php echo $has_advanced ? ' open' : ''; ?>>
                            <summary><?php esc_html_e( 'Advanced', 'prevent-browser-caching' ); ?></summary>
                            <p>
                                <label>
                                    <input type="checkbox" name="prevent_browser_caching_options[version_external]" value="1"<?php checked( $options['version_external'] ); ?> />
                                    <?php esc_html_e( 'Also version external URLs (CDN, third-party scripts)', 'prevent-browser-caching' ); ?>
                                </label>
                                <span class="description pbc-indent" style="display: block;"><?php esc_html_e( 'Off is safer: some external services (payment scripts in particular) reject URLs with an unexpected "ver" parameter.', 'prevent-browser-caching' ); ?></span>
                            </p>
                            <p>
                                <label>
                                    <input type="checkbox" name="prevent_browser_caching_options[admin_area]" value="1"<?php checked( $options['admin_area'] ); ?> />
                                    <?php esc_html_e( 'Also version styles & scripts in the admin area (wp-admin)', 'prevent-browser-caching' ); ?>
                                </label>
                            </p>
                        </details>
                    </div>

                    <?php submit_button(); ?>
                </form>

                <?php $pbc_last_update = Prevent_Browser_Caching::instance()->get_clear_cache_time(); ?>
                <div class="pbc-section">
                    <p class="pbc-heading"><?php esc_html_e( 'Manual update:', 'prevent-browser-caching' ); ?></p>
                    <p>
                        <button type="button" class="button" onclick="pbc_update_clear_cache_time(this)"><?php esc_html_e( 'Update versions now', 'prevent-browser-caching' ); ?></button>
                        <span class="pbc-update-feedback" style="display: none; margin-left: 8px; font-weight: 600;"></span>
                    </p>
                    <p class="description"><?php esc_html_e( 'Forces every visitor to fetch fresh copies of the CSS/JS files and images selected above on their next page view.', 'prevent-browser-caching' ); ?></p>
                    <p class="description pbc-last-update"<?php echo $pbc_last_update ? '' : ' style="display: none;"'; ?>>
                        <?php
                        if ( $pbc_last_update ) {
                            echo esc_html( sprintf(
                                /* translators: %s: human-readable time difference, e.g. "2 hours". */
                                __( 'Last manual update: %s ago.', 'prevent-browser-caching' ),
                                human_time_diff( $pbc_last_update )
                            ) );
                        }
                        ?>
                    </p>
                </div>

                <style>
                    .pbc-section { margin: 1.4em 0; padding: 1em 1.2em; background: #fff; border: 1px solid #c3c4c7; border-radius: 4px; max-width: 720px; }
                    .pbc-heading { margin: 0 0 0.6em; font-weight: 600; }
                    .pbc-section label { display: block; margin: 0.35em 0; }
                    .pbc-indent { margin: 0.1em 0 0.7em 1.8em; }
                    .pbc-toolbar-option { margin-top: 1em; }
                    .pbc-section details + details { margin-top: 1em; }
                    .pbc-section summary { cursor: pointer; font-weight: 600; }
                    .pbc-page-cache-note { color: #996800; }
                    .pbc-recommended-banner { padding-bottom: 0.5em; }
                </style>

                <script>
                    function pbc_update_clear_cache_time( element ) {
                        var update_button = jQuery( element );
                        var feedback = jQuery( '.pbc-update-feedback' );
                        var original_text = update_button.text();

                        var data = {
                            action: 'pbc_update_clear_cache_time',
                            nonce: '<?php echo esc_js( $ajax_nonce ); ?>'
                        };

                        update_button.attr( 'disabled', true ).text( '<?php echo esc_js( __( 'Updating…', 'prevent-browser-caching' ) ); ?>' );
                        feedback.hide();

                        jQuery.post( ajaxurl, data )
                            .done( function() {
                                feedback.css( 'color', '#00a32a' ).text( '<?php echo esc_js( __( '✓ Done — visitors will get the fresh files.', 'prevent-browser-caching' ) ); ?>' ).show();
                                jQuery( '.pbc-last-update' ).text( '<?php echo esc_js( __( 'Last manual update: just now.', 'prevent-browser-caching' ) ); ?>' ).show();
                            } )
                            .fail( function() {
                                feedback.css( 'color', '#b32d2e' ).text( '<?php echo esc_js( __( 'Something went wrong — please reload the page and try again.', 'prevent-browser-caching' ) ); ?>' ).show();
                            } )
                            .always( function() {
                                update_button.attr( 'disabled', false ).text( original_text );
                            } );
                    }

                    function pbc_ajax_action( element, action ) {
                        var button = jQuery( element );
                        var original_text = button.text();

                        var data = {
                            action: action,
                            nonce: '<?php echo esc_js( $ajax_nonce ); ?>'
                        };

                        button.attr( 'disabled', true ).text( '<?php echo esc_js( __( 'Please wait…', 'prevent-browser-caching' ) ); ?>' );
                        jQuery.post( ajaxurl, data )
                            .done( function() {
                                window.location.reload();
                            } )
                            .fail( function() {
                                button.attr( 'disabled', false ).text( original_text );
                                window.alert( '<?php echo esc_js( __( 'Something went wrong — please reload the page and try again.', 'prevent-browser-caching' ) ); ?>' );
                            } );
                    }

                    ( function() {
                        function pbc_refresh_visibility() {
                            var assets = document.getElementById( 'pbc-assets' );
                            var media = document.getElementById( 'pbc-media' );
                            var html = document.getElementById( 'pbc-html' );
                            var update_section = document.getElementById( 'pbc-update-versions-section' );
                            var idle_note = document.querySelector( '.pbc-idle-note' );

                            if ( assets && media && update_section ) {
                                update_section.style.display = ( assets.checked || media.checked ) ? '' : 'none';
                            }

                            if ( assets && media && html && idle_note ) {
                                idle_note.style.display = ( assets.checked || media.checked || html.checked ) ? 'none' : '';
                            }

                            var mode = document.querySelector( '.pbc-mode:checked' );
                            var toolbar = document.getElementById( 'pbc-toolbar' );
                            var warning = document.querySelector( '.pbc-manual-warning' );

                            if ( mode && toolbar && warning ) {
                                warning.style.display = ( 'never' === mode.value && ! toolbar.checked ) ? '' : 'none';
                            }
                        }

                        document.addEventListener( 'change', function( event ) {
                            var target = event.target;

                            if ( target && ( target.id === 'pbc-assets' || target.id === 'pbc-media' || target.id === 'pbc-html' || target.id === 'pbc-toolbar' || ( target.classList && target.classList.contains( 'pbc-mode' ) ) ) ) {
                                pbc_refresh_visibility();
                            }
                        } );

                        pbc_refresh_visibility();
                    } )();
                </script>
            <?php endif; ?>
            <?php if ( class_exists( 'Prevent_Browser_Caching_Function' ) ): ?>
                <?php

                $assets_version = Prevent_Browser_Caching_Function::instance()->assets_version;

                ?>
                <p><?php esc_html_e( 'NOTE: The assets version of CSS and JS files is set programmatically by your theme or plugin, so the settings above are disabled. It uses this code:', 'prevent-browser-caching' ); ?></p>
                <code style="display: block;">
                    prevent_browser_caching( array(<br>
                    &nbsp;&nbsp;&nbsp;&nbsp;'assets_version' => '<?php echo esc_html( $assets_version ); ?>'<br>
                    ) );
                </code>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Ajax action: bump the assets (and media) versions for all visitors.
     */
    public function update_clear_cache_time()
    {
        $this->verify_ajax_request();

        Prevent_Browser_Caching::instance()->bump_versions();

        exit;
    }

    /**
     * Ajax action: switch a legacy site to the recommended settings,
     * keeping a backup so the change is one click to undo.
     */
    public function apply_recommended()
    {
        $this->verify_ajax_request();

        $current = Prevent_Browser_Caching::instance()->get_options();

        update_option( 'prevent_browser_caching_previous_options', $current, false );

        $recommended = Prevent_Browser_Caching::get_fresh_defaults();

        // Deliberate 2.x-era choices stay as they are.
        $recommended['exclusions'] = $current['exclusions'];
        $recommended['admin_area'] = $current['admin_area'];
        $recommended['show_on_toolbar'] = $current['show_on_toolbar'];
        $recommended['clear_cache_automatically_minutes'] = $current['clear_cache_automatically_minutes'];

        update_option( 'prevent_browser_caching_options', $recommended );

        exit;
    }

    /**
     * Ajax action: hide the recommendation banner, keep everything as is.
     */
    public function dismiss_recommended()
    {
        $this->verify_ajax_request();

        $options = Prevent_Browser_Caching::instance()->get_options();
        $options['legacy_defaults'] = false;

        update_option( 'prevent_browser_caching_options', $options );

        exit;
    }

    /**
     * Ajax action: restore the settings saved before "Enable recommended".
     */
    public function restore_previous()
    {
        $this->verify_ajax_request();

        $previous = get_option( 'prevent_browser_caching_previous_options' );

        if ( is_array( $previous ) && ! empty( $previous ) ) {
            $previous['legacy_defaults'] = false;
            update_option( 'prevent_browser_caching_options', Prevent_Browser_Caching::instance()->filter_options( $previous ) );
        }

        delete_option( 'prevent_browser_caching_previous_options' );

        exit;
    }

    /**
     * Shared nonce + capability check for the Ajax actions above.
     */
    protected function verify_ajax_request()
    {
        check_ajax_referer( 'pbc_settings_actions', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) || ! class_exists( 'Prevent_Browser_Caching' ) ) {
            wp_die( '', '', array( 'response' => 403 ) );
        }
    }

}

new Prevent_Browser_Caching_Admin_Settings();
