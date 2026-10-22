export const SERVICE_AREA = Object.freeze({ minLat: 12.65, maxLat: 13.35, minLng: 77.2, maxLng: 77.95 });

export function inServiceArea(point) {
  return Number.isFinite(point?.lat) && Number.isFinite(point?.lng)
    && point.lat >= SERVICE_AREA.minLat && point.lat <= SERVICE_AREA.maxLat
    && point.lng >= SERVICE_AREA.minLng && point.lng <= SERVICE_AREA.maxLng;
}

export function pointFromLocation(location) {
  if (!location) return null;
  const lat = typeof location?.lat === 'function' ? location.lat() : location?.lat;
  const lng = typeof location?.lng === 'function' ? location.lng() : location?.lng;
  if (!Number.isFinite(Number(lat)) || !Number.isFinite(Number(lng))) return null;
  return { lat: Number(lat), lng: Number(lng) };
}

export function createState() {
  return { generation: 0, endpoints: { origin: null, destination: null }, confirmed: { origin: false, destination: false }, route: null, mode: 'DRIVING' };
}

export function invalidate(state, endpoint) {
  state.generation += 1;
  if (endpoint) { state.confirmed[endpoint] = false; state.endpoints[endpoint] = null; }
  else { state.confirmed.origin = false; state.confirmed.destination = false; state.endpoints.origin = null; state.endpoints.destination = null; }
  state.route = null;
  return state.generation;
}

export function setSelection(state, endpoint, point, address = '') {
  state.generation += 1;
  state.endpoints[endpoint] = { ...point, address };
  state.confirmed[endpoint] = false;
  state.route = null;
  return state.generation;
}

export function updateAddress(state, endpoint, address) {
  if (!state.endpoints[endpoint] || typeof address !== 'string' || !address.trim()) return false;
  state.endpoints[endpoint].address = address.trim();
  return true;
}

export function confirm(state, endpoint) {
  if (!state.endpoints[endpoint] || !inServiceArea(state.endpoints[endpoint])) return false;
  state.confirmed[endpoint] = true;
  state.route = null;
  return true;
}

export function canRoute(state) {
  return ['origin', 'destination'].every((key) => state.confirmed[key] && inServiceArea(state.endpoints[key]));
}

export function setMode(state, mode) { if (state.mode !== mode) { state.mode = mode; state.generation += 1; state.route = null; } return state.mode; }
export function beginRoute(state) { if (!canRoute(state)) return null; state.generation += 1; return state.generation; }
export function acceptRoute(state, generation, route) {
  if (generation !== state.generation || !canRoute(state)) return false;
  state.route = route;
  return true;
}
