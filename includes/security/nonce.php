<?php
/**
 * SiteGlow — Nonce Helpers
 *
 * Centralises all nonce action names and provides thin wrapper functions for
 * creating and verifying nonces.  Keeping these in one place means:
 *   • The action name string is defined exactly once — no risk of typos across
 *     separate files.
 *   • The AJAX handlers in ajax.php and the enqueueing code in block-editor.php
 *     both reference the same constant, so a future rename only touches this
 *     file.
 *
 * Nonce actions used by the plugin:
 *   SITEGLOW_NONCE_DASHBOARD — dashboard toggle + theme-variable override saves.
 *   SITEGLOW_NONCE_EXPORT    — block-editor CSS/JS export (floating button save).
 *
 * @package SiteGlow
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

// ── Nonce action name constants ────────────────────────────────────────────

/** Action name for all dashboard AJAX requests (toggle editor, save theme vars). */
define( 'SITEGLOW_NONCE_DASHBOARD', 'siteglow_dashboard_nonce' );

/** Action name for the block-editor export AJAX request. */
define( 'SITEGLOW_NONCE_EXPORT', 'siteglow_export_nonce' );

// ── Create helpers ─────────────────────────────────────────────────────────

/**
 * Creates a nonce for dashboard AJAX calls.
 *
 * Use the returned string as the `nonce` value in `wp_localize_script` data
 * passed to the dashboard JS, and verify it with `siteglow_verify_dashboard_nonce()`.
 *
 * @since  1.0.0
 * @return string  WP nonce token.
 */
function siteglow_nonce_dashboard(): string {
    return wp_create_nonce( SITEGLOW_NONCE_DASHBOARD );
}

/**
 * Creates a nonce for the block-editor CSS/JS export AJAX call.
 *
 * Use the returned string in the `siteglowData.nonce` value passed to
 * siteglow-input-button.js, and verify it with `siteglow_verify_export_nonce()`.
 *
 * @since  1.0.0
 * @return string  WP nonce token.
 */
function siteglow_nonce_export(): string {
    return wp_create_nonce( SITEGLOW_NONCE_EXPORT );
}

// ── Verify helpers ─────────────────────────────────────────────────────────

/**
 * Verifies the dashboard nonce sent with an AJAX request.
 *
 * Calls `check_ajax_referer()` which will `wp_die()` with a -1 response if
 * the nonce is missing or invalid, halting execution before any data changes.
 * Expects the nonce in `$_REQUEST['nonce']`.
 *
 * @since  1.0.0
 * @return void
 */
function siteglow_verify_dashboard_nonce(): void {
    check_ajax_referer( SITEGLOW_NONCE_DASHBOARD, 'nonce' );
}

/**
 * Verifies the export nonce sent with an AJAX request.
 *
 * Calls `check_ajax_referer()` which will `wp_die()` with a -1 response if
 * the nonce is missing or invalid.  Expects the nonce in `$_REQUEST['nonce']`.
 *
 * @since  1.0.0
 * @return void
 */
function siteglow_verify_export_nonce(): void {
    check_ajax_referer( SITEGLOW_NONCE_EXPORT, 'nonce' );
}
