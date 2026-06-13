<?php
/**
 * SiteGlow — Typography Customizer Module
 *
 * Manages per-element typography via CSS custom properties stored in
 * uploads/siteglow/typography.css.
 *
 * ── Elements managed (12 total) ────────────────────────────────────────────
 *   base-font, small-font, medium-font, normal-font, large-font,
 *   h1 – h6, button
 *
 * ── Properties per element (6 total) ──────────────────────────────────────
 *   font-family, font-size, line-height, letter-spacing, font-weight, font-color
 *
 * CSS variable naming convention:
 *   --{element-slug}-{property}
 *   e.g. --h1-font-size, --button-font-color, --base-font-font-family
 *
 * ── Storage ────────────────────────────────────────────────────────────────
 * All 72 variables (12 elements × 6 properties) are written to a single
 * typography.css file as a `:root { }` block and enqueued on the frontend
 * via `wp_enqueue_scripts`.  Changes take effect immediately after the
 * Customizer is published — no theme files are modified.
 *
 * ── Customizer integration ─────────────────────────────────────────────────
 * Registers a "Typography & Buttons" section (priority 20) with controls for
 * all 72 settings.  Font size, line height, and letter spacing use a custom
 * `SiteGlow_Typo_Value_Unit_Control` control that combines a number input with a
 * CSS unit dropdown (px / em / rem / %).  Font color uses the native
 * WP_Customize_Color_Control.  All settings use `postMessage` transport.
 *
 * @package SiteGlow
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

// ============================================================
// File helpers — read/write CSS variables in typography.css
// ============================================================

/**
 * Reads a single CSS variable value from typography.css.
 *
 * Searches the file for a line matching `--{variable}: {value};` using a
 * regex and returns the trimmed value.  Returns an empty string when the
 * file does not exist or the variable is not present.
 *
 * Used to pre-populate Customizer settings with the last-saved value so the
 * controls reflect the current state when the Customizer opens.
 *
 * @since  1.0.0
 * @param  string $variable  Variable name without the leading `--`,
 *                           e.g. 'h1-font-size'.
 * @return string            The CSS value string, or '' if not found.
 */
function siteglow_get_typo_value( string $variable ): string {
    $paths = siteglow_upload_paths();
    $path  = $paths['dir'] . '/typography.css';
    if ( ! file_exists( $path ) ) return '';

    $content = siteglow_get_file( $path );
    if ( preg_match( '/--' . preg_quote( $variable, '/' ) . ':\s*([^;]+);/', $content, $m ) ) {
        return trim( $m[1] );
    }
    return '';
}

/**
 * Writes or updates a single CSS variable in typography.css.
 *
 * Three update strategies in order of preference:
 *   1. If the variable already exists in the file, update it in-place
 *      (preserving all other content and whitespace).
 *   2. If a `:root {` block exists but the variable is missing, insert it as
 *      the first line inside the block.
 *   3. If no `:root {` block exists at all, append a new one.
 *
 * Uses `LOCK_EX` on the final write to prevent concurrent Customizer saves
 * from corrupting the file.  Creates the directory with `wp_mkdir_p()` if the
 * uploads folder does not exist yet (e.g. first Customizer publish after
 * plugin activation on a fresh site).
 *
 * @since  1.0.0
 * @param  string $variable  Variable name without the leading `--`,
 *                           e.g. 'h1-font-size'.
 * @param  string $value     The new CSS value, e.g. '2rem' or '#333'.
 * @return void
 */
function siteglow_update_typo_value( string $variable, string $value ): void {
    $paths = siteglow_upload_paths();
    $path  = $paths['dir'] . '/typography.css';

    if ( ! file_exists( $path ) ) {
        wp_mkdir_p( dirname( $path ) );
        siteglow_put_file( $path, ":root {\n  --{$variable}: {$value};\n}\n" );
        return;
    }

    $raw     = siteglow_get_file( $path );
    $lines   = $raw !== '' ? preg_split( '/(?<=\n)/', $raw, -1, PREG_SPLIT_NO_EMPTY ) : [];
    $updated = false;

    foreach ( $lines as $i => $line ) {
        if ( preg_match( '/--' . preg_quote( $variable, '/' ) . ':/', $line ) ) {
            $lines[ $i ] = "  --{$variable}: {$value};\n";
            $updated = true;
            break;
        }
    }

    if ( ! $updated ) {
        foreach ( $lines as $i => $line ) {
            if ( strpos( $line, ':root' ) !== false ) {
                array_splice( $lines, $i + 1, 0, [ "  --{$variable}: {$value};\n" ] );
                $updated = true;
                break;
            }
        }
    }

    if ( ! $updated ) {
        $lines[] = ":root {\n  --{$variable}: {$value};\n}\n";
    }

    siteglow_put_file( $path, implode( '', $lines ) );
}

// ============================================================
// Custom Customizer control: number + unit selector
// ============================================================

/**
 * Custom Customizer control combining a number input with a CSS unit dropdown.
 *
 * Renders a two-column row containing:
 *   - A `<input type="number">` for the numeric part (supports decimals).
 *   - A `<select>` for the CSS unit (px, em, rem, %).
 *
 * The combined value (e.g. "1.5rem") is assembled in the inline JavaScript
 * `sync()` function and pushed to the Customizer setting via
 * `wp.customize(id).set(value)`.
 *
 * On render, the existing setting value (e.g. '2rem') is split by a regex
 * into the numeric part and unit part so both inputs are pre-populated.
 *
 * @since 1.0.0
 */
// ============================================================
// Register Typography & Buttons section in Customizer
// ============================================================
add_action( 'customize_register', function ( $wp_customize ) {
    if ( ! class_exists( 'SiteGlow_Typo_Value_Unit_Control' ) ) {
        class SiteGlow_Typo_Value_Unit_Control extends WP_Customize_Control {
            public $type  = 'value_unit';
            public $units = [ 'px', 'em', 'rem', '%' ];

            public function render_content() {
                $value = $this->value();
                $num   = '';
                $unit  = 'px';

                if ( preg_match( '/^([0-9.\-]+)([a-zA-Z%]+)?$/', $value, $m ) ) {
                    $num  = $m[1] ?? '';
                    $unit = $m[2] ?? 'px';
                }

                if ( ! in_array( $unit, $this->units, true ) ) {
                    $unit = $this->units[0];
                }
                ?>
                <label>
                    <span class="customize-control-title"><?php echo esc_html( $this->label ); ?></span>
                    <div style="display:flex;gap:6px;">
                        <input type="number" step="0.1" class="siteglow-val-input" style="flex:1" value="<?php echo esc_attr( $num ); ?>">
                        <select class="siteglow-unit-select" style="width:80px;">
                            <?php foreach ( $this->units as $u ) : ?>
                                <option value="<?php echo esc_attr( $u ); ?>" <?php selected( $unit, $u ); ?>><?php echo esc_html( $u ); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </label>
                <script>
                (function($){
                    var ctrl  = $('#customize-control-<?php echo esc_js( $this->id ); ?>');
                    var input = ctrl.find('.siteglow-val-input');
                    var sel   = ctrl.find('.siteglow-unit-select');
                    var id    = '<?php echo esc_js( $this->id ); ?>';

                    function sync() {
                        var v = input.val().trim();
                        var u = sel.val().trim();
                        wp.customize(id, function(s){ s.set(v ? v + u : ''); });
                    }

                    input.on('input', sync);
                    sel.on('change', sync);
                })(jQuery);
                </script>
                <?php
            }
        }
    }

    $elements = [
        'base-font'   => 'Base Font',
        'small-font'  => 'Small Font',
        'medium-font' => 'Medium Font',
        'normal-font' => 'Normal Font',
        'large-font'  => 'Large Font',
        'h1'          => 'Heading H1',
        'h2'          => 'Heading H2',
        'h3'          => 'Heading H3',
        'h4'          => 'Heading H4',
        'h5'          => 'Heading H5',
        'h6'          => 'Heading H6',
        'button'      => 'Button',
    ];

    $font_list = [ 'Inter', 'Roboto', 'Poppins', 'Arial', 'Verdana' ];

    $wp_customize->add_section( 'theme_typography', [
        'title'    => __( 'Typography & Buttons', 'siteglow' ),
        'priority' => 20,
    ] );

    foreach ( $elements as $slug => $label ) {

        // Font Family
        $wp_customize->add_setting( "theme_{$slug}_font_family", [
            'default'           => siteglow_get_typo_value( "{$slug}-font-family" ),
            'transport'         => 'postMessage',
            'sanitize_callback' => 'sanitize_text_field',
        ] );
        $wp_customize->add_control( "theme_{$slug}_font_family", [
            'label'   => "$label Font Family",
            'section' => 'theme_typography',
            'type'    => 'select',
            'choices' => array_combine( $font_list, $font_list ),
        ] );

        // Font Size
        $wp_customize->add_setting( "theme_{$slug}_font_size", [
            'default'   => siteglow_get_typo_value( "{$slug}-font-size" ),
            'transport' => 'postMessage',
        ] );
        $wp_customize->add_control( new SiteGlow_Typo_Value_Unit_Control(
            $wp_customize,
            "theme_{$slug}_font_size",
            [ 'label' => "$label Font Size", 'section' => 'theme_typography' ]
        ) );

        // Line Height
        $wp_customize->add_setting( "theme_{$slug}_line_height", [
            'default'   => siteglow_get_typo_value( "{$slug}-line-height" ),
            'transport' => 'postMessage',
        ] );
        $wp_customize->add_control( new SiteGlow_Typo_Value_Unit_Control(
            $wp_customize,
            "theme_{$slug}_line_height",
            [ 'label' => "$label Line Height", 'section' => 'theme_typography' ]
        ) );

        // Letter Spacing
        $wp_customize->add_setting( "theme_{$slug}_letter_spacing", [
            'default'   => siteglow_get_typo_value( "{$slug}-letter-spacing" ),
            'transport' => 'postMessage',
        ] );
        $wp_customize->add_control( new SiteGlow_Typo_Value_Unit_Control(
            $wp_customize,
            "theme_{$slug}_letter_spacing",
            [ 'label' => "$label Letter Spacing", 'section' => 'theme_typography' ]
        ) );

        // Font Weight
        $wp_customize->add_setting( "theme_{$slug}_font_weight", [
            'default'           => siteglow_get_typo_value( "{$slug}-font-weight" ),
            'transport'         => 'postMessage',
            'sanitize_callback' => 'absint',
        ] );
        $wp_customize->add_control( "theme_{$slug}_font_weight", [
            'label'       => "$label Font Weight",
            'section'     => 'theme_typography',
            'type'        => 'number',
            'input_attrs' => [ 'min' => 100, 'max' => 900, 'step' => 100 ],
        ] );

        // Font Color
        $wp_customize->add_setting( "theme_{$slug}_font_color", [
            'default'   => siteglow_get_typo_value( "{$slug}-font-color" ),
            'transport' => 'postMessage',
        ] );
        $wp_customize->add_control( new WP_Customize_Color_Control(
            $wp_customize,
            "theme_{$slug}_font_color",
            [ 'label' => "$label Font Color", 'section' => 'theme_typography' ]
        ) );
    }
} );

// ============================================================
// Save all typography values to typography.css on publish
// ============================================================
add_action( 'customize_save_after', function ( $wp_customize ) {
    $slugs = [ 'base-font','small-font','medium-font','normal-font','large-font','h1','h2','h3','h4','h5','h6','button' ];

    foreach ( $slugs as $slug ) {
        siteglow_update_typo_value( "{$slug}-font-family",    $wp_customize->get_setting( "theme_{$slug}_font_family"    )->value() );
        siteglow_update_typo_value( "{$slug}-font-size",      $wp_customize->get_setting( "theme_{$slug}_font_size"      )->value() );
        siteglow_update_typo_value( "{$slug}-line-height",    $wp_customize->get_setting( "theme_{$slug}_line_height"    )->value() );
        siteglow_update_typo_value( "{$slug}-letter-spacing", $wp_customize->get_setting( "theme_{$slug}_letter_spacing" )->value() );
        siteglow_update_typo_value( "{$slug}-font-weight",    (string) $wp_customize->get_setting( "theme_{$slug}_font_weight" )->value() );
        siteglow_update_typo_value( "{$slug}-font-color",     $wp_customize->get_setting( "theme_{$slug}_font_color"    )->value() );
    }
} );

// ============================================================
// Front-end: Enqueue typography.css (CSS variables)
// ============================================================
add_action( 'wp_enqueue_scripts', function () {
    $paths    = siteglow_upload_paths();
    $css_file = $paths['dir'] . '/typography.css';

    if ( file_exists( $css_file ) ) {
        wp_enqueue_style(
            'siteglow-typography',
            $paths['url'] . '/typography.css',
            [],
            filemtime( $css_file )
        );
    }
} );
