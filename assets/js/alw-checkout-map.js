/**
 * Auto-Location for WooCommerce — Checkout Map
 *
 * Interactive Google Maps integration for WooCommerce checkout.
 * Handles map rendering, distance calculation, shipping cost display,
 * and billing field synchronization.
 *
 * @package Auto_Location_WooCommerce
 * @since   1.5.0
 */

window.ALW = window.ALW || {};

(function(ALW, $, config) {
  'use strict';

  if (!config || !config.api_key) return;

  var API_KEY     = config.api_key;
  var STORE_LAT   = parseFloat(config.store_lat);
  var STORE_LNG   = parseFloat(config.store_lng);
  var FREE_KM     = parseFloat(config.free_km);
  var MAX_KM      = parseFloat(config.max_km);
  var RATE_PER_KM = parseFloat(config.rate_per_km);
  var ROUND_METHOD = config.round_method;
  var CURRENCY    = config.currency_symbol || '';
  var i18n        = config.i18n || {};
  var DEBOUNCE_MS = 700;
  var MAPS_WAIT_MAX_MS = 8000;
  var MAPS_WAIT_INTERVAL = 250;

  // --- Private State (scoped to IIFE, NOT on window) ---
  var mapsLoaded = false;
  var map = null;
  var storeMarker = null;
  var customerMarker = null;
  var geocoder = null;
  var userLocMarker = null;
  var userLocCircle = null;
  var connectionLine = null;

  function injectMapsScript(url, id, onload, onerror){
    if (document.getElementById(id)) {
      if (mapsLoaded && typeof onload === 'function') try{ onload(); }catch(e){}
      return;
    }
    var s = document.createElement('script');
    s.id = id;
    s.src = url;
    s.async = true;
    s.defer = true;
    s.onload = function(){
      mapsLoaded = true;
      if (typeof onload === 'function') onload();
    };
    s.onerror = function(){
      if (typeof onerror === 'function') onerror();
    };
    document.head.appendChild(s);
  }

  // --- Blue Dot, Circle & Line Logic ---
  function drawUserLocation(lat, lng, accuracy) {
      if (!map || typeof google === 'undefined') return;
      var pos = new google.maps.LatLng(lat, lng);
      
      if (!userLocMarker) {
          userLocMarker = new google.maps.Marker({
              map: map, position: pos, title: i18n.current_location || 'Your Current Location', zIndex: 999999,
              icon: { path: google.maps.SymbolPath.CIRCLE, scale: 8, fillColor: '#4285F4', fillOpacity: 1, strokeColor: '#ffffff', strokeWeight: 2 }
          });
      } else { userLocMarker.setPosition(pos); }

      if (!userLocCircle) {
          userLocCircle = new google.maps.Circle({
              map: map, center: pos, radius: accuracy || 50, fillColor: '#4285F4', fillOpacity: 0.15, strokeColor: '#4285F4', strokeOpacity: 0.3, strokeWeight: 1, clickable: false
          });
      } else { userLocCircle.setCenter(pos); userLocCircle.setRadius(accuracy || 50); }

      updateConnectionLine();
  }

  function updateConnectionLine() {
      if (!map || !userLocMarker || !customerMarker) return;
      var userPos = userLocMarker.getPosition();
      var pinPos = customerMarker.getPosition();
      if (!userPos || !pinPos) return;
      var path = [userPos, pinPos];
      if (!connectionLine) {
          connectionLine = new google.maps.Polyline({
              path: path, geodesic: true, strokeOpacity: 0,
              icons: [{ icon: { path: google.maps.SymbolPath.CIRCLE, scale: 3, fillColor: '#4285F4', fillOpacity: 1, strokeWeight: 0 }, offset: '0', repeat: '15px' }],
              map: map
          });
      } else { connectionLine.setPath(path); }
  }

  function haversineKm(lat1, lon1, lat2, lon2){
    var R = 6371;
    var dLat = (lat2 - lat1) * Math.PI / 180;
    var dLon = (lon2 - lon1) * Math.PI / 180;
    var a = Math.sin(dLat/2) * Math.sin(dLat/2) + Math.cos(lat1 * Math.PI/180) * Math.cos(lat2 * Math.PI/180) * Math.sin(dLon/2) * Math.sin(dLon/2);
    var c = 2 * Math.asin(Math.min(1, Math.sqrt(a)));
    return R * c;
  }

  function roundKm(km) {
    switch(ROUND_METHOD) { case 'floor': return Math.floor(km); case 'ceil': return Math.ceil(km); default: return Math.round(km); }
  }

  function updateShippingPanel(text, isError){
    var panel = document.getElementById('ds-shipping-info');
    if (!panel) {
      var mapWrap = document.getElementById('ds-billing-map-wrap');
      panel = document.createElement('div');
      panel.id = 'ds-shipping-info';
      if (mapWrap && mapWrap.parentNode) mapWrap.parentNode.insertBefore(panel, mapWrap.nextSibling);
      else document.body.appendChild(panel);
    }
    panel.textContent = text;
    panel.style.color = isError ? '#c00' : '#111';
  }

  function renderShippingInfo(placeTitle, addressLine, distance_km, calc){
    var panel = document.getElementById('ds-shipping-info');
    if (!panel) {
      var mapWrap = document.getElementById('ds-billing-map-wrap');
      panel = document.createElement('div');
      panel.id = 'ds-shipping-info';
      if (mapWrap && mapWrap.parentNode) mapWrap.parentNode.insertBefore(panel, mapWrap.nextSibling);
      else document.body.appendChild(panel);
    }

    placeTitle = placeTitle || (i18n.selected_location || 'Selected location');
    addressLine = addressLine || '';
    var distanceText = (typeof distance_km === 'number') ? distance_km.toFixed(2) + ' km' : '';

    var shippingText = '';
    var shippingColor = '#374151';
    if (!calc || calc.cost === null) {
      shippingText = i18n.delivery_not_available || ('DELIVERY NOT AVAILABLE (beyond ' + MAX_KM + ' km)');
      shippingColor = '#c53030';
    } else if (calc.cost === 0) {
      shippingText = i18n.shipping_free || 'Shipping: FREE';
      shippingColor = '#059669';
    } else {
      shippingText = (i18n.shipping_label || 'Shipping') + ': ' + CURRENCY + calc.cost;
      shippingColor = '#111';
    }

    var html = ''
      + '<div class="ds-info-instruction">' + escapeHtml(i18n.place_pin || 'Place the pin at exact delivery location') + '</div>'
      + '<div class="ds-info-flex">'
      +   '<div class="ds-info-icon">📍</div>'
      +   '<div class="ds-info-content">'
      +     '<div class="ds-place-title">' + escapeHtml(placeTitle) + '</div>'
      +     '<div class="ds-place-addr">' + escapeHtml(addressLine) + '</div>'
      +   '</div>'
      + '</div>'
      + '<div class="ds-info-footer">'
      +   '<div class="ds-dist-text">' + escapeHtml(distanceText) + '</div>'
      +   '<div class="ds-cost-text" style="color:' + shippingColor + ';">' + escapeHtml(shippingText) + '</div>'
      + '</div>';

    panel.innerHTML = html;
  }

  function escapeHtml(str){
    if (!str && str !== 0) return '';
    return String(str)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }

  function computeDrivingDistanceSafe(dest, callback){
    var waited = 0;
    var waiter = setInterval(function(){
      if (mapsLoaded && typeof google !== 'undefined' && google.maps && geocoder) {
        clearInterval(waiter);
        try {
          var directionsService = new google.maps.DirectionsService();
          var origin = new google.maps.LatLng(STORE_LAT, STORE_LNG);
          var request = { origin: origin, destination: dest, travelMode: google.maps.TravelMode.DRIVING, provideRouteAlternatives: false };
          directionsService.route(request, function(result, status){
            if (status === 'OK' && result && result.routes && result.routes[0] && result.routes[0].legs && result.routes[0].legs[0]) {
              var meters = result.routes[0].legs[0].distance.value;
              var km = meters / 1000.0;
              callback({ distance_km: km, meters: meters, raw: result }, null);
            } else { 
              console.warn('Directions failed: ' + status);
              callback(false, status || 'directions-failed'); 
            }
          });
        } catch(e){ callback(false, 'directions-exception:' + e.message); }
      } else {
        waited += MAPS_WAIT_INTERVAL;
        if (waited > MAPS_WAIT_MAX_MS) { clearInterval(waiter); callback(false, 'maps-not-ready'); }
      }
    }, MAPS_WAIT_INTERVAL);
  }

  function computeHaversineDistance(lat, lng){
    var km = haversineKm(STORE_LAT, STORE_LNG, lat, lng);
    return { distance_km: km, meters: Math.round(km * 1000), raw: null };
  }

  function calculateShippingFromDistance(distance_km){
    var rounded_km = roundKm(distance_km);
    var cost = 0;
    if (distance_km <= FREE_KM) { cost = 0; } 
    else if (distance_km > MAX_KM) { cost = null; } 
    else { cost = rounded_km * RATE_PER_KM; }
    return { cost: cost, rounded_km: rounded_km, raw_km: distance_km };
  }

  function debounce(fn, wait) {
    var t;
    return function() { clearTimeout(t); var args = arguments; t = setTimeout(function(){ fn.apply(null, args); }, wait); };
  }

  function getBillingDestination() {
    var latField = document.querySelector('input[name="billing_lat"]');
    var lngField = document.querySelector('input[name="billing_lng"]');
    var lat = latField ? parseFloat(latField.value) : NaN;
    var lng = lngField ? parseFloat(lngField.value) : NaN;
    if (isFinite(lat) && isFinite(lng)) return { lat: lat, lng: lng, type: 'coords' };
    
    var a1 = (document.querySelector('input[name="billing_address_1"]') || {}).value || '';
    var city = (document.querySelector('input[name="billing_city"]') || {}).value || '';
    var state = (document.querySelector('input[name="billing_state"]') || document.querySelector('select[name="billing_state"]') || {}).value || '';
    var postcode = (document.querySelector('input[name="billing_postcode"]') || {}).value || '';
    var country = (document.querySelector('input[name="billing_country"]') || document.querySelector('select[name="billing_country"]') || {}).value || '';
    var addr = [a1, city, state, postcode, country].filter(Boolean).join(', ');
    if (addr.trim().length === 0) return null;
    return { address: addr, type: 'address' };
  }

  function recomputeAndShow() {
    var dest = getBillingDestination();
    if (!dest) { updateShippingPanel(i18n.enter_address || 'Enter billing address or use the map to compute delivery distance.'); return; }
    var directionsDest = (dest.type === 'coords' && typeof google !== 'undefined' && google.maps) ? new google.maps.LatLng(dest.lat, dest.lng) : (dest.type === 'coords' ? { lat: dest.lat, lng: dest.lng } : dest.address);

    computeDrivingDistanceSafe(directionsDest, function(dirRes, dirErr){
      var distance_obj;
      
      if (dirRes) {
        distance_obj = { km: dirRes.distance_km, meters: dirRes.meters, raw: dirRes.raw };
        continueAfterDistance(distance_obj, dest);
      } else {
        if (dest.type === 'coords') {
            distance_obj = computeHaversineDistance(dest.lat, dest.lng);
            continueAfterDistance(distance_obj, dest);
        } else {
           if (mapsLoaded && geocoder) {
             geocoder.geocode({ address: dest.address }, function(results, status){
               if (status === 'OK' && results && results[0]) {
                 var loc = results[0].geometry.location;
                 var h2 = computeHaversineDistance(loc.lat(), loc.lng());
                 continueAfterDistance({ km: h2.distance_km, meters: h2.meters, raw: results[0] }, dest);
               } else { updateShippingPanel(i18n.cannot_compute || 'Could not compute distance. Please use the map.'); }
             });
           } else { updateShippingPanel(i18n.maps_not_ready || 'Maps not ready. Please try again.'); }
        }
      }
    });

    function continueAfterDistance(distance_obj, dest) {
      var distance_raw = Number(distance_obj.km);
      var calc = calculateShippingFromDistance(distance_raw);
      var placeTitle = '';
      var addressLine = '';
      try {
        if (distance_obj.raw && distance_obj.raw.routes && distance_obj.raw.routes[0] && distance_obj.raw.routes[0].legs) {
          var leg = distance_obj.raw.routes[0].legs[0];
          if (leg.end_address) addressLine = leg.end_address;
          if (leg.end_address) placeTitle = leg.end_address.split(',')[0];
        } else if (distance_obj.raw && distance_obj.raw.formatted_address) { addressLine = distance_obj.raw.formatted_address; }
      } catch(e){}

      if (!addressLine) {
         var a1 = (document.querySelector('input[name="billing_address_1"]') || {}).value || '';
         var city = (document.querySelector('input[name="billing_city"]') || {}).value || '';
         if (!placeTitle) placeTitle = city || (i18n.selected_location || 'Selected location');
         addressLine = i18n.approx_location || 'Approx location'; 
      }

      renderShippingInfo(placeTitle, addressLine, distance_raw, calc);
      
      if (dest.type === 'coords') { setHiddenLatLng(dest.lat, dest.lng, distance_raw); } 
      else if (distance_obj.raw && distance_obj.raw.routes && distance_obj.raw.routes[0]) {
        try {
          var leg2 = distance_obj.raw.routes[0].legs[0];
          if (leg2 && leg2.end_location) setHiddenLatLng( leg2.end_location.lat(), leg2.end_location.lng(), distance_raw );
        } catch(e){}
      }
    }
  } 

  function setHiddenLatLng(lat, lng, dist){
    var latField = document.querySelector('input[name="billing_lat"]');
    var lngField = document.querySelector('input[name="billing_lng"]');
    var distField = document.querySelector('input[name="billing_distance"]');
    
    if (latField) latField.value = lat;
    if (lngField) lngField.value = lng;
    if (distField && typeof dist !== 'undefined') distField.value = dist;

    if ($) try{ $('body').trigger('update_checkout'); }catch(e){}
  }

  var debouncedRecompute = debounce(recomputeAndShow, DEBOUNCE_MS);

  function attachBillingFieldListeners(){
    var selectors = ['input[name="billing_address_1"]', 'input[name="billing_city"]', 'input[name="billing_postcode"]', 'input[name="billing_state"]', 'select[name="billing_state"]', 'input[name="billing_country"]', 'select[name="billing_country"]'];
    
    function handleAddressChange(e) {
      if (e && e.isTrusted) {
        var latField = document.querySelector('input[name="billing_lat"]');
        var lngField = document.querySelector('input[name="billing_lng"]');
        if (latField) latField.value = '';
        if (lngField) lngField.value = '';
      }
      debouncedRecompute();
    }

    selectors.forEach(function(sel){
      var el = document.querySelector(sel);
      if (el) { el.addEventListener('input', handleAddressChange); el.addEventListener('change', handleAddressChange); }
    });
    var latField = document.querySelector('input[name="billing_lat"]');
    if (latField) latField.addEventListener('input', debouncedRecompute);
  }

  function initMap(){
    try {
      var mapEl = document.getElementById('ds-billing-map');
      if (!mapEl) return;
      var storeLatLng = { lat: STORE_LAT, lng: STORE_LNG };
      map = new google.maps.Map(mapEl, { center: storeLatLng, zoom: 17, gestureHandling: 'greedy' });
      // Custom Blue Dot for Store
      var storeIcon = {
          path: google.maps.SymbolPath.CIRCLE, scale: 7, fillColor: '#4285F4', fillOpacity: 1, strokeColor: '#ffffff', strokeWeight: 2
      };
      storeMarker = new google.maps.Marker({ position: storeLatLng, map: map, title: i18n.store_label || 'Store', icon: storeIcon });

      // Custom Orange Pin for Delivery Location
      var customerIcon = {
          path: 'M0-48c-9.8 0-17.7 7.8-17.7 17.4 0 15.5 17.7 30.6 17.7 30.6s17.7-15.4 17.7-30.6c0-9.6-7.9-17.4-17.7-17.4z M0-36.6c3.2 0 5.8 2.5 5.8 5.6 0 3.1-2.6 5.6-5.8 5.6-3.2 0-5.8-2.5-5.8-5.6 0-3.1 2.6-5.6 5.8-5.6z',
          fillColor: '#FF5722', fillOpacity: 1, strokeColor: '#ffffff', strokeWeight: 1.5, scale: 0.8,
          labelOrigin: new google.maps.Point(0, -31)
      };
      
      customerMarker = new google.maps.Marker({
        map: map, position: map.getCenter() || storeLatLng, visible: true, draggable: false, clickable: false, title: i18n.delivery_location || 'Delivery location', icon: customerIcon
      });

      geocoder = new google.maps.Geocoder();

      map.addListener('center_changed', function(){
        try {
           var center = map.getCenter();
           if (center && customerMarker) { customerMarker.setPosition(center); updateConnectionLine(); }
        } catch(e) {}
      });

      var _last_geocode = null;
      map.addListener('idle', function(){
        try {
          var center = map.getCenter();
          if (!center) return;
          var lat = center.lat();
          var lng = center.lng();
          var key = lat.toFixed(6) + ',' + lng.toFixed(6);
          if (_last_geocode === key) return;
          _last_geocode = key;
          
          var latField = document.querySelector('input[name="billing_lat"]');
          var lngField = document.querySelector('input[name="billing_lng"]');
          if (latField) latField.value = lat;
          if (lngField) lngField.value = lng;

          recomputeAndShow(); 
          updateConnectionLine();

          if (geocoder) {
            geocoder.geocode({ location: { lat: lat, lng: lng } }, function(results, status){
              if (status === 'OK' && results && results[0]) {
                 var comp = results[0].address_components;
                 function getComp(type){ for (var i=0;i<comp.length;i++){ if (comp[i].types.indexOf(type) !== -1) return comp[i]; } return null; }
                 var streetNumber = getComp('street_number');
                 var route = getComp('route');
                 var sublocality = getComp('sublocality') || getComp('sublocality_level_1') || getComp('neighborhood');
                 var locality = getComp('locality') || getComp('postal_town') || getComp('administrative_area_level_2');
                 var administrative = getComp('administrative_area_level_1');
                 var postal = getComp('postal_code');
                 var country = getComp('country');
                 var addressLine1 = '';
                 if (streetNumber && route) addressLine1 = streetNumber.long_name + ' ' + route.long_name;
                 else if (route && sublocality) addressLine1 = route.long_name + ', ' + (sublocality.long_name || '');
                 else addressLine1 = results[0].formatted_address || '';

                 var billing_address_1 = document.querySelector('input[name="billing_address_1"]');
                 var billing_city = document.querySelector('input[name="billing_city"]');
                 var billing_postcode = document.querySelector('input[name="billing_postcode"]');
                 var billing_state = document.querySelector('select[name="billing_state"], input[name="billing_state"]');
                 var billing_country = document.querySelector('select[name="billing_country"], input[name="billing_country"]');

                 if (billing_address_1) billing_address_1.value = addressLine1;
                 if (billing_city && locality) billing_city.value = locality.long_name || locality.short_name;
                 if (billing_postcode && postal) billing_postcode.value = postal.long_name;
                 if (billing_state && administrative) try{ billing_state.value = administrative.short_name || administrative.long_name; var ev = document.createEvent('HTMLEvents'); ev.initEvent('change', false, true); billing_state.dispatchEvent(ev); }catch(e){}
                 if (billing_country && country) try{ billing_country.value = country.short_name || country.long_name; var ev2 = document.createEvent('HTMLEvents'); ev2.initEvent('change', false, true); billing_country.dispatchEvent(ev2); }catch(e){}
              }
            });
          }
        } catch(e){}
      });

      map.addListener('click', function(e){ try { map.panTo(e.latLng); } catch(e){} });

      // === Current Location Control ===
      (function(){
        function centerMapOnBrowserLocation() {
          if (!navigator.geolocation) return;
          navigator.geolocation.getCurrentPosition(function(position){
            var lat = position.coords.latitude;
            var lng = position.coords.longitude;
            var acc = position.coords.accuracy;
            drawUserLocation(lat, lng, acc);
            try { map.panTo(new google.maps.LatLng(lat, lng)); map.setZoom(17); } catch(e){}
          }, function(err){}, { enableHighAccuracy: true });
        }

        var controlDiv = document.createElement('div');
        controlDiv.className = 'ds-map-control-wrap'; 
        
        var controlBtn = document.createElement('button');
        controlBtn.type = 'button'; controlBtn.title = i18n.current_location || 'Current location'; 
        controlBtn.className = 'ds-map-current-loc-btn'; 

        var iconWrap = document.createElement('span');
        iconWrap.className = 'ds-loc-btn-icon';
        iconWrap.innerHTML = '&#8982;';
        
        var label = document.createElement('span');
        label.className = 'ds-loc-btn-label';
        label.textContent = i18n.current_location || 'Current location';
        
        controlBtn.appendChild(iconWrap); controlBtn.appendChild(label); controlDiv.appendChild(controlBtn);

        try { map.controls[google.maps.ControlPosition.BOTTOM_CENTER].push(controlDiv); } catch (e) {}

        controlBtn.addEventListener('click', function(e){
          e.preventDefault(); controlBtn.disabled = true;
          var originalText = label.textContent; label.textContent = i18n.getting_location || 'Getting location…';
          if (!navigator.geolocation) { alert(i18n.geolocation_unsupported || 'Geolocation not supported.'); controlBtn.disabled = false; label.textContent = originalText; return; }
          navigator.geolocation.getCurrentPosition(function(position){
            var lat = position.coords.latitude;
            var lng = position.coords.longitude;
            var acc = position.coords.accuracy;
            drawUserLocation(lat, lng, acc);
            try { map.panTo(new google.maps.LatLng(lat, lng)); map.setZoom(17); } catch(e){}
            label.textContent = originalText; controlBtn.disabled = false;
          }, function(err){ controlBtn.disabled = false; label.textContent = originalText; alert(i18n.location_failed || 'Could not get location.'); }, { enableHighAccuracy: true });
        });
        
        try { centerMapOnBrowserLocation(); } catch(e) {}
      })();
      // === End Control ===

      mapsLoaded = true;
    } catch (e) { console.error('ALW initMap exception', e); }
  }

  function ensureMapInjection(){
    if (typeof google !== 'undefined' && google.maps && mapsLoaded) return;
    var mapsUrl = "https://maps.googleapis.com/maps/api/js?key=" + encodeURIComponent(API_KEY) + "&libraries=places";
    injectMapsScript(mapsUrl, "ds-google-maps-script",
      function(){ 
        if (typeof ALW.initMap === 'function') { try { ALW.initMap(); } catch(e){} } 
        else { mapsLoaded = true; }
      },
      function(){ console.error('Google Maps script failed to load.'); }
    );
  }

  document.addEventListener('DOMContentLoaded', function(){
    var billingFields = document.querySelector('.woocommerce-billing-fields') || document.querySelector('form.checkout');
    if (!billingFields) return;

    if (!document.getElementById('ds-billing-map')) {
      var mapWrap = document.createElement('div');
      mapWrap.id = 'ds-billing-map-wrap';
      mapWrap.innerHTML = '<div id="ds-billing-map"></div>';
      billingFields.insertBefore(mapWrap, billingFields.firstChild);
    }

    var btn = document.getElementById('ds-use-location-billing-btn');
    if (!btn) {
        btn = document.createElement('button');
        btn.type = 'button';
        btn.id = 'ds-use-location-billing-btn';
        btn.textContent = i18n.use_my_location || 'Use my location for billing address';
        btn.className = 'button';
        billingFields.insertBefore(btn, billingFields.firstChild);
    }

    attachBillingFieldListeners();
    updateShippingPanel(i18n.enter_address || 'Enter billing address or pick location on the map to calculate shipping.');
    ensureMapInjection();
    setTimeout(function(){ try{ recomputeAndShow(); }catch(e){} }, 500);

    btn.addEventListener('click', function(e){
      e.preventDefault();
      if (!navigator.geolocation) return alert(i18n.geolocation_unsupported || 'Geolocation not supported.');
      btn.disabled = true;
      btn.textContent = i18n.getting_location || 'Getting location…';
      navigator.geolocation.getCurrentPosition(function(position){
        var lat = position.coords.latitude;
        var lng = position.coords.longitude;
        var acc = position.coords.accuracy;
        drawUserLocation(lat, lng, acc);
        if (mapsLoaded && map) {
           map.panTo(new google.maps.LatLng(lat, lng));
           map.setZoom(14);
        } else { setHiddenLatLng(lat, lng); } 
        btn.textContent = i18n.location_set || 'Location set';
        setTimeout(function(){ btn.textContent = i18n.use_my_location || 'Use my location for billing address'; btn.disabled = false; }, 1400);
      }, function(err){
        btn.disabled = false;
        btn.textContent = i18n.use_my_location || 'Use my location for billing address';
        alert(i18n.location_failed || 'Could not get location.');
      }, { enableHighAccuracy: true });
    });
  });

  // --- Public API ---
  ALW.recomputeShipping = recomputeAndShow;
  ALW.initMap = initMap;
  ALW.mapsLoaded = function() { return mapsLoaded; };

})(window.ALW, jQuery, window.alw_checkout_config || {});
