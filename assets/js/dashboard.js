/* globals jQuery, siteglowAdminData */
/**
 * SiteGlow — Dashboard admin interactions.
 *
 * All PHP-side values (ajaxUrl, nonce, translated strings) are injected by
 * `wp_localize_script` as `window.siteglowAdminData` so this file is pure JS with
 * no PHP interpolation.
 *
 * siteglowAdminData shape (see siteglow_enqueue_dashboard_assets() in dashboard.php):
 * {
 *   ajaxUrl     : string,   — wp-admin/admin-ajax.php
 *   nonce       : string,   — dashboard nonce
 *   i18n: {
 *     enabled      : string,  — "Enabled"
 *     disabled     : string,  — "Disabled"
 *     saving       : string,  — "Saving…"
 *     saveOverrides: string,  — "Save Overrides"
 *     reset        : string,  — "Reset"
 *   }
 * }
 *
 * @file    dashboard.js
 * @package SiteGlow
 * @since   1.0.0
 */
(function ($) {
    'use strict';

    if (typeof siteglowAdminData === 'undefined') return;

    var ajaxUrl = siteglowAdminData.ajaxUrl;
    var nonce   = siteglowAdminData.nonce;
    var i18n    = siteglowAdminData.i18n;

    /* ------------------------------------------------------------------ */
    /* Toggle floating editor on / off                                     */
    /* ------------------------------------------------------------------ */
    $('#siteglow-editor-toggle').on('change', function () {
        var enabled = this.checked ? 1 : 0;
        var $status = $('#siteglow-toggle-status');

        $.post(ajaxUrl, { action: 'siteglow_toggle_editor', nonce: nonce, enabled: enabled },
            function (res) {
                if (!res.success) return;
                if (enabled) {
                    $status.text(i18n.enabled).removeClass('disabled').addClass('enabled');
                } else {
                    $status.text(i18n.disabled).removeClass('enabled').addClass('disabled');
                }
            }
        );
    });

    /* ------------------------------------------------------------------ */
    /* Highlight override input when its value differs from theme default  */
    /* ------------------------------------------------------------------ */
    $(document).on('input', '.siteglow-var-input', function () {
        var $input  = $(this);
        var changed = $input.val() !== $input.data('default');
        $input.toggleClass('changed', changed);

        var $resetCell = $input.closest('tr').find('td:last-child');
        if (changed) {
            if (!$resetCell.find('.siteglow-reset-var').length) {
                $resetCell.html(
                    '<button type="button" class="siteglow-reset-var" data-default="' +
                    $input.data('default') + '">&#8635; ' + i18n.reset + '</button>'
                );
            }
        } else {
            $resetCell.find('.siteglow-reset-var').remove();
        }
    });

    /* ------------------------------------------------------------------ */
    /* Reset an individual variable back to the theme default              */
    /* ------------------------------------------------------------------ */
    $(document).on('click', '.siteglow-reset-var', function () {
        var $btn = $(this);
        var def  = $btn.data('default');
        $btn.closest('tr').find('.siteglow-var-input').val(def).removeClass('changed');
        $btn.remove();
    });

    /* ------------------------------------------------------------------ */
    /* Save CSS variable overrides via AJAX                                */
    /* ------------------------------------------------------------------ */
    $('#siteglow-save-vars').on('click', function () {
        var $btn = $(this).prop('disabled', true).text(i18n.saving);
        var vars = {};

        /* Collect ALL changed inputs regardless of search / scroll visibility */
        $('#siteglow-vars-table tbody tr.siteglow-var-row').each(function () {
            var $row   = $(this);
            var name   = $row.data('var');
            var $input = $row.find('.siteglow-var-input');
            if (name && $input.hasClass('changed')) {
                vars[name] = $input.val();
            }
        });

        $.post(ajaxUrl, { action: 'siteglow_save_theme_vars', nonce: nonce, vars: vars },
            function (res) {
                $btn.prop('disabled', false).text(i18n.saveOverrides);
                if (!res.success) return;

                /* Sync the .overridden class so the blue left-border reflects reality */
                $('#siteglow-vars-table tbody tr').each(function () {
                    var $row   = $(this);
                    var $input = $row.find('.siteglow-var-input');
                    $row.toggleClass('overridden', $input.hasClass('changed'));
                });

                $('#siteglow-vars-saved').show().delay(3000).fadeOut();
            }
        );
    });

    /* ------------------------------------------------------------------ */
    /* Live search — filter variable rows and collapse empty group headers  */
    /* ------------------------------------------------------------------ */
    $('#siteglow-var-search').on('input', function () {
        var term    = $(this).val().toLowerCase();
        var visible = 0;

        /* Show / hide data rows */
        $('#siteglow-vars-table tbody tr.siteglow-var-row').each(function () {
            var name = $(this).data('var').toLowerCase();
            var show = !term || name.indexOf(term) !== -1;
            $(this).toggle(show);
            if (show) visible++;
        });

        /* Hide group headers whose section has no visible rows */
        $('#siteglow-vars-table tbody tr.siteglow-group-header').each(function () {
            var $hdr     = $(this);
            var groupKey = $hdr.data('group');
            var hasVis   = $('#siteglow-vars-table tbody tr.siteglow-var-row[data-group="' + groupKey + '"]:visible').length > 0;
            $hdr.toggle(hasVis);
        });

        $('#siteglow-no-vars-msg').toggle(visible === 0);
    });

}(jQuery));
