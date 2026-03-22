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
        var $form = $body.closest('form');
        
        function togglePricingFields() {
            if ( ! $form.length ) return;

            var $pricingMode = $form.find('select[id$="pricing_mode"]');
            if ( ! $pricingMode.length ) return;

            var mode = $pricingMode.val();
            
            var $freeKmTr    = $form.find('input[id$="free_km"]').closest('tr');
            var $ratePerKmTr = $form.find('input[id$="rate_per_km"]').closest('tr');
            var $tiersTr     = $body.closest('tr');

            if ( mode === 'tiered' ) {
                $freeKmTr.hide();
                $ratePerKmTr.hide();
                $tiersTr.show();
            } else {
                $freeKmTr.show();
                $ratePerKmTr.show();
                $tiersTr.hide();
            }
        }

        // Run once on load
        togglePricingFields();

        // Run on pricing mode change
        if ( $form.length ) {
            $form.on('change', 'select[id$="pricing_mode"]', togglePricingFields);
        }
    }

    // Init on DOM ready and when WC opens the modal
    $(document).ready(initTiersRepeater);
    $(document.body).on('wc_backbone_modal_loaded', initTiersRepeater);

})(jQuery);
