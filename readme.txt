=== SiteGlow ===
Contributors: siteglow
Tags: css editor, live editor, block editor, customizer, javascript editor
Requires at least: 6.0
Tested up to: 7.0
Stable tag: 1.0.0
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Edit your site's CSS and JavaScript live — per-page, header, footer, and typography — all from inside the WordPress admin. No file editing required.

== Description ==

**SiteGlow** puts a complete live CSS and JavaScript editor directly inside WordPress. Style any page, customize your header and footer, and fine-tune typography — all with instant preview and zero file editing.

= Floating Block Editor Button =

A "Live Edit" button appears inside the Gutenberg block editor for every page and post (admins only). Click it to open side-by-side CSS and JavaScript editors powered by the same CodeMirror editor WordPress ships with. Changes preview instantly in the editor canvas and are saved automatically when the post is saved.

= Per-Page Asset Files =

Every page and post gets its own CSS and JS file stored in `wp-content/uploads/siteglow/pages/`. Only the current page's files are loaded on the frontend — no unnecessary HTTP requests for pages you have never edited.

= Theme CSS Variables Panel =

The dashboard shows every CSS custom property declared by your active theme, including all WordPress preset variables from `theme.json` (colors, font sizes, font families, spacing, gradients, custom tokens). Override any variable with a single click and save — overrides are written to a dedicated file and enqueued at priority 99 so they always win over theme defaults.

= Header Editor =

Edit header template CSS and JavaScript through the WordPress Customizer with live preview. Choose from five built-in header templates. Each template stores its own CSS/JS file so switching presets never loses your previous edits.

= Footer Editor =

Edit footer templates (Classic, Minimal, Centered) through the Customizer with full HTML, CSS, and JavaScript editing. Supports dynamic Gutenberg-based footer sections registered as a custom post type.

= Typography Manager =

Control font family, size, line height, letter spacing, weight, and color for 12 element types — headings H1–H6, five body text sizes, and the button element — through a dedicated Customizer section. All values are saved as CSS custom properties and applied globally without modifying any theme file.

= Block Theme Ready =

On Full Site Editing (block) themes the Header and Footer dashboard buttons route directly to the correct template parts in the Site Editor. On classic themes they open the Customizer sections with autofocus. The plugin detects the active theme type automatically.

= Built for Security =

Every admin feature is double-gated: capability check (`manage_options`) plus nonce verification on every AJAX request. All file writes use the WordPress Filesystem API — no direct `file_put_contents` calls. The floating block-editor button can be disabled site-wide from the dashboard without deactivating the plugin.

== Installation ==

1. Upload the `siteglow` folder to the `/wp-content/plugins/` directory, or install via **Plugins → Add New** in WordPress admin.
2. Activate the plugin through the **Plugins** screen.
3. Navigate to **Live Editor** in the admin sidebar to view the dashboard, stats, and CSS variable panel.
4. Open any page or post in the block editor — a floating **Live Edit** button appears for administrators.
5. Go to **Appearance → Customize** (classic themes) to access the Header, Footer, and Typography editors.

== Frequently Asked Questions ==

= Is SiteGlow compatible with the Block Editor (Gutenberg)? =

Yes. The floating live editor button is built for the WordPress block editor and uses the native CodeMirror editor for syntax highlighting.

= Is SiteGlow compatible with Full Site Editing (FSE) / block themes? =

Yes. The per-page CSS/JS editor works on any theme. The Header and Footer buttons on the dashboard link directly to the correct template parts in the Site Editor when a block theme is active.

= Where are my CSS and JS files stored? =

All generated files are stored inside `wp-content/uploads/siteglow/` and survive plugin updates and deactivation. Directory structure:

* `pages/css/` and `pages/js/` — per-page and per-post CSS/JS files
* `header-templates/` — CSS/JS files per header template
* `footer-templates/` — CSS/JS files per footer template
* `theme-vars.css` — your saved CSS variable overrides
* `typography.css` — your saved typography settings

= Does SiteGlow modify theme files? =

No. SiteGlow never touches theme files. All changes are stored in the uploads directory and enqueued via `wp_enqueue_style` and `wp_enqueue_script`. Deactivating the plugin removes all enqueued files cleanly.

= Can I disable the floating editor without deactivating the plugin? =

Yes. Go to **Live Editor** in the admin menu and toggle the **Live Editor** switch at the top of the page. The toggle state is saved as a site option.

= What WordPress and PHP versions are required? =

WordPress 6.0 or higher and PHP 7.4 or higher.

= Does the plugin make external HTTP requests? =

No. SiteGlow makes no external HTTP requests. All data is processed and stored on your own server.

= Will my edits survive plugin updates? =

Yes. All CSS and JS files are stored in the `wp-content/uploads/` directory, which is never touched by plugin updates.

== Screenshots ==

1. Admin dashboard — toggle switch, stats cards, theme CSS variable overrides panel, and asset status table.
2. Floating block-editor button with CSS and JavaScript editor panels open.
3. Header Options section inside the WordPress Customizer with CodeMirror editors.
4. Typography manager in the Customizer — font family, size, weight, color, and spacing controls.
5. Theme CSS Variables panel — search, override, and reset any CSS custom property from your active theme.

== Changelog ==

= 1.0.0 =
* Initial release.

== Upgrade Notice ==

= 1.0.0 =
Initial release — no upgrade steps required.
