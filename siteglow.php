<?php
/**
 * Plugin Name: SiteGlow
 * Plugin URI:  https://wordpress.org/plugins/siteglow/
 * Description: Live CSS/JS editor for the WordPress block editor and Customizer — edit per-page styles, header, footer, and typography without touching files.
 * Version:     1.0.0
 * Author:      omegadesign
 * License:     GPL-2.0+
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: siteglow
 * Domain Path: /languages
 */

/**
 * SiteGlow — Plugin Bootstrap
 *
 * This file is the single entry point WordPress loads. It:
 *   1. Defines the two global path constants (SITEGLOW_DIR, SITEGLOW_URL).
 *   2. Registers the activation hook that creates the upload-directory structure.
 *   3. Includes all feature modules (dashboard, block editor, page assets,
 *      header, footer, typography).
 *
 * ── Upload directory layout ────────────────────────────────────────────────
 * All generated files are stored inside wp-content/uploads/siteglow/
 * so they survive plugin updates and are never overwritten by Git deploys.
 *
 *   pages/css/{post-slug}.css      Per-page/post CSS written by the floating
 *   pages/js/{post-slug}.js        block-editor button and exported via AJAX.
 *
 *   header-templates/{tpl}.css     CSS per Customizer header template.
 *   header-templates/{tpl}.js      JS  per Customizer header template.
 *
 *   footer-templates/footer-{s}.css  CSS per Customizer footer template.
 *   footer-templates/footer-{s}.js   JS  per Customizer footer template.
 *
 *   theme-vars.css                 `:root { }` overrides for CSS custom
 *                                  properties, edited from the Dashboard panel.
 *                                  Enqueued at priority 99 so it wins over
 *                                  the active theme stylesheet.
 *
 *   typography.css                 `:root { }` block of typography CSS
 *                                  variables managed via the Customizer.
 *
 * ── Security model ─────────────────────────────────────────────────────────
 * Every admin-facing feature is double-gated:
 *   • PHP: `current_user_can('manage_options')` before rendering or saving.
 *   • AJAX: `check_ajax_referer()` + `current_user_can('manage_options')`.
 * The floating block-editor button is also gated by the `siteglow_editor_enabled`
 * option so admins can disable it without deactivating the plugin.
 *
 * ── Namespace convention ───────────────────────────────────────────────────
 * All functions, options, and script/style handles are prefixed `siteglow_` or
 * `siteglow-` to avoid collisions with other plugins or WordPress core.
 *
 * @package SiteGlow
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

/** Absolute filesystem path to the plugin root directory (trailing slash). */
define( 'SITEGLOW_DIR', plugin_dir_path( __FILE__ ) );

/** Public URL to the plugin root directory (trailing slash). */
define( 'SITEGLOW_URL', plugin_dir_url( __FILE__ ) );

/** Plugin version — used as a fallback asset version when filemtime() unavailable. */
define( 'SITEGLOW_VERSION', '1.0.0' );

// ============================================================
// TEXT DOMAIN
// ============================================================
add_action( 'init', function () {
    load_plugin_textdomain( 'siteglow', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
} );

// ============================================================
// FILESYSTEM HELPERS (WP_Filesystem API wrappers)
// ============================================================

/**
 * Returns the initialized WP_Filesystem global.
 *
 * @since  1.0.0
 * @return WP_Filesystem_Base|null
 */
function siteglow_filesystem(): ?object {
    global $wp_filesystem;
    if ( ! $wp_filesystem ) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        WP_Filesystem();
    }
    return $wp_filesystem;
}

/**
 * Writes content to a file using the WP Filesystem API.
 *
 * @since  1.0.0
 * @param  string $path     Absolute filesystem path to the file.
 * @param  string $content  Content to write.
 * @return bool             True on success, false on failure.
 */
function siteglow_put_file( string $path, string $content ): bool {
    $fs = siteglow_filesystem();
    return $fs ? (bool) $fs->put_contents( $path, $content, FS_CHMOD_FILE ) : false;
}

/**
 * Reads file contents using the WP Filesystem API.
 *
 * @since  1.0.0
 * @param  string $path  Absolute filesystem path to the file.
 * @return string        File contents, or empty string if the file does not exist or cannot be read.
 */
function siteglow_get_file( string $path ): string {
    $fs = siteglow_filesystem();
    return ( $fs && $fs->exists( $path ) ) ? (string) $fs->get_contents( $path ) : '';
}

// ============================================================
// HELPER: Upload directory paths for all generated files
// ============================================================

/**
 * Returns the absolute filesystem path and public URL for the plugin's
 * dedicated folder inside the WordPress uploads directory.
 *
 * Using `wp_upload_dir()` (rather than hard-coding a path) ensures the plugin
 * works correctly on multisite installations and when the uploads directory
 * has been moved via the `upload_path` option or a constant.
 *
 * @since  1.0.0
 * @return array {
 *     @type string $dir Absolute server path, e.g. /var/www/html/wp-content/uploads/siteglow
 *     @type string $url Public URL,             e.g. https://example.com/wp-content/uploads/siteglow
 * }
 */
function siteglow_upload_paths() {
    $upload = wp_upload_dir();
    return [
        'dir' => $upload['basedir'] . '/siteglow',
        'url' => $upload['baseurl'] . '/siteglow',
    ];
}

// ============================================================
// INCLUDES
// Security files must be loaded first — their constants and
// helpers are used by dashboard, block-editor, header, and footer.
// ============================================================
require SITEGLOW_DIR . 'includes/security/nonce.php';
require SITEGLOW_DIR . 'includes/security/ajax.php';
require SITEGLOW_DIR . 'includes/admin/dashboard.php';
require SITEGLOW_DIR . 'includes/core/block-editor.php';
require SITEGLOW_DIR . 'includes/core/page-assets.php';
require SITEGLOW_DIR . 'includes/core/header.php';
require SITEGLOW_DIR . 'includes/core/footer.php';
require SITEGLOW_DIR . 'includes/core/typography.php';

// ============================================================
// ACTIVATION: Create folder structure + seed existing pages
// ============================================================

/**
 * Plugin activation callback.
 *
 * Creates the required upload-directory structure and seeds empty asset files
 * for every already-published page and post so the live editor is immediately
 * available after activation without needing to resave each post.
 *
 * Hooked to `register_activation_hook` — runs only once, not on every load.
 *
 * Directories created:
 *   uploads/siteglow/pages/css/
 *   uploads/siteglow/pages/js/
 *   uploads/siteglow/header-templates/
 *   uploads/siteglow/footer-templates/
 *
 * @since 1.0.0
 */
function siteglow_on_activate() {
    $paths = siteglow_upload_paths();

    wp_mkdir_p( $paths['dir'] . '/pages/css' );
    wp_mkdir_p( $paths['dir'] . '/pages/js' );
    wp_mkdir_p( $paths['dir'] . '/header-templates' );
    wp_mkdir_p( $paths['dir'] . '/footer-templates' );

    // Create default typography.css if missing
    $typo_file = $paths['dir'] . '/typography.css';
    if ( ! file_exists( $typo_file ) ) {
        $default  = ":root {\n";
        $elements = [ 'base-font','small-font','medium-font','normal-font','large-font','h1','h2','h3','h4','h5','h6','button' ];
        foreach ( $elements as $slug ) {
            $default .= "  --{$slug}-font-family: inherit;\n";
            $default .= "  --{$slug}-font-size: inherit;\n";
            $default .= "  --{$slug}-line-height: inherit;\n";
            $default .= "  --{$slug}-letter-spacing: 0;\n";
            $default .= "  --{$slug}-font-weight: inherit;\n";
            $default .= "  --{$slug}-font-color: inherit;\n";
        }
        $default .= "}\n";
        siteglow_put_file( $typo_file, $default );
    }

    // Seed empty asset files for all existing published pages and posts
    $existing = get_posts( [
        'post_type'        => [ 'page', 'post' ],
        'numberposts'      => -1,
        'post_status'      => 'publish',
        'suppress_filters' => true,
    ] );
    foreach ( $existing as $post ) {
        siteglow_init_page_assets( $post->ID, $post, false );
    }
}
register_activation_hook( __FILE__, 'siteglow_on_activate' );
