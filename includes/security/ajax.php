<?php
/**
 * SiteGlow — AJAX Handlers
 *
 * All `wp_ajax_*` action handlers for the plugin live here.  Centralising them
 * in one file makes it easy to audit every endpoint the plugin exposes:
 *
 *   siteglow_toggle_editor              — Dashboard: toggle the floating editor on/off.
 *   siteglow_save_theme_vars            — Dashboard: write CSS variable overrides to file.
 *   siteglow_export_live_editor_files   — Block editor: save per-page CSS/JS to uploads.
 *   siteglow_fetch_header_template_files — Customizer: reload CSS/JS for a header template.
 *   siteglow_get_footer_fields          — Customizer: reload HTML/CSS/JS for a footer template.
 *
 * Every handler follows the same security pattern:
 *   1. Verify nonce via `siteglow_verify_dashboard_nonce()` or `siteglow_verify_export_nonce()`.
 *   2. Check `current_user_can('manage_options')` (or 'customize' where appropriate).
 *   3. Sanitize all input before use.
 *   4. Respond with `wp_send_json_success()` or `wp_send_json_error()`.
 *
 * Nonce helpers are defined in includes/security/nonce.php (required first in
 * siteglow.php).
 *
 * @package SiteGlow
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

// ============================================================
// Dashboard: toggle the floating editor button on / off
// ============================================================
add_action( 'wp_ajax_siteglow_toggle_editor', 'siteglow_toggle_editor_handler' );

/**
 * AJAX handler: saves the `siteglow_editor_enabled` option.
 *
 * Stores `1` (enabled) or `0` (disabled) in the options table.  The
 * block-editor loader (`siteglow_enqueue_block_editor_assets`) checks this option
 * before enqueueing the floating button script.
 *
 * Security: `siteglow_verify_dashboard_nonce()` + `manage_options`.
 *
 * POST parameters:
 *   nonce   (string)  — Dashboard nonce (SITEGLOW_NONCE_DASHBOARD).
 *   enabled (int 0|1) — Desired state.
 *
 * @since  1.0.0
 * @return void  Exits via wp_send_json_success() or wp_send_json_error().
 */
function siteglow_toggle_editor_handler(): void {
    siteglow_verify_dashboard_nonce();
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Permission denied' );
    }

    $enabled = intval( $_POST['enabled'] ?? 1 ) ? 1 : 0;
    update_option( 'siteglow_editor_enabled', $enabled );
    wp_send_json_success( [ 'enabled' => $enabled ] );
}

// ============================================================
// Dashboard: save theme CSS variable overrides to uploads file
// ============================================================
add_action( 'wp_ajax_siteglow_save_theme_vars', 'siteglow_save_theme_vars_handler' );

/**
 * AJAX handler: writes CSS custom property overrides to theme-vars.css.
 *
 * Accepts an associative array of `{ '--variable-name': 'value' }` pairs,
 * validates each key against `^--[\w-]+$`, sanitizes values, and writes a
 * single `:root { }` block to uploads/siteglow/theme-vars.css.
 *
 * Security: `siteglow_verify_dashboard_nonce()` + `manage_options`.
 *
 * POST parameters:
 *   nonce (string)        — Dashboard nonce.
 *   vars  (array<string>) — Map of variable name => override value.
 *
 * @since  1.0.0
 * @return void  Exits via wp_send_json_success() or wp_send_json_error().
 */
function siteglow_save_theme_vars_handler(): void {
    siteglow_verify_dashboard_nonce();
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Permission denied' );
    }

    $raw_vars = isset( $_POST['vars'] ) ? (array) $_POST['vars'] : [];
    $css      = ":root {\n";

    foreach ( $raw_vars as $name => $value ) {
        if ( ! preg_match( '/^--[\w-]+$/', $name ) ) continue;
        $value = sanitize_text_field( wp_unslash( $value ) );
        if ( $value === '' ) continue;
        $css .= "  {$name}: {$value};\n";
    }

    $css .= "}\n";

    $paths = siteglow_upload_paths();
    wp_mkdir_p( $paths['dir'] );
    siteglow_put_file( $paths['dir'] . '/theme-vars.css', $css );

    wp_send_json_success();
}

// ============================================================
// Block editor: export per-page CSS/JS to the uploads directory
// ============================================================
add_action( 'wp_ajax_siteglow_export_live_editor_files', 'siteglow_export_live_editor_files' );

/**
 * AJAX handler: persists live-edited CSS/JS to the uploads directory.
 *
 * Called by the "Save CSS" / "Save JS" buttons in the floating editor panel
 * and automatically after every WordPress post save (via `wp.data.subscribe`
 * in siteglow-input-button.js).
 *
 * Security: `siteglow_verify_export_nonce()` + `manage_options`.
 *
 * POST parameters:
 *   nonce   (string) — Export nonce (SITEGLOW_NONCE_EXPORT).
 *   post_id (int)    — ID of the post/page being edited.
 *   css     (string) — Full CSS content (may be empty).
 *   js      (string) — Full JS content (may be empty).
 *
 * @since  1.0.0
 * @return void  Exits via wp_send_json_success() or wp_send_json_error().
 */
function siteglow_export_live_editor_files(): void {
    siteglow_verify_export_nonce();

    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Permission denied' );
    }

    $post_id = intval( $_POST['post_id'] ?? 0 );
    $css     = wp_unslash( $_POST['css'] ?? '' );
    $js      = wp_unslash( $_POST['js']  ?? '' );

    $post = get_post( $post_id );
    if ( ! $post ) {
        wp_send_json_error( 'Invalid post' );
    }

    $slug    = $post->post_name;
    $paths   = siteglow_upload_paths();
    $css_dir = $paths['dir'] . '/pages/css';
    $js_dir  = $paths['dir'] . '/pages/js';

    wp_mkdir_p( $css_dir );
    wp_mkdir_p( $js_dir );

    siteglow_put_file( "{$css_dir}/{$slug}.css", $css );
    siteglow_put_file( "{$js_dir}/{$slug}.js",   $js );

    wp_send_json_success( [
        'css_url' => $paths['url'] . "/pages/css/{$slug}.css",
        'js_url'  => $paths['url'] . "/pages/js/{$slug}.js",
    ] );
}

// ============================================================
// Customizer (Header): fetch CSS/JS for a given template slug
// ============================================================
add_action( 'wp_ajax_siteglow_fetch_header_template_files', 'siteglow_fetch_header_template_files' );

/**
 * AJAX handler: returns the stored CSS/JS for a header template slug.
 *
 * Called by the Customizer JS when the admin changes the template selector,
 * so the CodeMirror editors reload with the selected template's content.
 *
 * Also invalidates opcode cache for both files so PHP does not serve a stale
 * copy immediately after a Customizer publish.
 *
 * Security: requires `customize` capability; `sanitize_file_name()` on the
 * template slug to prevent path traversal.
 *
 * POST parameters:
 *   template (string) — Template slug, e.g. 'header-classic'.
 *
 * @since  1.0.0
 * @return void  Exits via wp_send_json_success() or wp_send_json_error().
 */
function siteglow_fetch_header_template_files(): void {
    if ( ! current_user_can( 'customize' ) || empty( $_POST['template'] ) ) {
        wp_send_json_error( 'Unauthorized or invalid template' );
    }

    $template = sanitize_file_name( $_POST['template'] );
    $paths    = siteglow_upload_paths();
    $tpl_dir  = $paths['dir'] . '/header-templates/';
    $css_file = $tpl_dir . $template . '.css';
    $js_file  = $tpl_dir . $template . '.js';

    if ( function_exists( 'opcache_invalidate' ) ) {
        @opcache_invalidate( $css_file, true );
        @opcache_invalidate( $js_file,  true );
    }

    wp_send_json_success( [
        'css' => file_exists( $css_file ) ? html_entity_decode( siteglow_get_file( $css_file ), ENT_QUOTES, 'UTF-8' ) : '',
        'js'  => file_exists( $js_file )  ? html_entity_decode( siteglow_get_file( $js_file ),  ENT_QUOTES, 'UTF-8' ) : '',
    ] );
}

// ============================================================
// Customizer (Footer): fetch HTML/CSS/JS for a given template
// ============================================================
add_action( 'wp_ajax_siteglow_get_footer_fields', 'siteglow_get_footer_fields_handler' );

/**
 * AJAX handler: returns the HTML, CSS, and JS for a footer template slug.
 *
 * Called by the Customizer JS when the admin changes the footer template
 * selector so all three editors reload with the selected template's content.
 *
 * HTML is stored as a theme_mod (`footer_html_{template}`).
 * CSS/JS are read from files in the uploads directory.
 *
 * POST parameters:
 *   template (string) — Template slug, e.g. 'footer-classic'.
 *
 * @since  1.0.0
 * @return void  Exits via wp_send_json_success().
 */
function siteglow_get_footer_fields_handler(): void {
    if ( ! current_user_can( 'customize' ) ) {
        wp_send_json_error( 'Unauthorized' );
    }

    $template = sanitize_text_field( $_POST['template'] ?? 'footer-classic' );
    $slug     = str_replace( 'footer-', '', $template );
    $paths    = siteglow_upload_paths();

    $html = get_theme_mod( 'footer_html_' . $template, '' );

    $css_file = $paths['dir'] . "/footer-templates/footer-{$slug}.css";
    $js_file  = $paths['dir'] . "/footer-templates/footer-{$slug}.js";

    wp_send_json_success( [
        'html' => $html,
        'css'  => siteglow_get_file( $css_file ),
        'js'   => siteglow_get_file( $js_file ),
    ] );
}
