<?php
/**
 * SiteGlow — Footer Customizer Module
 *
 * Integrates the Footer editor into the WordPress Customizer. Provides:
 *
 *  1. A Customizer "Footer Options" section with:
 *       – Template selector (Classic, Minimal, Centered + dynamic Gutenberg
 *         footer sections registered as `footer_section` post type).
 *       – Per-template HTML textarea (value stored in theme_mods as
 *         `footer_html_{template}`).
 *       – Per-template CSS textarea (file-based, uploads).
 *       – Per-template JS  textarea (file-based, uploads).
 *       Fields are hidden for dynamic (Gutenberg-based) footer templates.
 *
 *  2. AJAX action `siteglow_get_footer_fields`: returns the HTML, CSS, and JS for a
 *     given template so the Customizer controls reload without a page refresh
 *     when the admin switches the template selector.
 *
 *  3. On Customizer publish (`customize_save_after`):
 *       – HTML is saved as a theme_mod (`footer_html_{template}`).
 *       – CSS/JS are written to:
 *           uploads/siteglow/footer-templates/footer-{slug}.css
 *           uploads/siteglow/footer-templates/footer-{slug}.js
 *
 *  4. Customizer panel JavaScript: live-switches fields when the template
 *     selector changes and initialises CodeMirror editors (HTML/CSS/JS modes).
 *
 *  5. Frontend `wp_enqueue_scripts`: loads the active template's CSS/JS on
 *     every page (skipped for dynamic templates which have no static files).
 *
 * @package SiteGlow
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

// ============================================================
// 1) Register Customizer section, settings, and controls
// ============================================================

/**
 * Registers the Footer Options section, settings, and controls in the Customizer.
 *
 * Template selector choices include static presets (Classic, Minimal, Centered)
 * and any posts of post type `footer_section` (dynamic Gutenberg-based footers)
 * discovered at registration time.
 *
 * HTML is stored in the theme_mods table (keyed per template) because it is
 * short structured data; CSS and JS are file-based for the same reasons as
 * header templates — they can be large and benefit from direct file serving.
 *
 * The three field controls use `active_callback => siteglow_footer_show_fields` so
 * they are hidden when a dynamic template is active (dynamic footers render
 * themselves via Gutenberg and do not use the HTML/CSS/JS fields).
 *
 * @since  1.0.0
 * @param  \WP_Customize_Manager $wp_customize  The Customizer manager instance.
 * @return void
 */
function siteglow_customize_footer( $wp_customize ) {
    $paths = siteglow_upload_paths();

    $wp_customize->add_section( 'footer_options', [
        'title'       => __( 'Footer Options', 'siteglow' ),
        'priority'    => 40,
        'description' => __( 'Select footer template and customize HTML, CSS, and JS per template.', 'siteglow' ),
    ] );

    // ---- Template selector ----
    $wp_customize->add_setting( 'footer_template', [
        'default'           => 'footer-classic',
        'sanitize_callback' => 'sanitize_text_field',
    ] );

    $choices = [
        'footer-classic'  => 'Classic',
        'footer-minimal'  => 'Minimal',
        'footer-centered' => 'Centered',
    ];

    // Append dynamic Gutenberg footer templates
    $footer_sections = get_posts( [
        'post_type'   => 'footer_section',
        'post_status' => 'publish',
        'numberposts' => -1,
    ] );

    foreach ( $footer_sections as $section ) {
        $choices[ 'dynamic-' . $section->post_name ] = 'Dynamic: ' . $section->post_title;
    }

    $wp_customize->add_control( 'footer_template', [
        'label'   => __( 'Select Footer Template', 'siteglow' ),
        'section' => 'footer_options',
        'type'    => 'select',
        'choices' => $choices,
    ] );

    // ---- HTML (stored per-template in DB) ----
    $active    = get_theme_mod( 'footer_template', 'footer-classic' );
    $html_key  = 'footer_html_' . $active;
    $slug      = str_replace( 'footer-', '', $active );

    $wp_customize->add_setting( 'footer_custom_html', [
        'default'           => get_theme_mod( $html_key, '' ),
        'sanitize_callback' => 'wp_kses_post',
    ] );

    $wp_customize->add_control( 'footer_custom_html', [
        'label'           => __( 'Footer HTML', 'siteglow' ),
        'section'         => 'footer_options',
        'type'            => 'textarea',
        'active_callback' => 'siteglow_footer_show_fields',
    ] );

    // ---- CSS (file-based, uploads) ----
    $css_file = $paths['dir'] . "/footer-templates/footer-{$slug}.css";
    $wp_customize->add_setting( 'footer_custom_css', [
        'default'           => siteglow_get_file( $css_file ),
        'sanitize_callback' => 'wp_strip_all_tags',
    ] );

    $wp_customize->add_control( 'footer_custom_css', [
        'label'           => __( 'Footer CSS', 'siteglow' ),
        'section'         => 'footer_options',
        'type'            => 'textarea',
        'active_callback' => 'siteglow_footer_show_fields',
    ] );

    // ---- JS (file-based, uploads) ----
    $js_file = $paths['dir'] . "/footer-templates/footer-{$slug}.js";
    $wp_customize->add_setting( 'footer_custom_js', [
        'default'           => siteglow_get_file( $js_file ),
        'sanitize_callback' => 'wp_strip_all_tags',
    ] );

    $wp_customize->add_control( 'footer_custom_js', [
        'label'           => __( 'Footer JS', 'siteglow' ),
        'section'         => 'footer_options',
        'type'            => 'textarea',
        'active_callback' => 'siteglow_footer_show_fields',
    ] );
}
add_action( 'customize_register', 'siteglow_customize_footer' );

/**
 * Active callback: returns true when a static (non-dynamic) footer template is selected.
 *
 * Controls using this callback are hidden in the Customizer when the active
 * template slug begins with 'dynamic-' (a Gutenberg-based footer section).
 *
 * @since  1.0.0
 * @return bool  True when the HTML/CSS/JS fields should be visible.
 */
function siteglow_footer_show_fields() {
    return strpos( get_theme_mod( 'footer_template', 'footer-classic' ), 'dynamic-' ) !== 0;
}

// ============================================================
// 3) Save HTML to DB, CSS/JS to uploads on Customizer publish
// ============================================================

/**
 * Saves the active footer template's data when the Customizer is published.
 *
 * HTML is stored as a theme_mod (keyed `footer_html_{template}`) because it
 * is structured text that belongs in the database.  CSS and JS are written
 * to files in the uploads directory.
 *
 * The method reads the posted customized values directly from `$_POST['customized']`
 * (the JSON blob WordPress sends on publish) rather than relying on `get_theme_mod()`
 * which would return the previously saved values at this point in the save cycle.
 *
 * Hooked to `customize_save_after` — runs once after all settings are committed.
 *
 * @since  1.0.0
 * @param  \WP_Customize_Manager $wp_customize  The Customizer manager instance.
 * @return void
 */
function siteglow_save_footer_fields( $wp_customize ) {
    if ( ! isset( $_POST['customized'] ) ) return;

    $customized = json_decode( stripslashes( $_POST['customized'] ), true );
    $template   = get_theme_mod( 'footer_template', 'footer-classic' );
    $slug       = str_replace( 'footer-', '', $template );
    $paths      = siteglow_upload_paths();

    // HTML → DB
    if ( isset( $customized['footer_custom_html'] ) ) {
        set_theme_mod( 'footer_html_' . $template, wp_kses_post( wp_unslash( $customized['footer_custom_html'] ) ) );
    }

    // CSS → file
    $css_dir = $paths['dir'] . '/footer-templates/';
    wp_mkdir_p( $css_dir );
    if ( isset( $customized['footer_custom_css'] ) ) {
        siteglow_put_file( "{$css_dir}footer-{$slug}.css", wp_unslash( $customized['footer_custom_css'] ) );
    }

    // JS → file
    $js_dir = $paths['dir'] . '/footer-templates/';
    wp_mkdir_p( $js_dir );
    if ( isset( $customized['footer_custom_js'] ) ) {
        siteglow_put_file( "{$js_dir}footer-{$slug}.js", wp_unslash( $customized['footer_custom_js'] ) );
    }
}
add_action( 'customize_save_after', 'siteglow_save_footer_fields' );

// ============================================================
// 4) Customizer controls: live switching + CodeMirror
// ============================================================
function siteglow_footer_customizer_scripts() {
    ?>
<script>
(function($){
    wp.customize('footer_template', function(value){
        value.bind(function(to){
            var controls = [
                '#customize-control-footer_custom_html',
                '#customize-control-footer_custom_css',
                '#customize-control-footer_custom_js'
            ];

            if (to.startsWith('dynamic-')) {
                controls.forEach(function(sel){ $(sel).hide(); });
                return;
            }
            controls.forEach(function(sel){ $(sel).show(); });

            wp.ajax.post('siteglow_get_footer_fields', { template: to }).done(function(res){
                wp.customize('footer_custom_html').set(res.html);
                wp.customize('footer_custom_css').set(res.css);
                wp.customize('footer_custom_js').set(res.js);
            });
        });
    });
})(jQuery);
</script>
    <?php
}
add_action( 'customize_controls_print_footer_scripts', 'siteglow_footer_customizer_scripts' );

function siteglow_footer_customizer_codemirror() {
    $settings = wp_enqueue_code_editor( [ 'type' => 'text/html' ] );
    if ( false === $settings ) return;

    wp_enqueue_script( 'wp-theme-plugin-editor' );
    wp_enqueue_style( 'wp-codemirror' );
    ?>
<script>
(function($){
    $(function(){
        if (typeof wp === 'undefined' || !wp.codeEditor) return;

        var editors = [
            { id: '#customize-control-footer_custom_html textarea', mode: 'htmlmixed', setting: 'footer_custom_html' },
            { id: '#customize-control-footer_custom_css textarea',  mode: 'css',        setting: 'footer_custom_css'  },
            { id: '#customize-control-footer_custom_js textarea',   mode: 'javascript', setting: 'footer_custom_js'   }
        ];

        editors.forEach(function(cfg) {
            var $el = $(cfg.id);
            if (!$el.length || $el.data('siteglow-cm-init')) return;
            $el.data('siteglow-cm-init', true);

            var cm = wp.codeEditor.initialize($el[0], {
                codemirror: {
                    mode: cfg.mode,
                    lineNumbers: true,
                    styleActiveLine: true,
                    matchBrackets: true,
                    autoCloseBrackets: true,
                    indentUnit: 2,
                    tabSize: 2,
                    lineWrapping: true
                }
            }).codemirror;

            cm.on('change', function(){
                var val = cm.getValue();
                $el[0].value = val;
                if (wp.customize && wp.customize(cfg.setting)) {
                    wp.customize(cfg.setting).set(val);
                }
                $el.trigger('input').trigger('change');
            });
        });
    });
})(jQuery);
</script>
    <?php
}
add_action( 'customize_controls_print_footer_scripts', 'siteglow_footer_customizer_codemirror', 20 );

// ============================================================
// 5) Front-end: Enqueue active footer template CSS/JS
// ============================================================
add_action( 'wp_enqueue_scripts', 'siteglow_enqueue_footer_assets' );

/**
 * Enqueues the active footer template's CSS and JS on the frontend.
 *
 * Dynamic templates (`dynamic-*`) render via Gutenberg and have no standalone
 * CSS/JS files — this function returns early for them.
 *
 * Uses `filemtime()` as the asset version for automatic cache-busting whenever
 * the file is updated via the Customizer.
 *
 * @since  1.0.0
 * @return void
 */
function siteglow_enqueue_footer_assets() {
    $template = get_theme_mod( 'footer_template', 'footer-classic' );

    // Dynamic footers have no standalone CSS/JS file
    if ( strpos( $template, 'dynamic-' ) === 0 ) return;

    $slug  = str_replace( 'footer-', '', $template );
    $paths = siteglow_upload_paths();
    $dir   = $paths['dir'] . '/footer-templates/';
    $url   = $paths['url'] . '/footer-templates/';

    $css_file = "{$dir}footer-{$slug}.css";
    $js_file  = "{$dir}footer-{$slug}.js";

    if ( file_exists( $css_file ) ) {
        wp_enqueue_style( 'siteglow-footer-css', "{$url}footer-{$slug}.css", [], filemtime( $css_file ) );
    }

    if ( file_exists( $js_file ) ) {
        wp_enqueue_script( 'siteglow-footer-js', "{$url}footer-{$slug}.js", [ 'jquery' ], filemtime( $js_file ), true );
    }
}
