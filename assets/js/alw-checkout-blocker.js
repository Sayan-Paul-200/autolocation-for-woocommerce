/**
 * Auto-Location for WooCommerce — Checkout Blocker
 *
 * Observes the shipping info panel for "delivery not available" messages
 * and disables the Place Order button accordingly. Provides a dual-layer
 * block alongside the backend validation in woocommerce_checkout_process.
 *
 * @package Auto_Location_WooCommerce
 * @since   1.5.0
 */

(function(){
    'use strict';

    var config = window.alw_blocker_config || {};
    var i18n = config.i18n || {};
    var PANEL_ID = 'ds-shipping-info';
    var PLACE_BTN_SELECTOR = 'form.checkout input#place_order, form.checkout button#place_order, form.checkout button[name="woocommerce_checkout_place_order"]';

    function ensureBlockingUi() {
        if (document.getElementById('ds-delivery-block-message')) return;
        var review = document.querySelector('.woocommerce-checkout-review-order') || document.querySelector('.col-2, .checkout-right, .woocommerce-checkout-payment');
        var box = document.createElement('div');
        box.id = 'ds-delivery-block-message';
        box.innerText = i18n.delivery_block_message || 'Delivery not available to the selected location. Please choose a different address or contact support.';
        if (review) review.appendChild(box);
        else document.body.appendChild(box);
    }

    function setPlaceOrderDisabled(disabled, reasonText) {
        ensureBlockingUi();
        var box = document.getElementById('ds-delivery-block-message');
        if (box) box.style.display = disabled ? 'block' : 'none';
        if (reasonText && box) box.innerText = reasonText;

        var btns = document.querySelectorAll(PLACE_BTN_SELECTOR);
        if (btns && btns.length) {
            btns.forEach(function(b){
                try {
                    b.disabled = !!disabled;
                    if (disabled) {
                        b.classList.add('disabled');
                        b.setAttribute('aria-disabled', 'true');
                    } else {
                        b.classList.remove('disabled');
                        b.removeAttribute('aria-disabled');
                    }
                } catch(e){}
            });
        }
    }

    function checkPanelAndApplyBlock() {
        var panel = document.getElementById(PANEL_ID);
        if (!panel) return;
        var txt = panel.textContent || panel.innerText || '';
        var blocked = /not available|delivery not available|delivery unavailable/i.test(txt);
        if (blocked) setPlaceOrderDisabled(true, txt.trim());
        else setPlaceOrderDisabled(false, '');
    }

    function observePanel() {
        var panel = document.getElementById(PANEL_ID);
        if (!panel) return;
        var obs = new MutationObserver(function(){ checkPanelAndApplyBlock(); });
        obs.observe(panel, { childList: true, subtree: true, characterData: true });
    }

    document.addEventListener('DOMContentLoaded', function(){
        ensureBlockingUi();
        setTimeout(checkPanelAndApplyBlock, 600);
        var tries = 0;
        var waiter = setInterval(function(){
            var p = document.getElementById(PANEL_ID);
            if (p) {
                clearInterval(waiter);
                observePanel();
                checkPanelAndApplyBlock();
            } else {
                tries++;
                if (tries > 40) clearInterval(waiter);
            }
        }, 200);

        if (window.jQuery) {
            jQuery(document.body).on('updated_checkout', function(){ setTimeout(checkPanelAndApplyBlock, 150); });
        }
        
        var checkoutForm = document.querySelector('form.checkout');
        if (checkoutForm) {
            checkoutForm.addEventListener('submit', function(e){
                var panel = document.getElementById(PANEL_ID);
                var txt = panel ? (panel.textContent || '') : '';
                if (/not available|delivery not available/i.test(txt)) {
                    e.preventDefault();
                    e.stopImmediatePropagation();
                    setPlaceOrderDisabled(true, txt.trim() || (i18n.delivery_not_available || 'Delivery not available.'));
                }
            }, true);
        }
    });
})();
