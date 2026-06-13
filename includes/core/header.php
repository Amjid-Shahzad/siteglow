<?php
/**
 * SiteGlow — Header Customizer Module
 *
 * Integrates the Header editor into the WordPress Customizer. Provides:
 *
 *  1. A Customizer "Header Options" section with a template selector (Classic,
 *     With CTA, With Search, Mega Menu, Sticky) and toggle-able CodeMirror CSS
 *     and JS editors.
 *
 *  2. On Customizer publish (`customize_save_after`): saves the active
 *     template's CSS/JS to:
 *       uploads/siteglow/header-templates/{template}.css
 *       uploads/siteglow/header-templates/{template}.js
 *     One pair per template so switching presets never loses prior edits.
 *
 *  3. AJAX action `siteglow_fetch_header_template_files`: returns the stored CSS/JS
 *     for a template slug when the admin switches the selector, reloading the
 *     CodeMirror editors without a page refresh.
 *
 *  4. Frontend `wp_enqueue_scripts`: loads the active template's CSS/JS on
 *     every page via `siteglow_enqueue_header_assets()`.
 *
 *  5. Customizer preview: enqueues customize.js for postMessage live preview.
 *
 * @package SiteGlow
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

// ============================================================
// 1) Register Customizer section, settings, and controls
// ============================================================

/**
 * Registers the Header Options section, settings, and controls in the Customizer.
 *
 * Settings registered:
 *   header_template       — slug of the active header template (postMessage transport).
 *   header_template_css   — raw CSS string for the active template (postMessage).
 *   header_template_js    — raw JS  string for the active template (postMessage).
 *   header_reload_button  — dummy setting backing the reload button control.
 *
 * The CSS/JS textarea controls are hidden (`hidden-textarea` class); the
 * visible CodeMirror editors in `siteglow_header_customizer_controls_js()` write
 * back to the underlying settings via `wp.customize(settingId).set(value)`.
 *
 * Loads the currently saved CSS/JS from the uploads directory into the
 * setting's `default` so the editor is pre-populated when the Customizer opens.
 *
 * @since  1.0.0
 * @param  \WP_Customize_Manager $wp_customize  The Customizer manager instance.
 * @return void
 */
function siteglow_customize_header( $wp_customize ) {
    if ( ! class_exists( 'SiteGlow_Header_Reload_Button_Control' ) ) {
        class SiteGlow_Header_Reload_Button_Control extends WP_Customize_Control {
            public $type = 'button';

            public function render_content() {
                ?>
                <label>
                    <span class="customize-control-title"><?php echo esc_html( $this->label ); ?></span>
                    <button type="button" id="siteglow-header-reload-button" class="button">
                        <?php echo esc_html( $this->description ); ?>
                    </button>
                </label>
                <?php
            }
        }
    }

    $paths = siteglow_upload_paths();

    $wp_customize->add_section( 'header_options', [
        'title'       => __( 'Header Options', 'siteglow' ),
        'priority'    => 30,
        'description' => __( 'Select the header template and edit its CSS/JS.', 'siteglow' ),
    ] );

    // Template selector
    $wp_customize->add_setting( 'header_template', [
        'default'           => 'header-classic',
        'sanitize_callback' => 'sanitize_text_field',
        'transport'         => 'postMessage',
    ] );

    $wp_customize->add_control( 'header_template', [
        'label'   => __( 'Select Header', 'siteglow' ),
        'section' => 'header_options',
        'type'    => 'select',
        'choices' => [
            'header-classic'   => 'Classic',
            'header-cta'       => 'With CTA',
            'header-search'    => 'With Search',
            'header-mega-menu' => 'Mega Menu',
            'header-sticky'    => 'Sticky',
        ],
    ] );

    // Load current template CSS/JS from uploads
    $template = get_theme_mod( 'header_template', 'header-classic' );
    $tpl_dir  = $paths['dir'] . '/header-templates/';
    $initial_css = siteglow_get_file( $tpl_dir . $template . '.css' );
    $initial_js  = siteglow_get_file( $tpl_dir . $template . '.js' );

    // CSS setting + control
    $wp_customize->add_setting( 'header_template_css', [
        'default'           => $initial_css,
        'sanitize_callback' => 'wp_strip_all_tags',
        'transport'         => 'postMessage',
    ] );

    $wp_customize->add_control( new WP_Customize_Control(
        $wp_customize,
        'header_template_css',
        [
            'label'       => __( 'Header CSS', 'siteglow' ),
            'section'     => 'header_options',
            'type'        => 'textarea',
            'input_attrs' => [ 'class' => 'hidden-textarea', 'id' => 'siteglow_header_css' ],
            'description' => '<div id="siteglow-css-editor-wrapper" style="border:1px solid #ddd;border-radius:10px;display:none;overflow:hidden;"></div>',
        ]
    ) );

    // JS setting + control
    $wp_customize->add_setting( 'header_template_js', [
        'default'           => $initial_js,
        'sanitize_callback' => 'wp_strip_all_tags',
        'transport'         => 'postMessage',
    ] );

    $wp_customize->add_control( new WP_Customize_Control(
        $wp_customize,
        'header_template_js',
        [
            'label'       => __( 'Header JS', 'siteglow' ),
            'section'     => 'header_options',
            'type'        => 'textarea',
            'input_attrs' => [ 'class' => 'hidden-textarea', 'id' => 'siteglow_header_js' ],
            'description' => '<div id="siteglow-js-editor-wrapper" style="border:1px solid #ddd;border-radius:10px;display:none;overflow:hidden;"></div>',
        ]
    ) );

    // Reload button
    $wp_customize->add_setting( 'header_reload_button', [
        'sanitize_callback' => 'sanitize_text_field',
    ] );

    $wp_customize->add_control( new SiteGlow_Header_Reload_Button_Control(
        $wp_customize,
        'header_reload_button',
        [
            'label'       => __( 'Live Edit CSS & JS', 'siteglow' ),
            'section'     => 'header_options',
            'description' => __( 'Reload Now', 'siteglow' ),
        ]
    ) );
}
add_action( 'customize_register', 'siteglow_customize_header' );

// ============================================================
// 2) Save CSS/JS to uploads when Customizer is published
// ============================================================

/**
 * Saves the active header template's CSS/JS to the uploads directory.
 *
 * Hooked to `customize_save_after` (fires once, after all settings are saved).
 * Reads the final values from `get_theme_mod()` (which returns the newly
 * published values) and writes them to:
 *   uploads/siteglow/header-templates/{template}.css
 *   uploads/siteglow/header-templates/{template}.js
 *
 * `html_entity_decode()` on the JS string reverses entity encoding that the
 * Customizer textarea applies to special characters (e.g. `&amp;` → `&`).
 *
 * @since  1.0.0
 * @return void
 */
function siteglow_save_header_files() {
    $template = get_theme_mod( 'header_template', 'header-classic' );
    $paths    = siteglow_upload_paths();
    $tpl_dir  = $paths['dir'] . '/header-templates/';
    wp_mkdir_p( $tpl_dir );

    $css = get_theme_mod( 'header_template_css', '' );
    $js  = html_entity_decode( get_theme_mod( 'header_template_js', '' ), ENT_QUOTES, 'UTF-8' );

    siteglow_put_file( $tpl_dir . $template . '.css', $css );
    siteglow_put_file( $tpl_dir . $template . '.js',  $js );
}
add_action( 'customize_save_after', 'siteglow_save_header_files' );

// ============================================================
// 4) Customizer controls: CodeMirror editors + toggle buttons
// ============================================================

/**
 * Enqueues WordPress CodeMirror assets inside the Customizer controls panel.
 *
 * Called on `customize_controls_enqueue_scripts`.  Enqueues CodeMirror for
 * both CSS and JavaScript modes so the two editor instances in the Header
 * Options section have syntax highlighting and autocompletion.
 *
 * @since  1.0.0
 * @return void
 */
function siteglow_header_customizer_enqueue() {
    wp_enqueue_code_editor( [ 'type' => 'text/css' ] );
    wp_enqueue_code_editor( [ 'type' => 'text/javascript' ] );
    wp_enqueue_script( 'code-editor' );
    wp_enqueue_style( 'code-editor' );
}
add_action( 'customize_controls_enqueue_scripts', 'siteglow_header_customizer_enqueue' );

/**
 * Outputs the Customizer panel JavaScript for the Header Options section.
 *
 * Injects an inline `<script>` into `customize_controls_print_footer_scripts`
 * that handles:
 *   - Lazy-initialising CodeMirror CSS and JS editors (on first toggle).
 *   - "Edit CSS" / "Edit JS" toggle buttons with slide-up/down animation.
 *   - Syncing CodeMirror changes back to the Customizer settings via
 *     `wp.customize(settingId)(value)` so the preview updates live.
 *   - Reloading both editors when the template selector changes (via the
 *     `siteglow_fetch_header_template_files` AJAX action).
 *
 * @since  1.0.0
 * @return void
 */
function siteglow_header_customizer_controls_js() {
    ?>
<style>
.hidden-textarea { display:none; margin-top:10px; }
</style>
<script>
(function($, api){
    var editors = { css: null, js: null };
    var open    = { css: false, js: false };

    function initEditor(settingId, mode) {
        if (editors[settingId]) return;

        var textarea = $('#customize-control-' + settingId + ' textarea');
        if (!textarea.length) return;

        var settings = _.clone(wp.codeEditor.defaultSettings || {});
        settings.codemirror = settings.codemirror || {};
        settings.codemirror.mode             = mode;
        settings.codemirror.lineNumbers      = true;
        settings.codemirror.matchBrackets    = true;
        settings.codemirror.autoCloseBrackets= true;
        settings.codemirror.styleActiveLine  = true;
        settings.codemirror.extraKeys        = { 'Ctrl-Space': 'autocomplete' };
        settings.codemirror.hintOptions      = { completeSingle: true };

        editors[settingId] = wp.codeEditor.initialize(textarea[0], settings);

        editors[settingId].codemirror.on('change', function() {
            api(settingId)(editors[settingId].codemirror.getValue());
        });
    }

    function toggleEditor(type) {
        var wrapper = $('#' + (type === 'css' ? 'siteglow-css-editor-wrapper' : 'siteglow-js-editor-wrapper'));
        var settingId = 'header_template_' + type;
        var btn = $('#customize-control-' + settingId + ' .siteglow-edit-btn');

        if (!editors[settingId]) {
            initEditor(settingId, type === 'css' ? 'css' : 'javascript');
        }

        if (open[type]) {
            wrapper.slideUp();
            btn.text('Edit ' + type.toUpperCase());
            open[type] = false;
        } else {
            wrapper.slideDown(function() {
                editors[settingId].codemirror.refresh();
            });
            btn.text('Close Editor');
            open[type] = true;
        }
    }

    $(document).ready(function() {
        ['css', 'js'].forEach(function(type) {
            var ctrl = $('#customize-control-header_template_' + type + ' .customize-control-title');
            if (!ctrl.find('.siteglow-edit-btn').length) {
                ctrl.append('<button type="button" class="button siteglow-edit-btn" style="margin-left:10px;">Edit ' + type.toUpperCase() + '</button>');
            }
            ctrl.find('.siteglow-edit-btn').on('click', function() { toggleEditor(type); });
        });

        // Reload template CSS/JS when template selector changes
        api('header_template', function(value) {
            value.bind(function(to) {
                wp.ajax.post('siteglow_fetch_header_template_files', { template: to }).done(function(res) {
                    if (editors['header_template_css']) {
                        editors['header_template_css'].codemirror.setValue(res.css || '');
                    }
                    if (editors['header_template_js']) {
                        editors['header_template_js'].codemirror.setValue(res.js || '');
                    }
                    api('header_template_css').set(res.css || '');
                    api('header_template_js').set(res.js || '');
                });
            });
        });
    });
})(jQuery, wp.customize);
</script>
    <?php
}
add_action( 'customize_controls_print_footer_scripts', 'siteglow_header_customizer_controls_js' );

// ============================================================
// 5) Front-end: Enqueue active header template CSS/JS
// ============================================================
add_action( 'wp_enqueue_scripts', 'siteglow_enqueue_header_assets' );

/**
 * Enqueues the active header template's CSS and JS on the frontend.
 *
 * Reads the `header_template` theme mod to determine which template is active,
 * then enqueues its CSS/JS files from the uploads directory (if they exist).
 * Uses `filemtime()` as the version string for automatic cache-busting.
 *
 * @since  1.0.0
 * @return void
 */
function siteglow_enqueue_header_assets() {
    $template = get_theme_mod( 'header_template', 'header-classic' );
    $paths    = siteglow_upload_paths();
    $tpl_dir  = $paths['dir'] . '/header-templates/';
    $tpl_url  = $paths['url'] . '/header-templates/';

    $css_file = $tpl_dir . $template . '.css';
    $js_file  = $tpl_dir . $template . '.js';

    if ( file_exists( $css_file ) ) {
        wp_enqueue_style( 'siteglow-header-css', $tpl_url . $template . '.css', [], filemtime( $css_file ) );
    }

    if ( file_exists( $js_file ) ) {
        wp_enqueue_script( 'siteglow-header-js', $tpl_url . $template . '.js', [ 'jquery' ], filemtime( $js_file ), true );
    }
}

// ============================================================
// 6) Customizer preview: enqueue customize.js for live preview
// ============================================================
add_action( 'customize_preview_init', 'siteglow_enqueue_customize_preview' );

/**
 * Enqueues the Customizer preview script inside the preview iframe.
 *
 * `customize_preview_init` fires inside the preview pane, not the controls
 * panel.  The script (assets/js/customize.js) listens for `postMessage`
 * events sent by the CodeMirror editors and applies CSS/JS changes to the
 * live preview without a full page reload.
 *
 * @since  1.0.0
 * @return void
 */
function siteglow_enqueue_customize_preview() {
    wp_enqueue_script(
        'siteglow-customize-preview',
        SITEGLOW_URL . 'assets/js/customize.js',
        [ 'customize-preview', 'jquery' ],
        filemtime( SITEGLOW_DIR . 'assets/js/customize.js' ),
        true
    );
}
