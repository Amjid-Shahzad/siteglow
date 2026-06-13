<?php
/**
 * SiteGlow — Block Editor Integration
 *
 * Handles all Gutenberg / block-editor–specific functionality:
 *
 *  1. Enqueues the floating CSS/JS editor button (siteglow-input-button.js) inside
 *     the block editor for admin users — gated by capability AND the
 *     `siteglow_editor_enabled` option so it can be toggled from the Dashboard.
 *
 *  2. Provides the `siteglow_export_live_editor_files` AJAX action that the floating
 *     button calls to persist edited CSS/JS to the uploads directory.
 *
 *  3. Registers `siteglow_enqueue_page_assets()` on `wp_enqueue_scripts` to
 *     conditionally load per-page CSS/JS on the frontend:
 *       • Singular pages/posts — only that post's files are enqueued.
 *       • Archive / blog home  — CSS for all visible post cards is enqueued in
 *         PHP (needed before first paint); JS is deferred to IntersectionObserver
 *         via archive-lazy.js so scripts execute only when each card scrolls
 *         into the viewport.
 *
 * ── Data flow from editor to frontend ─────────────────────────────────────
 *   Admin types CSS/JS into the floating CodeMirror panel
 *     → stored in localStorage (key: siteglow_iframe_css_{postId})
 *     → on Save / post save: AJAX → siteglow_export_live_editor_files()
 *       → writes uploads/siteglow/pages/css/{slug}.css
 *             and uploads/siteglow/pages/js/{slug}.js
 *         → wp_enqueue_style / wp_enqueue_script on next frontend page load
 *
 * @package SiteGlow
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

// ============================================================
// Block editor: enqueue floating CSS/JS editor (admins only)
// ============================================================

/**
 * Enqueues the floating live-editor button inside the block editor.
 *
 * Double-gated for security:
 *   1. `current_user_can('manage_options')` — non-admins never receive the script.
 *   2. `siteglow_editor_enabled` option — admins can turn the button off via the
 *      Dashboard without deactivating the plugin.
 *
 * Passes `siteglowData` (nonce + ajaxUrl) to JavaScript via `wp_localize_script`
 * so the save handler can authenticate its AJAX request.
 *
 * Also enqueues the WordPress CodeMirror bundle (`wp-code-editor`) which
 * provides syntax highlighting inside the floating panels.
 *
 * @since  1.0.0
 * @return void
 */
function siteglow_enqueue_block_editor_assets() {
    if ( ! current_user_can( 'manage_options' ) ) return;
    if ( ! get_option( 'siteglow_editor_enabled', 1 ) ) return;

    $js = SITEGLOW_DIR . 'assets/js/siteglow-input-button.js';

    wp_enqueue_script(
        'siteglow-live-input',
        SITEGLOW_URL . 'assets/js/siteglow-input-button.js',
        [ 'wp-blocks', 'wp-element', 'wp-components', 'wp-editor' ],
        filemtime( $js ),
        true
    );

    wp_localize_script( 'siteglow-live-input', 'siteglowData', [
        'nonce'   => siteglow_nonce_export(),
        'ajaxUrl' => admin_url( 'admin-ajax.php' ),
    ] );

    wp_enqueue_code_editor( [ 'type' => 'text/css' ] );
    wp_enqueue_script( 'wp-code-editor' );
    wp_enqueue_style( 'wp-code-editor' );
}
add_action( 'enqueue_block_editor_assets', 'siteglow_enqueue_block_editor_assets' );

// ============================================================
// Frontend: conditionally enqueue per-page/post assets
// ============================================================

/**
 * Enqueues per-page/post CSS and JS on the frontend.
 *
 * Two distinct loading strategies are used depending on the current template:
 *
 * ── Singular (is_singular()) ───────────────────────────────────────────────
 * Enqueues only the CSS and JS files for the single post/page being viewed.
 * Files are skipped if they are empty (filesize === 0) so no unnecessary
 * HTTP requests are made for pages that have never been live-edited.
 *
 * ── Archive / blog home (is_home() || is_archive()) ────────────────────────
 * On listing pages multiple post cards are visible at once:
 *
 *   CSS — must be in <head> before first paint to avoid flash of unstyled
 *          content.  PHP enqueues the CSS for every post currently in the
 *          main query unconditionally.
 *
 *   JS  — scripts are deferred to the IntersectionObserver in archive-lazy.js.
 *          PHP builds a `$lazy_js` map of { postId: versionedUrl } and passes
 *          it to the loader via `wp_localize_script('siteglow-archive-lazy', ...)`.
 *          The loader then injects each `<script>` tag only when the matching
 *          post card (id="post-{ID}") scrolls within 200px of the viewport.
 *          This avoids executing JS for cards the user never scrolls to.
 *
 * @since  1.0.0
 * @return void
 */
add_action( 'wp_enqueue_scripts', 'siteglow_enqueue_page_assets' );

function siteglow_enqueue_page_assets() {
    global $wp_query;

    $paths   = siteglow_upload_paths();
    $css_dir = $paths['dir'] . '/pages/css';
    $css_url = $paths['url'] . '/pages/css';
    $js_dir  = $paths['dir'] . '/pages/js';
    $js_url  = $paths['url'] . '/pages/js';

    /* ---- Single page or post ---- */
    if ( is_singular() ) {
        global $post;
        if ( ! $post ) return;

        $slug     = $post->post_name;
        $css_file = "{$css_dir}/{$slug}.css";
        $js_file  = "{$js_dir}/{$slug}.js";

        if ( file_exists( $css_file ) && filesize( $css_file ) > 0 ) {
            wp_enqueue_style(
                "siteglow-page-{$slug}",
                "{$css_url}/{$slug}.css",
                [],
                filemtime( $css_file )
            );
        }

        if ( file_exists( $js_file ) && filesize( $js_file ) > 0 ) {
            wp_enqueue_script(
                "siteglow-page-{$slug}",
                "{$js_url}/{$slug}.js",
                [],
                filemtime( $js_file ),
                true
            );
        }

        return;
    }

    /* ---- Archive, blog home, category, tag, author, date ---- */
    if ( ! is_home() && ! is_archive() ) return;

    $posts = $wp_query->posts ?? [];
    if ( empty( $posts ) ) return;

    $lazy_js = []; // post_id => versioned JS URL for the lazy loader

    foreach ( $posts as $queried_post ) {
        $slug     = $queried_post->post_name;
        $post_id  = $queried_post->ID;
        $css_file = "{$css_dir}/{$slug}.css";
        $js_file  = "{$js_dir}/{$slug}.js";

        // CSS must be in <head> — enqueue unconditionally for visible cards
        if ( file_exists( $css_file ) && filesize( $css_file ) > 0 ) {
            wp_enqueue_style(
                "siteglow-post-{$slug}",
                "{$css_url}/{$slug}.css",
                [],
                filemtime( $css_file )
            );
        }

        // JS deferred to IntersectionObserver loader
        if ( file_exists( $js_file ) && filesize( $js_file ) > 0 ) {
            $lazy_js[ $post_id ] = add_query_arg( 'v', filemtime( $js_file ), "{$js_url}/{$slug}.js" );
        }
    }

    if ( ! empty( $lazy_js ) ) {
        $loader = SITEGLOW_DIR . 'assets/js/archive-lazy.js';
        wp_enqueue_script(
            'siteglow-archive-lazy',
            SITEGLOW_URL . 'assets/js/archive-lazy.js',
            [],
            file_exists( $loader ) ? filemtime( $loader ) : null,
            true
        );
        wp_localize_script( 'siteglow-archive-lazy', 'siteglowArchiveAssets', $lazy_js );
    }
}
