<?php
/**
 * SiteGlow — Page Asset Initializer
 *
 * Hooks into `wp_insert_post` to create an empty CSS and JS placeholder file
 * in the uploads directory every time a new page or post is saved for the
 * first time.  This guarantees that the block-editor floating button always
 * has a target file to write to, even before any live edits have been made.
 *
 * File naming convention:
 *   uploads/siteglow/pages/css/{post-slug}.css
 *   uploads/siteglow/pages/js/{post-slug}.js
 *
 * Note: files are created with empty content (0 bytes).  The frontend loader
 * in block-editor.php checks `filesize() > 0` before enqueueing, so empty
 * placeholder files produce no HTTP requests on the live site.
 *
 * @package SiteGlow
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

// ============================================================
// Create empty CSS/JS files when a page or post is saved
// ============================================================

/**
 * Creates empty per-post CSS and JS asset files on first save.
 *
 * Only runs for `page` and `post` post types; ignores autosaves and posts
 * that have no slug yet (e.g. auto-drafts with an empty `post_name`).
 * Uses `file_exists()` guard so existing content is never overwritten.
 *
 * @since  1.0.0
 * @param  int      $post_id  The ID of the post being inserted or updated.
 * @param  \WP_Post $post     The full WP_Post object.
 * @param  bool     $update   Whether this is an update (true) or a new insert (false).
 * @return void
 */
function siteglow_init_page_assets( $post_id, $post, $update ) {
    if ( ! in_array( $post->post_type, [ 'page', 'post' ], true ) ) return;
    if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;

    $slug = $post->post_name;
    if ( empty( $slug ) ) return;

    $paths   = siteglow_upload_paths();
    $css_dir = $paths['dir'] . '/pages/css';
    $js_dir  = $paths['dir'] . '/pages/js';

    wp_mkdir_p( $css_dir );
    wp_mkdir_p( $js_dir );

    $css_file = "{$css_dir}/{$slug}.css";
    $js_file  = "{$js_dir}/{$slug}.js";

    if ( ! file_exists( $css_file ) ) siteglow_put_file( $css_file, '' );
    if ( ! file_exists( $js_file ) )  siteglow_put_file( $js_file,  '' );
}
add_action( 'wp_insert_post', 'siteglow_init_page_assets', 20, 3 );
