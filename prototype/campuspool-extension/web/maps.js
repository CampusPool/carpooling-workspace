import { createState, setSelection, updateAddress, invalidate, confirm, beginRoute, acceptRoute, setMode, inServiceArea, pointFromLocation } from './maps-state.mjs';

const state = createState();
const $ = (id) => document.getElementById(id);
const originInput = $('origin-input');
const destinationInput = $('destination-input');
const inputs = { origin: originInput, destination: destinationInput };
let map;
let markers = {};
let polyline;
let mapsReady;
let requestTimer;
let geocoder;
let lastRouteRequest = false;
const trackingView = new URLSearchParams(window.location.search).get('view') === 'tracking';
let trackingMarker = null, trackingExpiryTimer = null;
const bounds = { north: 13.35, south: 12.65, east: 77.95, west: 77.2 };

function say(message, error = false) { const node = $('status'); node.textContent = message; node.dataset.error = error ? 'true' : 'false'; }
const pickerMode = new URLSearchParams(location.search).get('picker');
function postPickerSelection(endpoint) {
  if (pickerMode !== endpoint || window.parent === window) return;
  const point = state.endpoints[endpoint];
  if (!point) return;
  try { if (location.origin !== window.parent.location.origin) return; } catch { return; }
  window.parent.postMessage({ type: 'private-journey-selection', endpoint, point: { lat: point.lat, lng: point.lng }, source: 'map_pin' }, location.origin);
}
function clearMap() { if (polyline) { polyline.setMap(null); polyline = null; } }
function refreshButtons() { $('route').disabled = !state.confirmed.origin || !state.confirmed.destination || !state.endpoints.origin || !state.endpoints.destination; }
function renderEndpoint(endpoint) { const point = state.endpoints[endpoint]; $(endpoint + '-picked').textContent = point ? (point.address || `${point.lat.toFixed(5)}, ${point.lng.toFixed(5)}`) + (state.confirmed[endpoint] ? ' · confirmed' : ' · confirm this place') : 'Not confirmed'; refreshButtons(); }
function placeMarker(endpoint, point) { if (!map || !google?.maps?.marker) return; if (markers[endpoint]) markers[endpoint].map = null; markers[endpoint] = new google.maps.marker.AdvancedMarkerElement({ map, position: point, gmpDraggable: true, title: endpoint === 'origin' ? 'Origin' : 'Destination' }); markers[endpoint].addListener('dragend', (event) => { const next = pointFromLocation(event.latLng); choose(endpoint, next, 'Map pin'); }); }
function choose(endpoint, point, address, reverse = true) {
  invalidate(state, endpoint);
  if (!point || !inServiceArea(point)) { if (markers[endpoint]) { markers[endpoint].map = null; markers[endpoint] = null; } renderEndpoint(endpoint); clearMap(); $('result').textContent = ''; say('That pin is outside the Bengaluru service area.', true); return; }
  setSelection(state, endpoint, point, address);
  const selectionGeneration = state.generation;
  placeMarker(endpoint, point); map?.panTo(point); renderEndpoint(endpoint); clearMap(); $('result').textContent = '';
  say('Location selected. Confirm it when the pin is correct. Resolving the address…');
  if (!reverse) { say('Location selected. Confirm it when the pin is correct.'); return; }
  reverseGeocode(point).then((label) => {
    if (selectionGeneration !== state.generation || !label) return;
    updateAddress(state, endpoint, label); renderEndpoint(endpoint);
    say('Location selected. Confirm it when the pin is correct.');
  }).catch((error) => {
    if (selectionGeneration !== state.generation) return;
    const status = String(error?.code || error?.status || error?.message || '').toUpperCase();
    say(status.includes('ZERO_RESULTS') ? 'No address was found for this pin. Confirm the map location to continue.' : status.includes('OVER_QUERY_LIMIT') || status.includes('REQUEST_DENIED') || status.includes('QUOTA') ? 'Address lookup is unavailable for this demo. Confirm the map location to continue.' : 'The address could not be resolved. Confirm the map location to continue.', true);
  });
}

async function reverseGeocode(point) {
  if (!geocoder) throw new Error('Reverse geocoding is unavailable.');
  let lastError;
  for (let attempt = 0; attempt < 2; attempt += 1) {
    try {
      const response = await geocoder.geocode({ location: point, fulfillOnZeroResults: true });
      const result = response.results?.[0];
      if (result?.formatted_address) return result.formatted_address;
      if (response.plus_code?.compound_code) return response.plus_code.compound_code;
      const noResult = new Error('No address found.');
      noResult.code = 'ZERO_RESULTS';
      throw noResult;
    } catch (error) {
      lastError = error;
      const status = String(error?.status || error?.code || error?.message || '').toUpperCase();
      const transient = status.includes('UNKNOWN_ERROR') || status === 'ERROR' || status.includes('NETWORK') || status.includes('TIMEOUT');
      if (attempt === 0 && transient) await new Promise((resolve) => setTimeout(resolve, 350));
      else break;
    }
  }
  throw lastError || new Error('Reverse geocoding failed.');
}

function providerStatus(event) {
  return String(event?.error?.status || event?.error?.code || event?.detail?.status || event?.detail?.code || event?.status || '').toUpperCase();
}

function announcePlaceProviderError(event) {
  const status = providerStatus(event);
  const quota = status.includes('OVER_QUERY_LIMIT') || status.includes('RESOURCE_EXHAUSTED') || status.includes('QUOTA');
  const denied = status.includes('REQUEST_DENIED') || status.includes('PERMISSION');
  say(quota ? 'Address search is temporarily unavailable because the demo search limit was reached. Click the map or drag a pin to choose a location.' : denied ? 'Address search is unavailable for this demo. Click the map or drag a pin to choose a location.' : 'Address search is unavailable right now. Click the map or drag a pin to choose a location.', true);
}

async function loadGoogle() {
  const response = await fetch('/mobility-pilot/maps-config', { cache: 'no-store', signal: AbortSignal.timeout(7000) });
  if (!response.ok) throw new Error('Map configuration is unavailable.');
  const config = await response.json();
  if (!config.browser_key) throw new Error('Map access is not configured for this evaluation.');
  let scriptResolve; let scriptReject;
  const scriptLoaded = new Promise((resolve, reject) => { scriptResolve = resolve; scriptReject = reject; });
  window.__mobilityMapsReady = () => scriptResolve();
  const script = document.createElement('script');
  script.src = `https://maps.googleapis.com/maps/api/js?key=${encodeURIComponent(config.browser_key)}&v=weekly&loading=async&callback=__mobilityMapsReady`;
  script.async = true; script.onerror = () => scriptReject(new Error('Google Maps could not load.'));
  document.head.appendChild(script);
  await Promise.race([scriptLoaded, new Promise((_, reject) => setTimeout(() => reject(new Error('Google Maps timed out.')), 10000))]);
  const [mapsLib, markerLib, placesLib, routesLib] = await Promise.all([google.maps.importLibrary('maps'), google.maps.importLibrary('marker'), google.maps.importLibrary('places'), google.maps.importLibrary('routes')]);
  const geocodingLib = await google.maps.importLibrary('geocoding').catch(() => ({}));
  return { ...mapsLib, ...markerLib, ...placesLib, ...routesLib, ...geocodingLib, mapId: config.map_id || 'DEMO_MAP_ID' };
}

async function boot() {
  try {
    const api = await (mapsReady ||= loadGoogle());
    geocoder = api.Geocoder ? new api.Geocoder() : null;
    map = new google.maps.Map($('map'), { center: { lat: 12.9716, lng: 77.5946 }, zoom: 11, mapId: api.mapId, clickableIcons: false, streetViewControl: false, fullscreenControl: false });
    map.addListener('click', (event) => { if (trackingView) return; const endpoint = document.querySelector('input[name="active-endpoint"]:checked')?.value || 'origin'; choose(endpoint, pointFromLocation(event.latLng), 'Map pin'); });
    const selector = document.createElement('fieldset'); selector.className = 'endpoint-selector'; const legend = document.createElement('legend'); legend.textContent = 'Map click sets'; selector.append(legend); for (const endpoint of ['origin', 'destination']) { const label = document.createElement('label'); const radio = document.createElement('input'); radio.type = 'radio'; radio.name = 'active-endpoint'; radio.value = endpoint; radio.checked = endpoint === 'origin'; label.append(radio, document.createTextNode(endpoint === 'origin' ? ' Origin' : ' Destination')); selector.append(label); } $('route').before(selector);
    if (trackingView) {
      document.querySelector('.intro h1').textContent = 'Your driver, privately.';
      document.querySelector('.intro > p:last-child').textContent = 'Google map display · Only the signed-in trip participant page supplies locations. A stale position is hidden after 30 seconds.';
      document.querySelector('.panel').hidden = true;
      const clearTracking = () => { if (trackingMarker) trackingMarker.map = null; trackingMarker = null; clearTimeout(trackingExpiryTimer); };
      window.addEventListener('message', event => {
        if (event.origin !== window.location.origin || event.source !== window.parent || window.parent === window || event.data?.type !== 'mobility-private-tracking') return;
        clearTracking();
        const point = event.data.location;
        const age = Date.now() - Date.parse(String(point?.recorded_at));
        if (!point || !inServiceArea(point) || !Number.isFinite(age) || age < -30000 || age > 30000 || !Number.isFinite(point.accuracy_meters) || point.accuracy_meters <= 0 || point.accuracy_meters > 500) return;
        trackingMarker = new google.maps.marker.AdvancedMarkerElement({ map, position: {lat: point.lat,lng: point.lng}, title: 'Driver location · accuracy ±' + Math.round(point.accuracy_meters) + ' m' });
        map.panTo({lat:point.lat,lng:point.lng}); if (map.getZoom() < 15) map.setZoom(15);
        trackingExpiryTimer = setTimeout(clearTracking, Math.max(1, Math.min(30000, 30000 - age)));
      });
      window.addEventListener('pagehide', clearTracking);
      // Timers can be suspended in a background tab; discard the old marker
      // immediately on resuming until the participant page supplies a fresh fix.
      document.addEventListener('visibilitychange', clearTracking);
      return;
    }
    for (const [endpoint, input] of Object.entries(inputs)) {
      input.locationRestriction = bounds;
      input.addEventListener('input', () => { invalidate(state, endpoint); if (markers[endpoint]) { markers[endpoint].map = null; markers[endpoint] = null; } renderEndpoint(endpoint); clearMap(); $('result').textContent = ''; say('Search changed. Confirm this location again.'); });
      input.addEventListener('gmp-error', announcePlaceProviderError);
      input.addEventListener('gmp-select', async (event) => { const prediction = event.placePrediction; if (!prediction) return; invalidate(state, endpoint); if (markers[endpoint]) { markers[endpoint].map = null; markers[endpoint] = null; } renderEndpoint(endpoint); clearMap(); $('result').textContent = ''; const selectionGeneration = state.generation; const place = prediction.toPlace(); try { await place.fetchFields({ fields: ['formattedAddress', 'location'] }); if (selectionGeneration !== state.generation) return; const point = pointFromLocation(place.location); choose(endpoint, point, place.formattedAddress || 'Selected place', false); } catch { if (selectionGeneration === state.generation) say('Could not read that place. Try another result.', true); } });
      $(endpoint + '-confirm').addEventListener('click', () => { if (!confirm(state, endpoint)) { say('Choose a valid Bengaluru location before confirming.', true); return; } renderEndpoint(endpoint); postPickerSelection(endpoint); say(endpoint === 'origin' ? 'Origin confirmed.' : 'Destination confirmed. You can show the route.'); });
    }
    $('mode').addEventListener('change', () => { setMode(state, $('mode').value); clearMap(); $('result').textContent = ''; refreshButtons(); say('Travel mode changed. Show the route again to recalculate.'); });
    $('reset').addEventListener('click', () => { invalidate(state); Object.values(inputs).forEach((input) => { input.value = ''; }); Object.keys(markers).forEach((key) => { if (markers[key]) markers[key].map = null; }); markers = {}; clearMap(); $('result').textContent = ''; say('Planner reset.'); renderEndpoint('origin'); renderEndpoint('destination'); });
    $('origin-current').addEventListener('click', () => { if (!navigator.geolocation) { say('Location is unavailable in this browser. Search for a place instead.', true); return; } invalidate(state, 'origin'); if (markers.origin) { markers.origin.map = null; markers.origin = null; } renderEndpoint('origin'); clearMap(); $('result').textContent = ''; const locationGeneration = state.generation; say('Waiting for your location…'); navigator.geolocation.getCurrentPosition((position) => { if (locationGeneration !== state.generation) return; const point = { lat: position.coords.latitude, lng: position.coords.longitude }; if (!inServiceArea(point)) { say('Your current location is outside the Bengaluru service area.', true); return; } choose('origin', point, `Current location · accuracy ${Math.round(position.coords.accuracy)} m`); }, () => { if (locationGeneration === state.generation) say('Could not read your location. Search for your origin instead.', true); }, { enableHighAccuracy: true, timeout: 8000, maximumAge: 0 }); });
    $('route').addEventListener('click', requestRoute);
    $('route-retry').addEventListener('click', () => { if (lastRouteRequest) requestRoute(); });
    say('Search for two places to begin.');
  } catch (error) { say(error.message || 'Map setup failed. Check configuration and try again.', true); }
}

function routeTimeout() {
  return new Promise((resolve, reject) => {
    setTimeout(() => reject(new Error('Route request timed out.')), 10000);
  });
}

async function computeRoute(origin, destination, mode) {
  const response = await Promise.race([
    google.maps.routes.Route.computeRoutes({
      origin: { location: origin },
      destination: { location: destination },
      travelMode: mode,
      fields: ['path', 'distanceMeters', 'durationMillis', 'warnings'],
    }),
    routeTimeout(),
  ]);
  const route = response.routes?.[0];
  if (!route?.path?.length) throw new Error('No route was returned.');
  return route;
}

function renderRoute(route, mode) {
  polyline = new google.maps.Polyline({ path: route.path, map, strokeColor: '#087f78', strokeOpacity: .9, strokeWeight: 5 });
  const km = (route.distanceMeters / 1000).toFixed(1);
  const minutes = Math.max(1, Math.round(route.durationMillis / 60000));
  $('result').replaceChildren();
  const title = document.createElement('span');
  title.textContent = `${km} km · about ${minutes} min`;
  const note = document.createElement('small');
  note.textContent = mode === 'WALKING' ? 'Walking route · may lack sidewalks or pedestrian paths. Check actual access; times are estimates.' : mode === 'TWO_WHEELER' ? 'Motorcycle route · use caution; suggested roads may be incomplete. Times are estimates.' : 'Driving route · times are estimates';
  $('result').append(title, note);
  for (const warning of route.warnings || []) {
    const line = document.createElement('small');
    line.textContent = String(warning);
    $('result').append(line);
  }
  const routeBounds = new google.maps.LatLngBounds();
  route.path.forEach((point) => routeBounds.extend(point));
  map.fitBounds(routeBounds, 56);
}

async function requestRoute() {
  lastRouteRequest = true;
  const generation = beginRoute(state);
  if (generation === null) { say('Confirm both Bengaluru locations first.', true); return; }
  const origin = { lat: state.endpoints.origin.lat, lng: state.endpoints.origin.lng };
  const destination = { lat: state.endpoints.destination.lat, lng: state.endpoints.destination.lng };
  const mode = state.mode;
  clearTimeout(requestTimer);
  clearMap();
  $('result').textContent = '';
  say('Finding a route…');
  $('route').disabled = true;
  requestTimer = setTimeout(async () => {
    let lastError;
    for (let attempt = 0; attempt < 2; attempt += 1) {
      try {
        const route = await computeRoute(origin, destination, mode);
        if (!acceptRoute(state, generation, route)) return;
        renderRoute(route, mode);
        $('route-retry').hidden = true;
        say('Route ready. This demo does not make a booking.');
        return;
      } catch (error) {
        lastError = error;
        if (attempt === 0) await new Promise((resolve) => setTimeout(resolve, 450));
      }
    }
    if (generation !== state.generation) return;
    $('route-retry').hidden = false;
    const message = lastError?.message;
    say(message?.includes('REQUEST_DENIED') ? 'Map access was denied. Check the enabled routes service.' : message === 'Route request timed out.' ? 'Route lookup timed out. Try again.' : 'The route provider did not return a route. Try again.', true);
  }, 80);
}

boot();
