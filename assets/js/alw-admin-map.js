/**
 * Auto-Location for WooCommerce — Admin Map Picker
 *
 * Interactive Google Map on the admin settings page for visually
 * selecting the store's coordinates instead of manual lat/lng entry.
 *
 * @package Auto_Location_WooCommerce
 * @since   2.0.0
 */

(function($, config) {
    'use strict';

    if (!config) return;

    var map, marker;
    var latField = document.querySelector('input[name="alw_store_lat"]');
    var lngField = document.querySelector('input[name="alw_store_lng"]');
    var mapEl    = document.getElementById('alw-admin-map');
    var apiKeyField = document.getElementById('alw_google_api_key');

    if (!latField || !lngField || !mapEl) return;

    function initAdminMap() {
        var lat = parseFloat(latField.value) || 0;
        var lng = parseFloat(lngField.value) || 0;
        var hasCoords = (lat !== 0 || lng !== 0);
        var center = hasCoords ? { lat: lat, lng: lng } : { lat: 20.5937, lng: 78.9629 };
        var zoom = hasCoords ? 15 : 5;

        map = new google.maps.Map(mapEl, {
            center: center,
            zoom: zoom,
            gestureHandling: 'cooperative',
            mapTypeControl: true,
            streetViewControl: false,
        });

        marker = new google.maps.Marker({
            position: center,
            map: map,
            draggable: true,
            title: config.i18n.store_location || 'Store Location',
        });

        // Drag end → update fields
        marker.addListener('dragend', function(e) {
            latField.value = e.latLng.lat().toFixed(7);
            lngField.value = e.latLng.lng().toFixed(7);
        });

        // Click map → move pin
        map.addListener('click', function(e) {
            marker.setPosition(e.latLng);
            latField.value = e.latLng.lat().toFixed(7);
            lngField.value = e.latLng.lng().toFixed(7);
        });

        // Field change → move pin
        function syncFromFields() {
            var lat = parseFloat(latField.value);
            var lng = parseFloat(lngField.value);
            if (isFinite(lat) && isFinite(lng)) {
                var pos = new google.maps.LatLng(lat, lng);
                marker.setPosition(pos);
                map.panTo(pos);
                if (map.getZoom() < 10) map.setZoom(15);
            }
        }
        latField.addEventListener('change', syncFromFields);
        lngField.addEventListener('change', syncFromFields);

        // "Use My Location" button
        var locBtn = document.getElementById('alw-admin-use-location');
        if (locBtn) {
            locBtn.addEventListener('click', function(e) {
                e.preventDefault();
                if (!navigator.geolocation) {
                    alert(config.i18n.geolocation_unsupported || 'Geolocation not supported.');
                    return;
                }
                locBtn.disabled = true;
                locBtn.textContent = config.i18n.getting_location || 'Getting location…';
                navigator.geolocation.getCurrentPosition(function(position) {
                    var lat = position.coords.latitude;
                    var lng = position.coords.longitude;
                    latField.value = lat.toFixed(7);
                    lngField.value = lng.toFixed(7);
                    var pos = new google.maps.LatLng(lat, lng);
                    marker.setPosition(pos);
                    map.panTo(pos);
                    map.setZoom(17);
                    locBtn.disabled = false;
                    locBtn.textContent = config.i18n.use_my_location || '📍 Use My Location';
                }, function() {
                    locBtn.disabled = false;
                    locBtn.textContent = config.i18n.use_my_location || '📍 Use My Location';
                    alert(config.i18n.location_failed || 'Could not get location.');
                }, { enableHighAccuracy: true });
            });
        }
    }

    var scriptInjected = false;

    function loadGoogleMaps(apiKey) {
        if (scriptInjected) return;
        scriptInjected = true;

        // Ensure button and description are visible
        var useLocBtn = document.getElementById('alw-admin-use-location');
        if (useLocBtn) useLocBtn.style.display = 'inline-block';
        var descTarget = document.getElementById('alw-admin-map-desc');
        if (descTarget) descTarget.style.display = 'block';

        if (typeof google !== 'undefined' && google.maps) {
            initAdminMap();
        } else {
            var s = document.createElement('script');
            s.src = 'https://maps.googleapis.com/maps/api/js?key=' + encodeURIComponent(apiKey);
            s.onload = initAdminMap;
            s.onerror = function() {
                mapEl.innerHTML = '<p style="padding:20px;color:#666;text-align:center;">' +
                    (config.i18n.map_load_failed || 'Could not load Google Maps. Please check your API key.') + '</p>';
                scriptInjected = false; // allow retry
            };
            document.head.appendChild(s);
        }
    }

    if (config.api_key) {
        loadGoogleMaps(config.api_key);
    } else if (apiKeyField) {
        // Listen for user to paste API key
        apiKeyField.addEventListener('input', function() {
            var val = this.value.trim();
            if (val.length > 25 && !scriptInjected) {
                loadGoogleMaps(val);
            }
        });
    }

})(jQuery, window.alw_admin_config || {});
