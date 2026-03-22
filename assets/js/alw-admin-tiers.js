/**
 * Auto-Location for WooCommerce — Admin Tiers Repeater
 *
 * Handles the add/remove/serialize logic for the distance tiers
 * repeater table in WooCommerce shipping method instance settings.
 *
 * @package Auto_Location_WooCommerce
 * @since   2.0.0
 */

(function($) {
    'use strict';

    function initTiersRepeater() {
        var $body  = $('#alw-tiers-body');
        var $input = $('textarea[id$="distance_tiers"]');
        var $addBtn = $('#alw-add-tier');

        if (!$body.length || !$input.length) return;

        // Load existing tiers
        var tiers = [];
        try { tiers = JSON.parse($input.val()) || []; } catch(e) { tiers = []; }

        function renderRows() {
            $body.empty();
            tiers.forEach(function(tier, idx) {
                var $row = $(
                    '<tr>' +
                    '<td><input type="number" step="0.01" min="0" class="small-text alw-tier-from" value="' + tier.from + '" /></td>' +
                    '<td><input type="number" step="0.01" min="0" class="small-text alw-tier-to" value="' + tier.to + '" /></td>' +
                    '<td><input type="number" step="0.01" min="0" class="small-text alw-tier-rate" value="' + tier.rate + '" /></td>' +
                    '<td><input type="number" step="0.01" min="0" class="small-text alw-tier-flat" value="' + tier.flat + '" /></td>' +
                    '<td><button type="button" class="button alw-remove-tier" title="Remove">&times;</button></td>' +
                    '</tr>'
                );
                $body.append($row);
            });
            syncToInput();
        }

        function syncToInput() {
            var data = [];
            $body.find('tr').each(function() {
                var $r = $(this);
                data.push({
                    from: parseFloat($r.find('.alw-tier-from').val()) || 0,
                    to:   parseFloat($r.find('.alw-tier-to').val())   || 0,
                    rate: parseFloat($r.find('.alw-tier-rate').val()) || 0,
                    flat: parseFloat($r.find('.alw-tier-flat').val()) || 0,
                });
            });
            $input.val(JSON.stringify(data));
        }

        // Add tier
        $addBtn.on('click', function() {
            var lastTo = 0;
            if (tiers.length > 0) {
                lastTo = tiers[tiers.length - 1].to || 0;
            }
            tiers.push({ from: lastTo, to: lastTo + 10, rate: 0, flat: 0 });
            renderRows();
        });

        // Remove tier (delegated)
        $body.on('click', '.alw-remove-tier', function() {
            var idx = $(this).closest('tr').index();
            tiers.splice(idx, 1);
            renderRows();
        });

        // On any input change
        $body.on('input change', 'input', function() {
            var idx = $(this).closest('tr').index();
            var $r  = $(this).closest('tr');
            tiers[idx] = {
                from: parseFloat($r.find('.alw-tier-from').val()) || 0,
                to:   parseFloat($r.find('.alw-tier-to').val())   || 0,
                rate: parseFloat($r.find('.alw-tier-rate').val()) || 0,
                flat: parseFloat($r.find('.alw-tier-flat').val()) || 0,
            };
            syncToInput();
        });

        renderRows();

        // ---------------------------------------------------------
        // Dynamic Field Visibility
        // ---------------------------------------------------------
        // ---------------------------------------------------------
        // Dynamic Field Visibility
        // ---------------------------------------------------------
        function togglePricingFields() {
            var $pricingMode = $('select[name*="pricing_mode"]');
            if ( ! $pricingMode.length ) $pricingMode = $('[id*="pricing_mode"]');
            
            if ( ! $pricingMode.length ) return;

            var mode = $pricingMode.val() || '';
            var isTiered = mode === 'tiered' || (typeof mode === 'object' && mode.indexOf('tiered') !== -1);
            
            var $freeKm    = $('input[name*="free_km"]');
            if (!$freeKm.length) $freeKm = $('[id*="free_km"]');
            
            var $ratePerKm = $('input[name*="rate_per_km"]');
            if (!$ratePerKm.length) $ratePerKm = $('[id*="rate_per_km"]');

            // Find closest wrapper (tr, or form-row)
            var $freeKmRow     = $freeKm.closest('tr, .form-row, .form-field').length ? $freeKm.closest('tr, .form-row, .form-field') : $freeKm.parent();
            var $ratePerKmRow  = $ratePerKm.closest('tr, .form-row, .form-field').length ? $ratePerKm.closest('tr, .form-row, .form-field') : $ratePerKm.parent();
            var $tiersRow      = $('#alw-tiers-wrap').closest('tr, .form-row, .form-field').length ? $('#alw-tiers-wrap').closest('tr, .form-row, .form-field') : $('#alw-tiers-wrap').parent();

            if ( isTiered ) {
                $freeKmRow.hide();
                $ratePerKmRow.hide();
                $tiersRow.show();
            } else {
                $freeKmRow.show();
                $ratePerKmRow.show();
                $tiersRow.hide();
            }
        }

        // Run once on load, use setTimeout to escape any Backbone render race conditions
        setTimeout(togglePricingFields, 50);

        // Run on pricing mode change (bind to body to catch all dynamic elements securely)
        $(document.body).off('change.alw_toggle').on('change.alw_toggle', 'select[name*="pricing_mode"], [id*="pricing_mode"]', togglePricingFields);
    }

    // Init on DOM ready and when WC opens the modal
    $(document).ready(initTiersRepeater);
    $(document.body).on('wc_backbone_modal_loaded', function() {
        setTimeout(initTiersRepeater, 50);
    });

})(jQuery);
