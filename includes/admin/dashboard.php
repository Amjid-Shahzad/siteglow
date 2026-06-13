<?php
/**
 * SiteGlow — Admin Dashboard
 *
 * Registers the "Live Editor" top-level admin menu page and provides all
 * features accessible from it:
 *
 *  • Toggle switch to enable/disable the floating block-editor button site-wide.
 *  • Theme CSS Variables panel — shows every CSS custom property defined by the
 *    active theme (scanned from CSS files) PLUS all WordPress preset variables
 *    generated at runtime from theme.json (colors, font sizes, font families,
 *    spacing, gradients, and custom tokens).  Admins can override any variable
 *    and the override is saved to uploads/siteglow/theme-vars.css and
 *    enqueued at priority 99 so it always wins over the theme stylesheet.
 *  • Asset Status table — lists every published page/post with badges showing
 *    whether a CSS and/or JS file with content exists for that post.
 *  • Global Editors panel — quick-access cards linking to the Customizer
 *    sections for Header, Footer, and Typography.
 *
 * ── AJAX actions registered here ──────────────────────────────────────────
 *   wp_ajax_siteglow_toggle_editor    — saves the `siteglow_editor_enabled` option.
 *   wp_ajax_siteglow_save_theme_vars  — writes theme variable overrides to file.
 *
 * ── Option stored ─────────────────────────────────────────────────────────
 *   siteglow_editor_enabled  (int, 0|1) — whether the floating editor is active.
 *
 * ── File written ──────────────────────────────────────────────────────────
 *   uploads/siteglow/theme-vars.css
 *   Contains a single :root { } block with all overridden CSS custom
 *   properties. Enqueued via `siteglow_enqueue_theme_var_overrides()` at
 *   wp_enqueue_scripts priority 99 (after the active theme stylesheet).
 *
 * @package SiteGlow
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

// ============================================================
// Admin menu
// ============================================================
add_action( 'admin_menu', 'siteglow_register_admin_menu' );

/**
 * Registers the "Live Editor" top-level menu item in wp-admin.
 *
 * Uses the `edit-page` dashicon and menu position 60 (between Appearance
 * and Plugins) so it is easy to find without clashing with common plugins.
 * Access is restricted to `manage_options` (Administrators).
 *
 * @since  1.0.0
 * @return void
 */
function siteglow_register_admin_menu() {
    add_menu_page(
        __( 'Live Editor', 'siteglow' ),
        __( 'Live Editor', 'siteglow' ),
        'manage_options',
        'siteglow',
        'siteglow_render_dashboard',
        SITEGLOW_URL . 'assets/icons/siteglow.png',
        3
    );
}

// ============================================================
// Frontend: enqueue theme variable overrides after theme CSS
// ============================================================
add_action( 'wp_enqueue_scripts', 'siteglow_enqueue_theme_var_overrides', 99 );

/**
 * Enqueues the theme variable override stylesheet on the frontend.
 *
 * Runs at priority 99 on `wp_enqueue_scripts` so it is always added to the
 * queue after the active theme's own stylesheet (which typically uses the
 * default priority of 10).  This ensures `:root` variable declarations in
 * theme-vars.css have higher cascade specificity and override the theme's
 * original values.
 *
 * The file is only enqueued when it exists AND has content (filesize > 0)
 * to avoid a wasted HTTP request before any overrides have been saved.
 *
 * The `filemtime()` version string acts as an automatic cache-buster: the
 * browser fetches a fresh copy whenever the file changes.
 *
 * @since  1.0.0
 * @return void
 */
function siteglow_enqueue_theme_var_overrides() {
    $paths = siteglow_upload_paths();
    $file  = $paths['dir'] . '/theme-vars.css';
    if ( file_exists( $file ) && filesize( $file ) > 0 ) {
        wp_enqueue_style(
            'siteglow-theme-vars',
            $paths['url'] . '/theme-vars.css',
            [],
            filemtime( $file )
        );
    }
}

// ============================================================
// Helper: scan active theme CSS files for CSS custom properties
// ============================================================

/**
 * Returns all CSS custom properties (variables) declared by the active theme.
 *
 * Sources scanned (in order, duplicates skipped — first value wins):
 *   1. {theme}/style.css
 *   2. {theme}/assets/css/*.css
 *   3. {theme}/css/*.css
 *   4. {theme}/dist/css/*.css
 *   5. {theme}/build/css/*.css
 *   6. {theme}/src/css/*.css
 *   7. WordPress preset variables from theme.json via `siteglow_get_wp_preset_vars()`
 *      (colors, font sizes, font families, spacing, gradients, custom tokens).
 *
 * WordPress preset variables (--wp--preset--* and --wp--custom--*) are
 * generated at runtime and never written to CSS files on disk, which is why
 * they require a separate code path.
 *
 * @since  1.0.0
 * @return array<string,string>  Map of `--variable-name` => `default-value`.
 */
function siteglow_get_theme_css_vars() {
    $theme_dir  = get_stylesheet_directory();

    // Scan common CSS output directories used by themes/builders
    $candidates = array_filter( array_merge(
        [ $theme_dir . '/style.css' ],
        glob( $theme_dir . '/assets/css/*.css'  ) ?: [],
        glob( $theme_dir . '/css/*.css'          ) ?: [],
        glob( $theme_dir . '/dist/css/*.css'     ) ?: [],
        glob( $theme_dir . '/build/css/*.css'    ) ?: [],
        glob( $theme_dir . '/src/css/*.css'      ) ?: []
    ), 'file_exists' );

    $vars = [];
    foreach ( $candidates as $file ) {
        $content = siteglow_get_file( $file );
        if ( ! $content ) continue;
        preg_match_all( '/(--[\w-]+)\s*:\s*([^;}{]+);/', $content, $matches, PREG_SET_ORDER );
        foreach ( $matches as $m ) {
            $name = trim( $m[1] );
            if ( ! isset( $vars[ $name ] ) ) {
                $vars[ $name ] = trim( $m[2] );
            }
        }
    }

    // Merge in WordPress preset / custom variables from theme.json global settings
    // These are NEVER written to CSS files — WordPress generates them at runtime.
    foreach ( siteglow_get_wp_preset_vars() as $name => $value ) {
        if ( ! isset( $vars[ $name ] ) ) {
            $vars[ $name ] = $value;
        }
    }

    return $vars;
}

// ============================================================
// Helper: collect --wp--preset--* and --wp--custom--* vars
//         from wp_get_global_settings() (WP 5.9+) with a
//         direct theme.json parse as fallback.
// ============================================================

/**
 * Collects all WordPress preset and custom CSS variables from theme.json.
 *
 * WordPress generates `--wp--preset--*` and `--wp--custom--*` variables at
 * runtime from theme.json and injects them into the page via an inline
 * `<style>` block — they are never written to static CSS files, so a CSS-file
 * scan alone would miss them entirely.
 *
 * Primary path (WordPress 5.9+):
 *   Uses `wp_get_global_settings()` which returns the merged settings from
 *   core defaults, the parent theme, and the child theme.  Settings may be
 *   keyed by origin ('custom', 'theme', 'default') or returned as flat arrays
 *   depending on the WordPress version; both shapes are normalised here.
 *
 * Fallback path (WordPress < 5.9 or missing function):
 *   Directly parses the child theme's theme.json, then the parent theme's
 *   theme.json.  Uses `??=` (null-coalescing assignment) so child-theme values
 *   always take precedence over parent-theme defaults.
 *
 * Variable categories extracted:
 *   --wp--preset--color--{slug}          from settings.color.palette
 *   --wp--preset--font-size--{slug}      from settings.typography.fontSizes
 *   --wp--preset--font-family--{slug}    from settings.typography.fontFamilies
 *   --wp--preset--spacing--{slug}        from settings.spacing.spacingSizes
 *   --wp--preset--gradient--{slug}       from settings.color.gradients
 *   --wp--custom--{key}--{subkey}…       from settings.custom (nested)
 *
 * @since  1.0.0
 * @return array<string,string>  Map of `--variable-name` => `value`.
 */
function siteglow_get_wp_preset_vars() {
    $vars = [];

    if ( function_exists( 'wp_get_global_settings' ) ) {
        $s = wp_get_global_settings();

        // Settings can be keyed by origin ('theme','default','custom') or flat arrays —
        // normalise both into one flat list.
        $flat = function ( $data ) {
            if ( empty( $data ) ) return [];
            if ( isset( $data[0] ) ) return $data;             // already flat
            return array_merge(
                (array) ( $data['custom']  ?? [] ),
                (array) ( $data['theme']   ?? [] ),
                (array) ( $data['default'] ?? [] )
            );
        };

        // Colors  →  --wp--preset--color--{slug}
        foreach ( $flat( $s['color']['palette'] ?? [] ) as $item ) {
            if ( isset( $item['slug'], $item['color'] ) ) {
                $vars[ '--wp--preset--color--' . $item['slug'] ] = $item['color'];
            }
        }

        // Font sizes  →  --wp--preset--font-size--{slug}
        foreach ( $flat( $s['typography']['fontSizes'] ?? [] ) as $item ) {
            if ( isset( $item['slug'], $item['size'] ) ) {
                $vars[ '--wp--preset--font-size--' . $item['slug'] ] = $item['size'];
            }
        }

        // Font families  →  --wp--preset--font-family--{slug}
        foreach ( $flat( $s['typography']['fontFamilies'] ?? [] ) as $item ) {
            if ( isset( $item['slug'], $item['fontFamily'] ) ) {
                $vars[ '--wp--preset--font-family--' . $item['slug'] ] = $item['fontFamily'];
            }
        }

        // Spacing  →  --wp--preset--spacing--{slug}
        foreach ( $flat( $s['spacing']['spacingSizes'] ?? [] ) as $item ) {
            if ( isset( $item['slug'], $item['size'] ) ) {
                $vars[ '--wp--preset--spacing--' . $item['slug'] ] = $item['size'];
            }
        }

        // Gradients  →  --wp--preset--gradient--{slug}
        foreach ( $flat( $s['color']['gradients'] ?? [] ) as $item ) {
            if ( isset( $item['slug'], $item['gradient'] ) ) {
                $vars[ '--wp--preset--gradient--' . $item['slug'] ] = $item['gradient'];
            }
        }

        // Custom (nested)  →  --wp--custom--{key}--{subkey}…
        if ( ! empty( $s['custom'] ) ) {
            siteglow_flatten_custom_vars( $s['custom'], '--wp--custom', $vars );
        }

        return $vars;
    }

    // Fallback: parse theme.json files directly (child theme takes priority)
    foreach ( array_unique( [ get_stylesheet_directory(), get_template_directory() ] ) as $dir ) {
        $path = $dir . '/theme.json';
        if ( ! file_exists( $path ) ) continue;
        $raw  = siteglow_get_file( $path );
        $json = $raw ? json_decode( $raw, true ) : null;
        if ( empty( $json['settings'] ) ) continue;
        $s = $json['settings'];

        foreach ( (array) ( $s['color']['palette']          ?? [] ) as $item ) {
            if ( isset( $item['slug'], $item['color'] ) )
                $vars[ '--wp--preset--color--'       . $item['slug'] ] ??= $item['color'];
        }
        foreach ( (array) ( $s['typography']['fontSizes']   ?? [] ) as $item ) {
            if ( isset( $item['slug'], $item['size'] ) )
                $vars[ '--wp--preset--font-size--'   . $item['slug'] ] ??= $item['size'];
        }
        foreach ( (array) ( $s['typography']['fontFamilies'] ?? [] ) as $item ) {
            if ( isset( $item['slug'], $item['fontFamily'] ) )
                $vars[ '--wp--preset--font-family--' . $item['slug'] ] ??= $item['fontFamily'];
        }
        foreach ( (array) ( $s['spacing']['spacingSizes']   ?? [] ) as $item ) {
            if ( isset( $item['slug'], $item['size'] ) )
                $vars[ '--wp--preset--spacing--'     . $item['slug'] ] ??= $item['size'];
        }
        foreach ( (array) ( $s['color']['gradients']        ?? [] ) as $item ) {
            if ( isset( $item['slug'], $item['gradient'] ) )
                $vars[ '--wp--preset--gradient--'    . $item['slug'] ] ??= $item['gradient'];
        }
        if ( ! empty( $s['custom'] ) ) {
            siteglow_flatten_custom_vars( $s['custom'], '--wp--custom', $vars );
        }
        break;
    }

    return $vars;
}

// ============================================================
// Helper: recursively flatten theme.json `custom` block into
//         --wp--custom--{key}--{subkey}: value pairs
// ============================================================

/**
 * Recursively flattens a nested theme.json `settings.custom` array into CSS
 * variable names.
 *
 * WordPress converts nested keys to double-hyphen segments. For example:
 *   `settings.custom.spacing.large = "2rem"`
 *   becomes `--wp--custom--spacing--large: 2rem`
 *
 * This function mirrors that logic so the Dashboard shows the same variable
 * names that WordPress will output on the page.
 *
 * @since  1.0.0
 * @param  array<string,mixed>   $data   The current nesting level (array from theme.json).
 * @param  string                $prefix Accumulated CSS variable prefix, e.g. '--wp--custom'.
 * @param  array<string,string> &$vars   Output map of variable name => value (passed by reference).
 * @return void
 */
function siteglow_flatten_custom_vars( array $data, string $prefix, array &$vars ) {
    foreach ( $data as $key => $value ) {
        $slug = $prefix . '--' . $key;
        if ( is_array( $value ) ) {
            siteglow_flatten_custom_vars( $value, $slug, $vars );
        } else {
            $vars[ $slug ] ??= (string) $value;
        }
    }
}

// ============================================================
// Helper: return display group key for a CSS variable name
// ============================================================

/**
 * Returns a group key used to categorise CSS variables in the Dashboard table.
 *
 * Variables are grouped by prefix so the table can render readable section
 * headers and allow users to collapse or search within a specific category.
 *
 * Group keys and their CSS variable prefixes:
 *   'wp-colors'        — --wp--preset--color--*
 *   'wp-font-sizes'    — --wp--preset--font-size--*
 *   'wp-font-families' — --wp--preset--font-family--*
 *   'wp-spacing'       — --wp--preset--spacing--*
 *   'wp-gradients'     — --wp--preset--gradient--*
 *   'wp-shadows'       — --wp--preset--shadow--*
 *   'wp-custom'        — --wp--custom--*
 *   'theme'            — everything else (custom theme variables)
 *
 * @since  1.0.0
 * @param  string $name  Full CSS variable name including leading `--`.
 * @return string        One of the group keys listed above.
 */
function siteglow_get_var_group( string $name ): string {
    if ( strpos( $name, '--wp--preset--color'        ) === 0 ) return 'wp-colors';
    if ( strpos( $name, '--wp--preset--font-size'    ) === 0 ) return 'wp-font-sizes';
    if ( strpos( $name, '--wp--preset--font-family'  ) === 0 ) return 'wp-font-families';
    if ( strpos( $name, '--wp--preset--spacing'      ) === 0 ) return 'wp-spacing';
    if ( strpos( $name, '--wp--preset--gradient'     ) === 0 ) return 'wp-gradients';
    if ( strpos( $name, '--wp--preset--shadow'       ) === 0 ) return 'wp-shadows';
    if ( strpos( $name, '--wp--custom'               ) === 0 ) return 'wp-custom';
    return 'theme';
}

// ============================================================
// Helper: read previously saved overrides from uploads file
// ============================================================

/**
 * Reads previously saved CSS custom property overrides from theme-vars.css.
 *
 * Parses the `:root { }` block written by `siteglow_save_theme_vars_handler()` and
 * returns it as an associative array so the Dashboard table can pre-populate
 * the override input fields and mark overridden rows with the `.overridden`
 * CSS class (blue left border).
 *
 * Returns an empty array (not an error) when the file does not exist yet —
 * this is the expected state before any overrides have been saved.
 *
 * @since  1.0.0
 * @return array<string,string>  Map of `--variable-name` => `override-value`.
 *                               Empty array when no overrides file exists.
 */
function siteglow_get_theme_var_overrides() {
    $paths   = siteglow_upload_paths();
    $file    = $paths['dir'] . '/theme-vars.css';
    if ( ! file_exists( $file ) ) return [];

    $content = siteglow_get_file( $file );
    if ( ! $content ) return [];

    $overrides = [];
    preg_match_all( '/(--[\w-]+)\s*:\s*([^;}{]+);/', $content, $matches, PREG_SET_ORDER );
    foreach ( $matches as $m ) {
        $overrides[ trim( $m[1] ) ] = trim( $m[2] );
    }
    return $overrides;
}

// ============================================================
// Helper: detect hex / rgb / hsl color values (for swatches)
// ============================================================

/**
 * Checks whether a CSS value string represents a color.
 *
 * Used by the Dashboard table to decide whether to render a small color swatch
 * `<span>` next to the "Theme Default" value. Matches the most common CSS color
 * notations: hex (#fff, #ffffff, #ffffffff), rgb(), rgba(), hsl(), hsla().
 *
 * @since  1.0.0
 * @param  mixed $value  The CSS value string to test.
 * @return bool          True if the value looks like a color, false otherwise.
 */
function siteglow_is_color_value( $value ) {
    return (bool) preg_match( '/^(#[0-9a-fA-F]{3,8}|rgba?\s*\(|hsla?\s*\()/', trim( $value ) );
}

// ============================================================
// Dashboard assets: enqueue CSS + JS on the dashboard screen
// ============================================================
add_action( 'admin_enqueue_scripts', 'siteglow_enqueue_dashboard_assets' );

/**
 * Enqueues the dashboard stylesheet and script on the Live Editor admin screen.
 *
 * Runs on `admin_enqueue_scripts` with a screen ID check so the files are
 * only loaded on `toplevel_page_siteglow`, never on other admin pages.
 *
 * dashboard.css — All scoped `.siteglow-db` styles (moved from the former inline
 *                 `<style>` block for cleaner HTTP caching).
 * dashboard.js  — jQuery interactions: toggle switch, variable input highlighting,
 *                 reset buttons, Save Overrides AJAX call, live search filter.
 *
 * PHP values the JS needs (ajaxUrl, nonce, translated strings) are passed via
 * `wp_localize_script` as `window.siteglowAdminData`.
 *
 * @since  1.0.0
 * @return void
 */
function siteglow_enqueue_dashboard_assets(): void {
    $screen = get_current_screen();
    if ( ! $screen || $screen->id !== 'toplevel_page_siteglow' ) return;

    $css_file = SITEGLOW_DIR . 'assets/css/dashboard.css';
    $js_file  = SITEGLOW_DIR . 'assets/js/dashboard.js';

    wp_enqueue_style(
        'siteglow-dashboard',
        SITEGLOW_URL . 'assets/css/dashboard.css',
        [],
        file_exists( $css_file ) ? filemtime( $css_file ) : SITEGLOW_VERSION
    );

    wp_enqueue_script(
        'siteglow-dashboard',
        SITEGLOW_URL . 'assets/js/dashboard.js',
        [ 'jquery' ],
        file_exists( $js_file ) ? filemtime( $js_file ) : SITEGLOW_VERSION,
        true
    );

    wp_localize_script( 'siteglow-dashboard', 'siteglowAdminData', [
        'ajaxUrl' => admin_url( 'admin-ajax.php' ),
        'nonce'   => siteglow_nonce_dashboard(),
        'i18n'    => [
            'enabled'      => __( 'Enabled',       'siteglow' ),
            'disabled'     => __( 'Disabled',      'siteglow' ),
            'saving'       => __( 'Saving…',  'siteglow' ),
            'saveOverrides'=> __( 'Save Overrides', 'siteglow' ),
            'reset'        => __( 'Reset',         'siteglow' ),
        ],
    ] );
}

// ============================================================
// Dashboard render
// ============================================================

/**
 * Renders the full SiteGlow admin dashboard page.
 *
 * Page sections (top to bottom):
 *
 *  1. Page header  — plugin logo, version badge, enable/disable toggle.
 *  2. Stats row    — four cards: total pages & posts, CSS count, JS count,
 *                    CSS variable count.
 *  3. Global Editors grid — quick-access cards linking into the Customizer for
 *                    Header, Footer, and Typography sections.
 *  4. Theme CSS Variables — searchable, grouped table of all CSS custom
 *                    properties with inline override inputs and reset buttons.
 *                    Grouped into: Theme Variables, WP Preset Colors, WP Preset
 *                    Font Sizes, WP Preset Font Families, WP Preset Spacing,
 *                    WP Preset Gradients, WP Preset Shadows, WP Custom.
 *  5. Asset Status — table of published pages/posts showing whether each has
 *                    a CSS file and/or JS file with content, plus Edit/View links.
 *
 * Inline `<script>` block (jQuery) handles:
 *   - Toggle switch AJAX call.
 *   - Marking variable inputs as changed (visual `.changed` class).
 *   - Reset-to-default buttons for each variable row.
 *   - Save Overrides AJAX call (collects only `.changed` inputs).
 *   - Live search filtering of variable rows and group headers.
 *
 * Access restricted to `manage_options` capability.
 *
 * @since  1.0.0
 * @return void
 */
function siteglow_render_dashboard() {
    if ( ! current_user_can( 'manage_options' ) ) return;

    $paths       = siteglow_upload_paths();
    $css_dir     = $paths['dir'] . '/pages/css';
    $js_dir      = $paths['dir'] . '/pages/js';
    $editor_on   = (bool) get_option( 'siteglow_editor_enabled', 1 );
    $theme_vars  = siteglow_get_theme_css_vars();
    $overrides   = siteglow_get_theme_var_overrides();
    $nonce       = siteglow_nonce_dashboard();
    $theme_name  = wp_get_theme()->get( 'Name' );

    $is_block_theme = function_exists( 'wp_is_block_theme' ) && wp_is_block_theme();

    if ( $is_block_theme ) {
        // Block theme: open the correct template part directly in the Site Editor.
        $stylesheet = urlencode( get_stylesheet() );
        $header_url = admin_url( 'site-editor.php' ) . '?postType=wp_template_part&postId=' . $stylesheet . '%2F%2Fheader&canvas=edit';
        $footer_url = admin_url( 'site-editor.php' ) . '?postType=wp_template_part&postId=' . $stylesheet . '%2F%2Ffooter&canvas=edit';
        $typo_url   = admin_url( 'site-editor.php' ) . '?path=%2Fwp_global_styles';
    } else {
        // Classic theme: open the Customizer auto-focused to the correct section.
        // Brackets must remain literal in the query string so PHP parses them as
        // $_GET['autofocus']['section'] — add_query_arg encodes them, breaking autofocus.
        $customizer_base = admin_url( 'customize.php' );
        $header_url      = $customizer_base . '?autofocus[section]=header_options';
        $footer_url      = $customizer_base . '?autofocus[section]=footer_options';
        $typo_url        = $customizer_base . '?autofocus[section]=theme_typography';
    }

    $pages = get_posts( [
        'post_type'        => [ 'page', 'post' ],
        'numberposts'      => -1,
        'post_status'      => 'publish',
        'orderby'          => 'title',
        'order'            => 'ASC',
        'suppress_filters' => true,
    ] );

    // Stats for header cards
    $stat_css = 0;
    $stat_js  = 0;
    foreach ( $pages as $p ) {
        $s = $p->post_name;
        if ( file_exists( "{$css_dir}/{$s}.css" ) && filesize( "{$css_dir}/{$s}.css" ) > 0 ) $stat_css++;
        if ( file_exists( "{$js_dir}/{$s}.js" )   && filesize( "{$js_dir}/{$s}.js" )   > 0 ) $stat_js++;
    }
    ?>
    <div class="siteglow-db wrap">

        <!-- ── Page header ───────────────────────────────── -->
        <div class="siteglow-page-header">
            <div class="siteglow-ph-brand">
                <div class="siteglow-ph-icon">
                    <img src="<?php echo esc_url( SITEGLOW_URL . 'assets/icons/mainicon.png' ); ?>" alt="" />
                </div>
                <div>
                    <h1><?php esc_html_e( 'SiteGlow', 'siteglow' ); ?></h1>
                    <p><?php esc_html_e( 'Live CSS/JS editing for the WordPress block editor &amp; customizer', 'siteglow' ); ?></p>
                </div>
            </div>
            <div class="siteglow-ph-right">
                <span class="siteglow-version-badge">Version <?php echo esc_html( siteglow_plugin_version() ); ?></span>
                <div class="siteglow-ph-toggle">
                    <label class="siteglow-switch" title="<?php esc_attr_e( 'Toggle floating editor', 'siteglow' ); ?>">
                        <input type="checkbox" id="siteglow-editor-toggle" <?php checked( $editor_on ); ?>>
                        <span class="siteglow-slider"></span>
                    </label>
                    <span class="siteglow-ph-toggle-label"><?php esc_html_e( 'Live Editor', 'siteglow' ); ?></span>
                    <span id="siteglow-toggle-status" class="<?php echo $editor_on ? 'enabled' : 'disabled'; ?>">
                        <?php echo $editor_on ? esc_html__( 'On', 'siteglow' ) : esc_html__( 'Off', 'siteglow' ); ?>
                    </span>
                </div>
            </div>
        </div>

        <!-- ── Stats row ─────────────────────────────────── -->
        <div class="siteglow-stats-row">
            <div class="siteglow-stat-card siteglow-sc-pages">
                <div class="siteglow-stat-icon"><span class="dashicons dashicons-admin-page"></span></div>
                <div>
                    <div class="siteglow-stat-value"><?php echo absint( count( $pages ) ); ?></div>
                    <div class="siteglow-stat-label"><?php esc_html_e( 'Pages &amp; Posts', 'siteglow' ); ?></div>
                </div>
            </div>
            <div class="siteglow-stat-card siteglow-sc-css">
                <div class="siteglow-stat-icon"><span class="dashicons dashicons-editor-code"></span></div>
                <div>
                    <div class="siteglow-stat-value"><?php echo absint( $stat_css ); ?></div>
                    <div class="siteglow-stat-label"><?php esc_html_e( 'With Custom CSS', 'siteglow' ); ?></div>
                </div>
            </div>
            <div class="siteglow-stat-card siteglow-sc-js">
                <div class="siteglow-stat-icon"><span class="dashicons dashicons-media-code"></span></div>
                <div>
                    <div class="siteglow-stat-value"><?php echo absint( $stat_js ); ?></div>
                    <div class="siteglow-stat-label"><?php esc_html_e( 'With Custom JS', 'siteglow' ); ?></div>
                </div>
            </div>
            <div class="siteglow-stat-card siteglow-sc-vars">
                <div class="siteglow-stat-icon"><span class="dashicons dashicons-art"></span></div>
                <div>
                    <div class="siteglow-stat-value"><?php echo absint( count( $theme_vars ) ); ?></div>
                    <div class="siteglow-stat-label">
                        <?php esc_html_e( 'CSS Variables', 'siteglow' ); ?><br>
                        <span style="font-size:11px;color:#2271b1;font-weight:600;"><?php echo esc_html( $theme_name ); ?></span>
                    </div>
                </div>
            </div>
        </div>

        <!-- ── Global editors ────────────────────────────── -->
        <div class="siteglow-section">
            <div class="siteglow-section-head">
                <h2><span class="dashicons dashicons-layout"></span><?php esc_html_e( 'Global Editors', 'siteglow' ); ?></h2>
            </div>
            <div class="siteglow-editor-grid">
                <div class="siteglow-editor-card siteglow-ec-header">
                    <div class="siteglow-ec-stripe"></div>
                    <div class="siteglow-ec-body">
                        <div class="siteglow-ec-icon"><span class="dashicons dashicons-align-center"></span></div>
                        <h3><?php esc_html_e( 'Header', 'siteglow' ); ?></h3>
                        <p><?php $is_block_theme
                            ? esc_html_e( 'Edit the header template in the Site Editor.', 'siteglow' )
                            : esc_html_e( 'Edit header template CSS &amp; JS with live customizer preview.', 'siteglow' ); ?></p>
                        <a href="<?php echo esc_url( $header_url ); ?>" class="button button-primary"><?php $is_block_theme
                            ? esc_html_e( 'Open Site Editor', 'siteglow' )
                            : esc_html_e( 'Open Header Editor', 'siteglow' ); ?></a>
                    </div>
                </div>
                <div class="siteglow-editor-card siteglow-ec-footer">
                    <div class="siteglow-ec-stripe"></div>
                    <div class="siteglow-ec-body">
                        <div class="siteglow-ec-icon"><span class="dashicons dashicons-align-full-width"></span></div>
                        <h3><?php esc_html_e( 'Footer', 'siteglow' ); ?></h3>
                        <p><?php $is_block_theme
                            ? esc_html_e( 'Edit the footer template in the Site Editor.', 'siteglow' )
                            : esc_html_e( 'Edit footer template HTML, CSS &amp; JS with live preview.', 'siteglow' ); ?></p>
                        <a href="<?php echo esc_url( $footer_url ); ?>" class="button button-primary"><?php $is_block_theme
                            ? esc_html_e( 'Open Site Editor', 'siteglow' )
                            : esc_html_e( 'Open Footer Editor', 'siteglow' ); ?></a>
                    </div>
                </div>
                <div class="siteglow-editor-card siteglow-ec-typo">
                    <div class="siteglow-ec-stripe"></div>
                    <div class="siteglow-ec-body">
                        <div class="siteglow-ec-icon"><span class="dashicons dashicons-editor-textcolor"></span></div>
                        <h3><?php esc_html_e( 'Typography', 'siteglow' ); ?></h3>
                        <p><?php $is_block_theme
                            ? esc_html_e( 'Manage global font styles in the Site Editor.', 'siteglow' )
                            : esc_html_e( 'Manage global font families, sizes, weights and colors.', 'siteglow' ); ?></p>
                        <a href="<?php echo esc_url( $typo_url ); ?>" class="button button-primary"><?php $is_block_theme
                            ? esc_html_e( 'Open Global Styles', 'siteglow' )
                            : esc_html_e( 'Open Typography Editor', 'siteglow' ); ?></a>
                    </div>
                </div>
            </div>
        </div>

        <!-- ── Theme CSS variables ───────────────────────── -->
        <div class="siteglow-section">
            <div class="siteglow-section-head">
                <h2><span class="dashicons dashicons-art"></span><?php esc_html_e( 'Theme CSS Variables', 'siteglow' ); ?></h2>
            </div>

        <?php if ( empty( $theme_vars ) ) : ?>
            <p style="padding:20px 24px;color:#646970;margin:0;">
                <?php printf(
                    esc_html__( 'No CSS custom properties found in %s.', 'siteglow' ),
                    '<strong>' . esc_html( $theme_name ) . '</strong>'
                ); ?>
            </p>
        <?php else : ?>
            <div class="siteglow-vars-toolbar">
                <span class="siteglow-vars-meta">
                    <?php printf( esc_html__( '%d variables', 'siteglow' ), count( $theme_vars ) ); ?>
                    &nbsp;·&nbsp;
                    <strong><?php echo esc_html( $theme_name ); ?></strong>
                    <?php if ( ! empty( $overrides ) ) : ?>
                        <span class="siteglow-override-badge"><?php printf( esc_html__( '%d overridden', 'siteglow' ), count( $overrides ) ); ?></span>
                    <?php endif; ?>
                </span>
                <input type="text" class="siteglow-var-search" id="siteglow-var-search"
                    placeholder="<?php esc_attr_e( 'Search variables…', 'siteglow' ); ?>" />
            </div>

            <p id="siteglow-no-vars-msg"><?php esc_html_e( 'No variables match your search.', 'siteglow' ); ?></p>

            <table class="siteglow-vars-table" id="siteglow-vars-table">
                <thead>
                    <tr>
                        <th style="width:36%"><?php esc_html_e( 'Variable', 'siteglow' ); ?></th>
                        <th style="width:24%"><?php esc_html_e( 'Theme Default', 'siteglow' ); ?></th>
                        <th><?php esc_html_e( 'Override Value', 'siteglow' ); ?></th>
                        <th style="width:80px"></th>
                    </tr>
                </thead>
                <tbody>
                <?php
                // ---- Group variables by type for readable display ----
                $group_labels = [
                    'theme'            => __( 'Theme Variables',           'siteglow' ),
                    'wp-colors'        => __( 'WP Preset — Colors',        'siteglow' ),
                    'wp-font-sizes'    => __( 'WP Preset — Font Sizes',    'siteglow' ),
                    'wp-font-families' => __( 'WP Preset — Font Families', 'siteglow' ),
                    'wp-spacing'       => __( 'WP Preset — Spacing',       'siteglow' ),
                    'wp-gradients'     => __( 'WP Preset — Gradients',     'siteglow' ),
                    'wp-shadows'       => __( 'WP Preset — Shadows',       'siteglow' ),
                    'wp-custom'        => __( 'WP Custom (theme.json)',     'siteglow' ),
                ];
                $group_order = array_keys( $group_labels );

                $grouped = [];
                foreach ( $theme_vars as $name => $default ) {
                    $grouped[ siteglow_get_var_group( $name ) ][ $name ] = $default;
                }

                foreach ( $group_order as $group_key ) :
                    if ( empty( $grouped[ $group_key ] ) ) continue;
                    $label      = $group_labels[ $group_key ];
                    $group_vars = $grouped[ $group_key ];
                ?>
                    <tr class="siteglow-group-header" data-group="<?php echo esc_attr( $group_key ); ?>">
                        <td colspan="4">
                            <?php echo esc_html( $label ); ?>
                            <span class="siteglow-group-count">(<?php echo count( $group_vars ); ?>)</span>
                        </td>
                    </tr>

                    <?php foreach ( $group_vars as $name => $default ) :
                        $has_override  = isset( $overrides[ $name ] );
                        $current_value = $has_override ? $overrides[ $name ] : $default;
                        $is_color      = siteglow_is_color_value( $default );
                    ?>
                    <tr class="siteglow-var-row <?php echo $has_override ? 'overridden' : ''; ?>"
                        data-var="<?php echo esc_attr( $name ); ?>"
                        data-group="<?php echo esc_attr( $group_key ); ?>">
                        <td><span class="siteglow-var-name"><?php echo esc_html( $name ); ?></span></td>
                        <td>
                            <?php if ( $is_color ) : ?>
                                <span class="siteglow-color-swatch"
                                    style="background:<?php echo esc_attr( $default ); ?>;"></span>
                            <?php endif; ?>
                            <span class="siteglow-var-default"><?php echo esc_html( $default ); ?></span>
                        </td>
                        <td>
                            <input
                                type="text"
                                class="siteglow-var-input<?php echo $has_override ? ' changed' : ''; ?>"
                                data-default="<?php echo esc_attr( $default ); ?>"
                                name="vars[<?php echo esc_attr( $name ); ?>]"
                                value="<?php echo esc_attr( $current_value ); ?>"
                            >
                        </td>
                        <td>
                            <?php if ( $has_override ) : ?>
                                <button type="button" class="siteglow-reset-var"
                                    data-default="<?php echo esc_attr( $default ); ?>"
                                    title="<?php esc_attr_e( 'Reset to theme default', 'siteglow' ); ?>">
                                    &#8635; <?php esc_html_e( 'Reset', 'siteglow' ); ?>
                                </button>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>

                <?php endforeach; ?>
                </tbody>
            </table>

            <div class="siteglow-vars-footer">
                <button type="button" class="button button-primary" id="siteglow-save-vars">
                    <?php esc_html_e( 'Save Overrides', 'siteglow' ); ?>
                </button>
                <span id="siteglow-vars-saved">&#10003; <?php esc_html_e( 'Saved! Reload frontend to see changes.', 'siteglow' ); ?></span>
            </div>
        <?php endif; ?>
        </div><!-- /.siteglow-section vars -->

        <!-- ── Asset status ──────────────────────────────── -->
        <div class="siteglow-section">
            <div class="siteglow-section-head">
                <h2><span class="dashicons dashicons-media-code"></span><?php esc_html_e( 'Asset Status', 'siteglow' ); ?></h2>
                <span style="font-size:12px;color:#646970;">
                    <?php printf(
                        esc_html__( '%d pages &amp; posts', 'siteglow' ),
                        count( $pages )
                    ); ?>
                </span>
            </div>

        <?php if ( empty( $pages ) ) : ?>
            <p style="padding:20px 24px;color:#646970;margin:0;"><?php esc_html_e( 'No published pages or posts found.', 'siteglow' ); ?></p>
        <?php else : ?>
            <table class="siteglow-assets-table">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Title', 'siteglow' ); ?></th>
                        <th style="width:80px"><?php esc_html_e( 'Type', 'siteglow' ); ?></th>
                        <th style="width:100px"><?php esc_html_e( 'CSS', 'siteglow' ); ?></th>
                        <th style="width:100px"><?php esc_html_e( 'JS', 'siteglow' ); ?></th>
                        <th style="width:160px"><?php esc_html_e( 'Actions', 'siteglow' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ( $pages as $page ) :
                    $slug     = $page->post_name;
                    $css_file = "{$css_dir}/{$slug}.css";
                    $js_file  = "{$js_dir}/{$slug}.js";
                    $has_css  = file_exists( $css_file ) && filesize( $css_file ) > 0;
                    $has_js   = file_exists( $js_file )  && filesize( $js_file )  > 0;
                    $edit_url = get_edit_post_link( $page->ID );
                    $view_url = get_permalink( $page->ID );
                    $is_post  = $page->post_type === 'post';
                ?>
                    <tr>
                        <td>
                            <span class="siteglow-post-title"><?php echo esc_html( $page->post_title ); ?></span>
                            <span class="siteglow-post-slug">/<?php echo esc_html( $slug ); ?></span>
                        </td>
                        <td>
                            <span class="siteglow-badge <?php echo $is_post ? 'type-post' : 'type-page'; ?>">
                                <?php echo $is_post ? esc_html__( 'Post', 'siteglow' ) : esc_html__( 'Page', 'siteglow' ); ?>
                            </span>
                        </td>
                        <td>
                            <span class="siteglow-badge <?php echo $has_css ? 'has' : 'empty'; ?>">
                                <?php echo $has_css ? esc_html__( 'Has CSS', 'siteglow' ) : esc_html__( 'Empty', 'siteglow' ); ?>
                            </span>
                        </td>
                        <td>
                            <span class="siteglow-badge <?php echo $has_js ? 'has' : 'empty'; ?>">
                                <?php echo $has_js ? esc_html__( 'Has JS', 'siteglow' ) : esc_html__( 'Empty', 'siteglow' ); ?>
                            </span>
                        </td>
                        <td>
                            <div class="siteglow-action-btns">
                                <a href="<?php echo esc_url( $edit_url ); ?>" class="button button-small"><?php esc_html_e( 'Edit', 'siteglow' ); ?></a>
                                <a href="<?php echo esc_url( $view_url ); ?>" class="button button-small" target="_blank"><?php esc_html_e( 'View', 'siteglow' ); ?></a>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
        </div><!-- /.siteglow-section assets -->

    </div><!-- /.siteglow-db -->
    <?php
}

// ============================================================
// Helper: read plugin version from header
// ============================================================

/**
 * Returns the plugin version string declared in the main plugin file header.
 *
 * Uses `get_file_data()` rather than a hard-coded string so the version shown
 * in the Dashboard badge always matches the "Version:" line in siteglow.php
 * without a separate constant to keep in sync.
 *
 * Result is statically cached in `$ver` so `get_file_data()` is called at most
 * once per request, even if `siteglow_plugin_version()` is called multiple times.
 *
 * @since  1.0.0
 * @return string  Semantic version string, e.g. '1.0.0'.
 */
function siteglow_plugin_version() {
    static $ver = null;
    if ( $ver === null ) {
        $data = get_file_data( SITEGLOW_DIR . 'siteglow.php', [ 'Version' => 'Version' ] );
        $ver  = $data['Version'] ?? '1.0.0';
    }
    return $ver;
}
