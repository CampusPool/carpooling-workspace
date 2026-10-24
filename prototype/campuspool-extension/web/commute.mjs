import { mountTripChat } from './trip-chat-ui.mjs';
import { locationFields, mountLocationFields } from './place-search.mjs';
import { mapPanel, mountMap, commutePreview, matchesSavedCommute } from './commute-map.mjs';

const API_ROOT = '/api/mobility/';
const ZONE = 'Asia/Kolkata';
const $ = id => document.getElementById(id);
const esc = value => String(value ?? '').replace(/[&<>"']/g, char => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[char]));
const money = value => `₹${(Number(value ?? 0) / 100).toFixed(2)}`;
const today = () => new Intl.DateTimeFormat('en-CA', { timeZone: ZONE, year: 'numeric', month: '2-digit', day: '2-digit' }).format(new Date());
const addDays = (date, days) => new Date(Date.parse(`${date}T12:00:00Z`) + days * 86400000).toISOString().slice(0, 10);
const dayName = date => new Date(`${date}T12:00:00Z`).toLocaleDateString('en-IN', { timeZone: ZONE, weekday: 'short', day: 'numeric' });
const dayLabel = date => date === today() ? 'Today' : date === addDays(today(), 1) ? 'Tomorrow' : dayName(date);
const time = value => value ? new Date(value).toLocaleTimeString('en-IN', { timeZone: ZONE, hour: '2-digit', minute: '2-digit' }) : '—';
const pickupWindow = value => `${time(value)} – ${time(new Date(Date.parse(value) + 300000).toISOString())}`;
const localTime = value => time(`2000-01-01T${value || '08:30'}:00+05:30`);
const shortDistance = value => Number(value) < 1000 ? `${Number(value)} m` : `${(Number(value) / 1000).toFixed(1)} km`;
const initials = value => String(value || 'M').split(' ').filter(Boolean).slice(0, 2).map(part => part[0]).join('').toUpperCase();
const vehicleName = value => ({ car: 'Car', bike: 'Bike', both: 'Car or bike' }[value] || 'Car or bike');
const retryKeys = new Map();
let chatUI = null;
let locationUI = null;
let pageMap = null;
let sheetMap = null;
let toastTimer = null;
let restoreFocus = null;

const state = {
  token: null, user: null, profile: null, driverApproved: false, demo: false, role: 'rider', view: 'home',
  generation: 0, bookingReadVersion: 0, bookingFetches: 0, bookings: [], rides: [], offers: [], wallet: {},
  selectedDate: today(), direction: 'outbound', searchContext: null, ridesMode: 'results', searchVersion: 0,
  sheet: null, sheetVersion: 0, draft: null, profileStep: 0, chatTrip: null, poll: null, pendingActions: new Set(),
};

const paths = {
  calendar: '<rect x="3" y="5" width="18" height="16" rx="3"/><path d="M7 3v4m10-4v4M3 11h18m-13 4h.1m4 0h.1m4 0h.1"/>',
  clock: '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l4 2"/>',
  pin: '<path d="M19 10c0 5-7 11-7 11S5 15 5 10a7 7 0 1 1 14 0Z"/><circle cx="12" cy="10" r="2.5"/>',
  arrow: '<path d="M5 12h14m-6-6 6 6-6 6"/>',
  back: '<path d="m15 5-7 7 7 7"/>',
  plus: '<path d="M12 5v14M5 12h14"/>',
  close: '<path d="m6 6 12 12M6 18 18 6"/>',
  swap: '<path d="M8 3v16m-4-4 4 4 4-4m4 6V5m-4 4 4-4 4 4"/>',
  edit: '<path d="m15 4 5 5-11 11H4v-5ZM13 6l5 5"/>',
  car: '<path d="m4 10 3-6h10l3 6M3 10h18v9H3Z"/><path d="M6 19v2m12-2v2M6 14h2m8 0h2"/>',
  bike: '<circle cx="5" cy="17" r="4"/><circle cx="19" cy="17" r="4"/><path d="m5 17 5-9 5 9h4l-4-13h-3m-4 1h4"/>',
  walk: '<circle cx="13" cy="3" r="1.5"/><path d="m7 21 3-7 3 3v5M6 11l4-4 4 1 3 5h4m-11-6 1 7 5 1"/>',
  person: '<circle cx="12" cy="7" r="4"/><path d="M4 22a8 8 0 0 1 16 0"/>',
  chat: '<path d="M21 11a9 9 0 0 1-9 9c-2 0-4-.7-5-1L2 21l2-5a9 9 0 1 1 17-5Z"/><path d="M8 11h.1m4 0h.1m4 0h.1"/>',
  wallet: '<path d="M19 8V5H5a2 2 0 0 0-2 2v12h18V8H5a1 1 0 0 1 0-2"/><path d="M21 12h-6v4h6"/>',
  check: '<path d="m5 12 5 5L20 7"/>',
  chevron: '<path d="m9 5 7 7-7 7"/>',
  logout: '<path d="M9 4H4v16h5m6-12 4 4-4 4m-7-4h11"/>',
};
const icon = (name, className = '') => `<svg class="icon ${className}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${paths[name] || paths.pin}</svg>`;
const carArt = () => '<svg class="hero-car" viewBox="0 0 260 135" aria-hidden="true"><ellipse cx="133" cy="120" rx="108" ry="10" fill="#123db8" opacity=".2"/><path d="M39 66 68 26q5-7 16-7h75q11 0 19 10l32 37 27 10q9 3 9 16v16H17V87q0-13 22-21Z" fill="#deebff"/><path d="m76 30-23 35h127l-25-35Z" fill="#96bfff"/><path d="M116 30v35" stroke="#deebff" stroke-width="6"/><path d="M18 82h227v22H18Z" fill="#aacdff"/><path d="m22 79 28 4v12H20m224-17-28 5v12h30" fill="white"/><rect x="106" y="88" width="63" height="12" rx="5" fill="#1557f5"/><circle cx="65" cy="106" r="23" fill="#16315e"/><circle cx="65" cy="106" r="11" fill="#eef5ff"/><circle cx="203" cy="106" r="23" fill="#16315e"/><circle cx="203" cy="106" r="11" fill="#eef5ff"/></svg>';

const messages = {
  INSUFFICIENT_DEMO_CREDITS: 'Add demo credits in Wallet, then come back to confirm payment.',
  COMMUTE_CHOOSE_FUTURE_DEPARTURE: 'Choose a pickup at least 15 minutes from now, or try tomorrow.',
  DEMO_SESSION_REQUIRED: 'Use the rider or driver demo to try this journey.',
  DRIVER_ROUTE_OVERLAP: 'This driver already has a ride around that time. Try a different pickup time.',
  NOT_SEAT_AVAILABLE: 'This ride is full. Choose another ride.',
  COMMUTE_BUSY_RETRY: 'Another action is finishing. Please retry; the same payment will not be charged twice.',
  COMMUTE_QUOTE_CHANGED: 'Your commute changed. Find rides again to review a fresh fare.',
  RIDER_PAYMENT_CONFIRMATION_REQUIRED: 'The rider needs to confirm payment with demo credits first.',
  COMMUTE_TRIP_CLOSED: 'This ride is no longer open. Please choose another departure.',
};
async function api(path, options = {}) {
  const response = await fetch(`${API_ROOT}${path.replace(/^\//, '')}`, {
    ...options, cache: 'no-store',
    headers: { Accept: 'application/json', ...(options.body ? { 'Content-Type': 'application/json' } : {}), ...(state.token ? { Authorization: `Bearer ${state.token}` } : {}), ...(options.headers || {}) },
    body: options.body ? JSON.stringify(options.body) : undefined,
  });
  let data = {};
  try { data = await response.json(); } catch { /* A non-JSON failure still gets a useful message. */ }
  if (!response.ok) {
    if (response.status === 401 && state.token) {
      signOut(false);
      status('Your session is no longer valid. Reopen the same demo; your saved commute and bookings are retained.', true);
    }
    const code = typeof data.error === 'string' ? data.error : data.error?.code;
    const error = new Error(messages[code] || Object.values(data.errors || {}).flat()[0] || data.message || code || 'Something went wrong. Please try again.');
    error.status = response.status; error.code = code; throw error;
  }
  return data;
}
async function mutate(path, body = {}) {
  const fingerprint = path + JSON.stringify(body);
  if (!retryKeys.has(fingerprint)) retryKeys.set(fingerprint, crypto.randomUUID());
  const result = await api(path, { method: 'POST', body, headers: { 'Idempotency-Key': retryKeys.get(fingerprint) } });
  retryKeys.delete(fingerprint);
  return result;
}
function status(text, error = false, id = 'auth-status') {
  const element = $(id);
  if (element) { element.textContent = text; element.classList.toggle('error', error); }
}
function toast(text) {
  const element = $('app-toast'); if (!element) return;
  element.textContent = text; element.classList.remove('hidden'); clearTimeout(toastTimer);
  toastTimer = setTimeout(() => element.classList.add('hidden'), 5000);
}
function applyMe(data) {
  state.user = data.user || state.user; state.profile = data.profile || null;
  state.driverApproved = Boolean(data.driver_approved); state.demo = Boolean(data.demo);
  state.role = data.profile?.role || state.role;
}
async function beginDemo(role) {
  const button = document.querySelector(`[data-demo-role="${role}"]`);
  if (button) button.disabled = true;
  status('Opening your commute…');
  try {
    const scenario = typeof location !== 'undefined' && new URLSearchParams(location.search).get('demo') === 'campus-route' ? 'campus-route' : null;
    const data = await api('commute/demo-session', { method: 'POST', body: { role, ...(scenario ? { scenario } : {}) } });
    state.generation++; state.token = data.token; state.role = role; applyMe(data); state.role = role;
    state.selectedDate = today(); state.view = 'home'; state.rides = []; state.bookings = []; state.offers = []; state.chatTrip = null;
    $('auth-screen').classList.add('hidden'); $('product-screen').classList.remove('hidden');
    render(); startPoll();
    if (!state.profile?.onboarding_complete) openProfile(0);
  } catch (error) { status(error.message, true); }
  finally { if (button) button.disabled = false; }
}
function navigate(view, mode) {
  closeSheet(); state.view = view; state.chatTrip = null;
  if (view === 'rides' && mode) state.ridesMode = mode;
  render(); window.scrollTo?.({ top: 0, behavior: 'instant' });
}
function startPoll() {
  clearInterval(state.poll);
  const generation = state.generation;
  state.poll = setInterval(() => { if (!document.hidden && generation === state.generation && state.token && !state.bookingFetches) void bookings().catch(() => {}); }, 5000);
  void bookings().catch(() => toast('Ride updates are temporarily unavailable.'));
}
async function bookings() {
  const generation = state.generation, version = ++state.bookingReadVersion;
  state.bookingFetches++;
  try {
    const data = await api('commute/bookings');
    if (generation !== state.generation || version !== state.bookingReadVersion || !state.token) return;
    state.bookings = data.data || [];
    const surface = $('booking-surface'); if (surface) { surface.innerHTML = bookingSurface(); bindBookings(surface); }
    updateActivity(); refreshPageMap();
    if (state.view === 'chats' && !state.chatTrip) renderChatList();
  } finally { state.bookingFetches--; }
}
function updateActivity() {
  const active = state.bookings.filter(booking => [0, 1].includes(Number(booking.request_state)) && ['planned', 'in_progress'].includes(booking.state)).length;
  const count = $('activity-count'); if (count) { count.textContent = active > 9 ? '9+' : String(active); count.classList.toggle('hidden', active === 0); }
}
function weekday(date) { return new Date(`${date}T12:00:00Z`).getUTCDay() || 7; }
function plannedFor(date) {
  const profile = state.profile; if (!profile?.onboarding_complete || !(profile.weekdays || []).includes(weekday(date))) return [];
  return ['outbound', 'return'].filter(direction => Date.parse(`${date}T${profile[direction === 'outbound' ? 'outbound_time' : 'return_time']}:00+05:30`) > Date.now() + 15 * 60000);
}
function nextPlanned() {
  for (let offset = 1; offset <= 8; offset++) { const date = addDays(state.selectedDate, offset); if (plannedFor(date).length) return date; }
  return null;
}
function dateBar() {
  return `<div class="date-strip" role="group" aria-label="Choose ride date">${Array.from({ length: 6 }, (_, index) => addDays(today(), index)).map(date => `<button type="button" class="date-chip ${date === state.selectedDate ? 'selected' : ''}" data-day="${date}" aria-pressed="${date === state.selectedDate}">${dayLabel(date)}</button>`).join('')}</div>`;
}
function hero() {
  return `<div class="home-hero"><div class="hero-copy"><span class="eyebrow">YOUR EVERYDAY COMMUTE</span><h1>${state.role === 'driver' ? 'A better ride.<br>A little company.' : 'Same campus.<br>Better company.'}</h1><p>${state.role === 'driver' ? 'Your requests. Your route. Your call.' : 'Find your people on the way.'}</p></div>${carArt()}<div class="hero-road" aria-hidden="true"></div></div>`;
}
function routeRows(from, to, className = '') {
  return `<div class="route-points ${className}"><div><span class="route-dot start"></span><span>${esc(from || 'Pickup')}</span></div><span class="route-connector" aria-hidden="true"></span><div><span class="route-dot end"></span><span>${esc(to || 'Destination')}</span></div></div>`;
}
function commuteTile(direction) {
  const profile = state.profile, returning = direction === 'return';
  return `<article class="commute-tile"><div class="commute-tile-top"><span class="direction-chip">${icon(returning ? 'back' : 'arrow')}${returning ? 'Back home' : 'To campus'}</span><button type="button" class="plain-icon" data-edit-direction="${direction}" aria-label="Edit ${returning ? 'return' : 'campus'} ride">${icon('edit')}</button></div><div class="commute-time">${localTime(profile[returning ? 'return_time' : 'outbound_time'])}<span>pickup</span></div>${routeRows(profile[returning ? 'campus' : 'home']?.label, profile[returning ? 'home' : 'campus']?.label)}<div class="commute-tile-bottom"><span class="vehicle-caption">${icon(profile.vehicle_preference === 'bike' ? 'bike' : 'car')}${vehicleName(profile.vehicle_preference)}</span><button class="button primary compact" type="button" data-find-direction="${direction}">${state.role === 'driver' ? 'Offer a ride' : 'Find a ride'} ${icon('arrow')}</button></div></article>`;
}
function riderHome() {
  const planned = plannedFor(state.selectedDate), next = nextPlanned();
  return `${mapPanel('home-route-map', 'Where your ride goes')}<section class="home-panel"><div class="section-heading"><h2>Your rides</h2><button class="text-action" type="button" data-edit-profile>${icon('calendar')}Edit planned rides</button></div>${dateBar()}<p class="day-caption">${new Date(`${state.selectedDate}T12:00:00Z`).toLocaleDateString('en-IN', { timeZone: ZONE, weekday: 'long', day: 'numeric', month: 'long' })}</p>${planned.length ? `<div class="commute-tiles">${planned.map(commuteTile).join('')}</div><button class="button soft full-width add-ride" type="button" data-create-ride>${icon('plus')}Create another ride</button>` : `<div class="empty-rides"><span class="empty-icon">${icon('car')}</span><h3>No rides for the day</h3><p>${state.profile?.onboarding_complete ? 'Make a one-off trip, or choose another day.' : 'Add your commute to start finding rides.'}</p><button class="button soft" type="button" data-create-ride>${icon('plus')}Create new ride</button></div>`}${!planned.length && next ? `<div class="next-planned"><span>Your next planned ride is <strong>${dayLabel(next)}</strong></span><button class="button primary" type="button" data-day="${next}">Check for ${dayLabel(next)} ${icon('arrow')}</button></div>` : ''}<div class="section-heading activity-heading"><h3>Ride updates</h3><button class="text-action" type="button" data-nav-action="rides">See all ${icon('chevron')}</button></div><div id="booking-surface"></div></section>`;
}
function driverHome() {
  return `${mapPanel('home-route-map', 'Where your ride goes')}<section class="home-panel"><div class="section-heading"><h2>Your commute</h2><button class="text-action" data-edit-profile type="button">Edit planned rides</button></div>${dateBar()}${state.driverApproved ? `<button class="button primary full-width" data-create-ride type="button">Offer a ride</button><p class="fine-print">Publish a dated ride from your saved route. One demo rider per offer; driver approval is separate from mode.</p>${(state.offers || []).map(offer => `<article class="booking-card"><div class="section-heading"><strong>Published · ${vehicleName(offer.vehicle_type)}</strong><span class="state-chip">${offer.seats_available} seat available</span></div>${routeChangeNotice(offer, true)}${routeRows(offer.pickup_label, offer.dropoff_label)}<button class="map-route-button" type="button" data-offer-map="${offer.trip_id}">${icon('pin')}View published route</button><p class="booking-note">${dayLabel(offer.departure_at.slice(0,10))} · Depart ${time(offer.departure_at)} IST · ${offer.choice === 'DIRECT_RIDER_PICKUP' ? `Direct pickup · up to ${shortDistance(offer.max_detour_meters)} / ${Math.ceil(offer.max_detour_seconds/60)} min detour` : 'Meet along my route'}</p></article>`).join('')}<div class="section-heading activity-heading"><h2>Your requests</h2><button class="text-action" type="button" data-nav-action="rides">My rides ${icon('chevron')}</button></div><p class="muted panel-intro">Review pickup, fare and detour before accepting.</p><div id="booking-surface"></div>` : `<div class="empty-rides"><h3>Driver approval needed</h3><p>Changing your mode does not approve a driver profile.</p>${state.demo ? '<button class="button primary" data-switch-demo="driver" type="button">Try the separate driver demo</button>' : ''}</div>`}</section>`;
}
function matchingInfo(ride) {
  return ride.driver_route_origin_label ? `<div class="matching-info"><strong>Driver route</strong><p>${esc(ride.driver_route_origin_label)} → ${esc(ride.driver_route_destination_label)}</p><span>${ride.choice === 'ON_ROUTE_MEETING_POINT' ? 'Meet along the route' : 'Direct pickup'} · Driver detour ${shortDistance(ride.detour_meters || 0)} / ${Math.ceil((ride.detour_seconds || 0)/60)} min · Total walking ${Number(ride.access_meters || 0)+Number(ride.egress_meters || 0)} m</span></div>` : '';
}
function meetingInfo(ride) {
  if (ride.choice !== 'ON_ROUTE_MEETING_POINT') return '';
  const details = ride.meeting_details || {}, access = Number(ride.access_meters || 0), egress = Number(ride.egress_meters || 0);
  return `<div class="meeting-plan"><strong>${access > 0 ? 'Walk → meet → ride together' : 'Meet → ride together'}${egress > 0 ? ' → walk to destination' : ''}</strong><p>${icon(access > 0 ? 'walk' : 'pin')} ${access > 0 ? `Walk ${shortDistance(access)} · about ${Math.ceil((details.access_seconds || 0)/60)} min from ${esc(details.rider_origin_label || 'your starting point')} to meeting pin P.` : 'Meet the driver at pin P at your starting point.'}</p><p>${icon('car')} Share the ride from P to E.</p>${egress > 0 ? `<p>${icon('walk')} From drop-off E, walk ${shortDistance(egress)} · about ${Math.ceil((details.egress_seconds || 0)/60)} min to ${esc(details.rider_destination_label || 'your destination')}.</p>` : ''}${details.leave_by ? `<p class="meeting-leave">${ride.owner ? 'Rider should leave' : 'Leave by'} ${time(details.leave_by)} IST · includes 1 minute to meet.</p>` : ''}<small>Driver stays on the published road route · no pickup extra. Walking time is estimated; access gaps are shown on the map.</small></div>`;
}
function meetingConsent(ride) {
  const access = Number(ride.access_meters || 0), egress = Number(ride.egress_meters || 0);
  return `I agree to the walking plan: ${access} m to pickup + ${egress} m after drop-off (${access + egress} m total), the shared journey and total demo fare.`;
}
function legBar(ride) {
  return `<div class="journey-legs" aria-label="Walk ${ride.access_meters || 0} metres, ride ${ride.ride_meters || 0} metres, walk ${ride.egress_meters || 0} metres"><span>${icon('walk')}<b>${shortDistance(ride.access_meters || 0)}</b></span><i></i><span class="ride-leg">${icon(ride.vehicle_type === 'bike' ? 'bike' : 'car')}<b>${shortDistance(ride.ride_meters || 0)}</b></span><i></i><span>${icon('walk')}<b>${shortDistance(ride.egress_meters || 0)}</b></span></div>`;
}
function rideCard(ride) {
  return `<article class="host-card"><div class="vehicle-corner ${ride.vehicle_type === 'bike' ? 'bike' : ''}">${icon(ride.vehicle_type === 'bike' ? 'bike' : 'car')}<span>${vehicleName(ride.vehicle_type)}</span></div><div class="host-identity"><span class="host-avatar ${ride.vehicle_type === 'bike' ? 'lilac' : ''}">${esc(initials(ride.driver_name))}</span><div><h3>${esc(ride.driver_name)}</h3><span class="host-label">Published campus ride · demo account</span><p>${icon('clock')}Pickup ${pickupWindow(ride.departure_at)}</p></div></div>${matchingInfo(ride)}${meetingInfo(ride)}<button class="map-route-button" type="button" data-result-map="${ride.trip_id}">${icon('pin')}View route & pickup</button>${legBar(ride)}<div class="host-card-bottom"><div class="fare-block"><strong>${money(ride.total_fare_minor)}</strong><span>${ride.seats_available} ${Number(ride.seats_available) === 1 ? 'seat' : 'seats'} left</span></div><button class="button primary request-ride" data-trip="${ride.trip_id}" type="button" ${Number(ride.seats_available) < 1 ? 'disabled' : ''}>Request Ride</button></div><div class="host-card-footer"><span>${icon('pin')}${ride.choice === 'ON_ROUTE_MEETING_POINT' ? 'Meet on driver route' : 'Direct pickup'}</span><span>${ride.choice === 'ON_ROUTE_MEETING_POINT' ? 'No direct-pickup extra' : `Fare includes ${money(ride.surcharge_minor)} pickup extra`}</span></div></article>`;
}
function ridesView() {
  if (state.role === 'driver' || state.ridesMode === 'bookings') return `<section class="standard-panel"><div class="page-heading"><button class="plain-icon" type="button" data-nav-action="home" aria-label="Back to home">${icon('back')}</button><div><h1>My rides</h1><p>Your requests, upcoming rides and history</p></div></div><div id="booking-surface"></div></section>`;
  const context = state.searchContext, returning = context?.direction === 'return', profile = state.profile;
  return `<section class="results-panel"><div class="results-heading"><button class="plain-icon" data-nav-action="home" type="button" aria-label="Back to your rides">${icon('back')}</button><div class="results-route"><h1>${esc(profile?.[returning ? 'campus' : 'home']?.label || 'Your pickup')}<span>→ ${esc(profile?.[returning ? 'home' : 'campus']?.label || 'Your destination')}</span></h1><button class="outline-pill" type="button" data-change-time>${icon('clock')}Change time</button></div><label class="car-filter"><span>Car only</span><input id="car-only" type="checkbox" ${context?.vehicle === 'car' ? 'checked' : ''}><span class="toggle-track">${icon('car')}</span></label></div><div class="results-date">${icon('calendar')}${context ? `${dayLabel(context.date)} · ${localTime(context.startTime)}–${localTime(context.endTime)}` : 'Choose a trip to find rides'}<span>${state.rides.length} ${state.rides.length === 1 ? 'ride' : 'rides'}</span></div><div class="community-banner"><span class="community-symbol">${icon('person')}</span><div><strong>Your route. Your community.</strong><p>Driver-published rides matching your commute</p></div><span class="community-check">${icon('check')}</span></div>${state.rides.length ? mapPanel('results-route-map', 'Route & pickup') : ''}<div id="ride-results">${state.rides.length ? state.rides.map(rideCard).join('') : `<div class="empty-rides"><span class="empty-icon">${icon('car')}</span><h3>No rides found</h3><p>${context?.pickupChoice === 'ON_ROUTE_MEETING_POINT' ? `No compatible meeting point for this time within your ${Number(state.profile?.max_walk_meters ?? 999)} m total walking limit. ${Number(state.profile?.max_walk_meters ?? 999) < 999 ? 'Try a larger limit, another time, or direct pickup.' : 'Try another time or direct pickup; the walking limit must stay under 1 km.'}` : 'Try another pickup time, vehicle or pickup option.'}</p><button class="button primary" data-change-time type="button">Change search</button></div>`}</div><p class="status" id="rides-status" role="status"></p></section>`;
}
function mapForRide(ride, prefix='') {
  return ride?.route_map ? {...ride.route_map, heading: `${prefix}${dayLabel(ride.departure_at?.slice(0,10))} · ${time(ride.departure_at)} IST`} : null;
}
function changedCommute(ride, published=false) {
  return !matchesSavedCommute(ride?.route_map, state.profile, published || Boolean(ride?.owner));
}
function routeChangeNotice(ride, published=false) {
  return changedCommute(ride, published) ? `<p class="route-change-note">${published ? 'Earlier saved route. Publish a new ride to use your current commute.' : 'Your commute has changed. This booking keeps its original pickup, route and fare.'}</p>` : '';
}
function pageMapData() {
  if (state.view === 'rides' && state.ridesMode !== 'bookings') return mapForRide(state.rides[0], 'Pickup · ') || commutePreview(state.profile, state.direction);
  const candidates = state.bookings.filter(row => [0,1].includes(Number(row.request_state)) && ['planned','in_progress'].includes(row.state) && !changedCommute(row));
  const active = candidates.find(row => row.departure_at?.slice(0,10) === state.selectedDate) || candidates[0];
  const offers = (state.offers || []).filter(row => !changedCommute(row, true));
  const offer = offers.find(row => row.departure_at?.slice(0,10) === state.selectedDate) || offers[0];
  return mapForRide(active, Number(active?.request_state) === 0 ? 'Current request · ' : 'Current booked route · ') || mapForRide(offer, 'Current published route · ') || {...commutePreview(state.profile, state.direction), heading: `Saved commute · ${state.direction === 'return' ? 'Back home' : 'To campus'}`};
}
function refreshPageMap() { pageMap?.update(pageMapData()); }
function mountSheetMap(root, data) {
  const version = state.sheetVersion, generation = state.generation;
  sheetMap = mountMap(root, data, {isCurrent: () => version === state.sheetVersion && generation === state.generation});
}
function openRoute(ride) {
  const dialog = openSheet('Route & pickup', `${mapPanel('booking-route-map', 'See exactly where to meet')}${routeChangeNotice(ride, !('booking_id' in ride))}${routeRows(ride.pickup_label, ride.dropoff_label)}${matchingInfo(ride)}${meetingInfo(ride)}<p class="fine-print">${dayLabel(ride.departure_at?.slice(0,10))} · ${time(ride.departure_at)} IST. Tap a stop to zoom into its pin.</p>`, 'route');
  mountSheetMap(dialog.querySelector('#booking-route-map'), mapForRide(ride));
}
function mountFormMap(form, names, selector) {
  const version = state.sheetVersion, generation = state.generation;
  const selected = () => {
    const point = name => {
      const latitude=form.elements[`${name}_lat`], longitude=form.elements[`${name}_lng`];
      if (!latitude?.value || !longitude?.value) return null;
      return {lat:Number(latitude.value),lng:Number(longitude.value),label:form.elements[`${name}_label`]?.value};
    };
    return {preview:true,points:{origin:point(names[0]),destination:point(names[1])}};
  };
  sheetMap = mountMap(form.querySelector(selector), selected(), {isCurrent: () => version === state.sheetVersion && generation === state.generation});
  const update = () => queueMicrotask(() => { if (version === state.sheetVersion && generation === state.generation) sheetMap?.update(selected()); });
  form.addEventListener('input', update); form.addEventListener('change', update);
}
function driverButtons(booking) {
  if (!['planned', 'in_progress'].includes(booking.state)) return '';
  if (booking.wallet_state !== 'held') return '<p class="booking-note">Waiting for the rider to confirm demo payment.</p>';
  const action = booking.state === 'planned' ? 'start' : booking.participant_state === 'accepted' ? 'board' : booking.participant_state === 'boarded' ? 'alight' : 'complete';
  const label = { start: 'Start trip', board: 'Mark rider boarded', alight: 'Mark rider alighted', complete: 'Complete trip' }[action];
  return `<button class="button primary booking-action" data-action="${action}" data-trip="${booking.trip_id}" data-passenger="${booking.rider_id}" type="button">${label}</button>`;
}
function bookingCard(booking) {
  const pending = Number(booking.request_state) === 0, accepted = Number(booking.request_state) === 1, owner = booking.owner;
  const label = ({ 0: 'Request pending', 1: booking.state === 'completed' ? 'Completed' : booking.state === 'in_progress' ? 'On your way' : 'Accepted', 2: 'Declined', 3: 'Cancelled' })[booking.request_state] || 'Ride';
  return `<article class="booking-card"><div class="booking-heading"><span class="small-avatar">${esc(initials(owner ? booking.rider_name : booking.driver_name))}</span><div><h3>${esc(owner ? booking.rider_name : booking.driver_name)}</h3><p>${dayLabel(booking.departure_at?.slice(0, 10))} · ${time(booking.departure_at)} IST</p></div><span class="state-chip ${pending ? 'pending' : accepted ? 'accepted' : ''}">${label}</span></div>${routeChangeNotice(booking)}${matchingInfo(booking)}${meetingInfo(booking)}<button class="map-route-button" type="button" data-booking-map="${booking.booking_id}">${icon('pin')}View route & pickup</button>${routeRows(booking.pickup_label || 'Scheduled pickup', booking.dropoff_label || 'Your destination')}<div class="booking-fare"><span>${icon(booking.vehicle_type === 'bike' ? 'bike' : 'car')}${vehicleName(booking.vehicle_type)}</span><strong>${money(booking.fare_minor)}<small> demo fare</small></strong></div>${booking.wallet_state === 'held' ? '<p class="payment-note">Demo payment held securely for this ride</p>' : booking.wallet_state === 'refunded' ? '<p class="payment-note">Demo payment refunded</p>' : booking.wallet_state === 'released' ? '<p class="payment-note">Demo credits released to driver</p>' : ''}${booking.release_at ? `<p class="booking-note">Release eligible ${new Date(booking.release_at + 'Z').toLocaleString('en-IN', { timeZone: ZONE, day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' })} IST after arrival confirmation.</p>` : ''}<div class="booking-actions">${owner && pending ? `<button class="button primary booking-action" data-action="accept" data-booking="${booking.booking_id}" type="button">Accept request</button><button class="button outline booking-action" data-action="reject" data-booking="${booking.booking_id}" type="button">Decline</button>` : ''}${!owner && accepted && booking.wallet_state === 'unpaid' && booking.state === 'planned' ? `<button class="button primary booking-action" data-action="pay" data-booking="${booking.booking_id}" type="button">Confirm & pay ${money(booking.fare_minor)}</button>` : ''}${owner && accepted ? driverButtons(booking) : ''}${!owner && accepted && booking.state === 'completed' && !booking.rider_arrived_at ? `<button class="button primary booking-action" data-action="confirm-arrival" data-booking="${booking.booking_id}" type="button">I’ve reached my destination</button>` : ''}${owner && booking.wallet_state === 'held' && booking.release_at ? '<button class="button outline booking-action" data-action="release-due" type="button">Check release due</button>' : ''}${accepted ? `<button class="button soft" data-chat-trip="${booking.trip_id}" type="button">${icon('chat')}Trip chat</button>` : ''}${(pending || accepted) && booking.state === 'planned' ? `<button class="text-action muted booking-action" data-action="cancel" data-booking="${booking.booking_id}" type="button">Cancel ride</button>` : ''}</div></article>`;
}
function bookingSurface() {
  const rows = state.view === 'home' ? state.bookings.filter(booking => [0, 1].includes(Number(booking.request_state)) && ['planned', 'in_progress'].includes(booking.state)) : state.bookings;
  return rows.length ? `${rows.map(bookingCard).join('')}<p id="booking-status" class="status" role="status"></p>` : `<div class="quiet-empty">${icon('calendar')}<p>${state.view === 'home' ? 'No active ride requests yet.' : 'Your ride history will appear here.'}</p></div>`;
}
function walletView() {
  const wallet = state.wallet;
  return `<section class="standard-panel"><div class="page-heading"><button class="plain-icon" data-nav-action="home" type="button" aria-label="Back to home">${icon('back')}</button><div><h1>Your wallet</h1><p>Demo credits · no real money</p></div></div><div class="wallet-card"><span>Available demo balance</span><strong>${money(wallet.available_minor)}</strong><div><span>Held for rides<b>${money(wallet.held_minor)}</b></span><span>Pending driver release<b>${money(wallet.pending_release_minor)}</b></span></div></div><form id="topup-form"><button class="button primary full-width" type="submit">${icon('plus')}Add 500 demo credits</button><p class="status" id="wallet-status" role="status"></p></form><div class="section-heading"><h2>Activity</h2><span class="muted">Demo ledger</span></div><div class="wallet-entries">${(wallet.entries || []).map(entry => `<div class="wallet-entry"><span class="entry-icon">${icon(Number(entry.amount_minor) < 0 ? 'car' : 'wallet')}</span><div><strong>${({ demo_credit: 'Demo credits added', ride_hold: 'Ride payment held', refund: 'Ride refund', driver_release: 'Ride earnings released' })[entry.kind] || 'Wallet update'}</strong><small>${entry.created_at ? new Date(entry.created_at + 'Z').toLocaleDateString('en-IN', { timeZone: ZONE, day: 'numeric', month: 'short' }) : 'Demo transaction'}</small></div><b>${Number(entry.amount_minor) > 0 ? '+' : ''}${money(entry.amount_minor)}</b></div>`).join('') || '<div class="quiet-empty">No transactions yet.</div>'}</div><p class="fine-print">These credits cannot be purchased, withdrawn or transferred as real money. After completion and rider arrival, the demo hold becomes eligible for release after three hours.</p></section>`;
}
function accountView() {
  const profile = state.profile;
  return `<section class="standard-panel account-panel"><div class="account-identity"><span class="account-avatar">${esc(initials(state.user?.name))}</span><h1>${esc(state.user?.name || 'Your account')}</h1><p>PES community · ${state.demo ? 'demo account' : 'college member'}</p></div><div class="account-section"><h2>Travel your way</h2><div class="role-switch"><button type="button" class="${state.role === 'rider' ? 'selected' : ''}" data-role-switch="rider">${icon('person')}Rider</button><button type="button" class="${state.role === 'driver' ? 'selected' : ''}" data-role-switch="driver">${icon('car')}Driver</button></div><p class="fine-print">Driver mode requires a separately approved driver profile.</p><p class="status" id="mode-status" role="status"></p></div><div class="account-menu"><button type="button" data-nav-action="rides">${icon('calendar')}<span>My rides<small>Requests, upcoming rides and history</small></span>${icon('chevron')}</button><button type="button" data-edit-profile>${icon('edit')}<span>My commute<small>${profile ? `${vehicleName(profile.vehicle_preference)} · ${localTime(profile.outbound_time)} / ${localTime(profile.return_time)}` : 'Set your route and pickup times'}</small></span>${icon('chevron')}</button><button type="button" data-nav-action="wallet">${icon('wallet')}<span>Wallet<small>Demo credits and ride payments</small></span>${icon('chevron')}</button><button type="button" data-nav-action="chats">${icon('chat')}<span>Trip chats<small>Message accepted ride participants</small></span>${icon('chevron')}</button></div>${state.demo ? `<div class="demo-account-switch"><span>Testing both sides?</span><button class="text-action" type="button" data-switch-demo="${state.role === 'driver' ? 'rider' : 'driver'}">Open separate ${state.role === 'driver' ? 'rider' : 'driver'} demo ${icon('arrow')}</button></div>` : ''}<button class="sign-out" id="sign-out" type="button">${icon('logout')}Sign out</button><p class="fine-print centered">Original campus demo · inspired by the Hopr reference flow.<br>Manual review is separate from external verification.</p></section>`;
}
function chatsView() {
  return `<section class="standard-panel"><div class="page-heading"><div><h1>Chats</h1><p>Keep your commute conversation in one place.</p></div></div>${state.chatTrip ? '<button class="text-action chat-back" type="button" data-chat-back>← All conversations</button><div id="active-chat"></div>' : '<div id="chat-list"></div>'}</section>`;
}
function renderChatList() {
  const target = $('chat-list'); if (!target) return;
  const seen = new Set(), rows = state.bookings.filter(booking => Number(booking.request_state) === 1 && !seen.has(booking.trip_id) && seen.add(booking.trip_id));
  target.innerHTML = rows.length ? rows.map(booking => `<button class="conversation-row" type="button" data-chat-trip="${booking.trip_id}"><span class="host-avatar">${esc(initials(booking.owner ? booking.rider_name : booking.driver_name))}</span><span><strong>${esc(booking.owner ? booking.rider_name : booking.driver_name)}</strong><small>${dayLabel(booking.departure_at?.slice(0, 10))} · ${time(booking.departure_at)} · ${booking.state === 'completed' ? 'Ride history' : 'Trip conversation'}</small></span>${icon('chevron')}</button>`).join('') : `<div class="empty-rides chat-empty"><span class="empty-icon">${icon('chat')}</span><h3>A chat for every shared ride</h3><p>Once a driver accepts your request, your trip conversation appears here.</p><button class="button soft" type="button" data-nav-action="home">Back to my rides</button></div>`;
  bindNavigation(target);
}
function mountChat() {
  const target = $('active-chat'); if (!target || !state.chatTrip) return;
  chatUI = mountTripChat(target, { tripId: state.chatTrip, api: (path, method = 'GET', body, headers) => api(path, { method, body, headers }), getActorId: () => state.user?.id, getSessionGeneration: () => state.generation });
  target.querySelector('.trip-chat-toggle')?.click();
}
function openChat(trip) {
  closeSheet(); state.chatTrip = Number(trip); state.view = 'chats'; render();
}
function openSheet(title, content, kind) {
  locationUI?.dispose(); locationUI = null; sheetMap?.dispose(); sheetMap = null;
  if (!state.sheet) restoreFocus = document.activeElement;
  state.sheet = kind; state.sheetVersion++;
  $('sheet-root').innerHTML = `<dialog class="flow-dialog" aria-labelledby="sheet-title" id="flow-dialog"><div class="sheet-handle" aria-hidden="true"></div><div class="sheet-heading"><h2 id="sheet-title">${title}</h2><button class="close-button" type="button" data-close-sheet aria-label="Close dialog">${icon('close')}</button></div><div class="sheet-content">${content}</div></dialog>`;
  const dialog = $('flow-dialog');
  if (typeof dialog.showModal === 'function') dialog.showModal(); else dialog.setAttribute('open', '');
  dialog.addEventListener('cancel', event => { event.preventDefault(); closeSheet(); });
  dialog.querySelector('[data-close-sheet]').onclick = closeSheet;
  bindNavigation(dialog);
  return dialog;
}
function closeSheet() {
  locationUI?.dispose(); locationUI = null; sheetMap?.dispose(); sheetMap = null;
  if (!state.sheet) return;
  state.sheet = null; state.sheetVersion++; $('sheet-root').replaceChildren();
  if (restoreFocus?.isConnected) restoreFocus.focus(); restoreFocus = null;
}
function profileDefaults() {
  return structuredClone({ name: state.user?.name || '', role: state.role, vehicle_preference: 'both', schedule_mode: 'fixed', home: { label: 'Home · demo pickup', lat: 12.9716, lng: 77.5946 }, campus: { label: 'Campus · demo stop', lat: 12.9352, lng: 77.62 }, outbound_time: '08:30', return_time: '17:30', weekdays: [1, 2, 3, 4, 5], ...(state.profile || {}) });
}
function collectProfile(form) {
  const data = new FormData(form), draft = state.draft;
  for (const key of ['name', 'vehicle_preference', 'schedule_mode', 'outbound_time', 'return_time']) if (data.has(key)) draft[key] = data.get(key);
  for (const endpoint of ['home', 'campus']) for (const key of ['label', 'lat', 'lng']) if (data.has(`${endpoint}_${key}`)) draft[endpoint][key] = key === 'label' ? data.get(`${endpoint}_${key}`).trim() : Number(data.get(`${endpoint}_${key}`));
  if (form.querySelector('[name="weekdays"]')) draft.weekdays = [...form.querySelectorAll('[name="weekdays"]:checked')].map(input => Number(input.value));
  draft.role = state.role;
  return draft;
}
function vehicleChoices(selected, name = 'vehicle_preference', values = ['car', 'bike', 'both']) {
  return `<div class="vehicle-choices" role="group" aria-label="Vehicle preference">${values.map(value => `<label><input type="radio" name="${name}" value="${value}" ${selected === value ? 'checked' : ''}><span>${icon(value === 'bike' ? 'bike' : value === 'car' ? 'car' : 'swap')}<strong>${value === 'both' ? 'Both' : vehicleName(value)}</strong></span></label>`).join('')}</div>`;
}
function openProfile(step = 0, keepDraft = false) {
  if (!keepDraft || !state.draft) state.draft = profileDefaults();
  state.profileStep = step; const draft = state.draft;
  const progress = `<div class="step-progress" aria-label="Step ${step + 1} of 3">${[0, 1, 2].map(value => `<span class="${value <= step ? 'filled' : ''}"></span>`).join('')}</div><p class="step-label">${['YOUR PREFERENCES', 'YOUR EVERYDAY ROUTE', 'YOUR PICKUP TIMES'][step]}</p>`;
  let fields = '';
  if (step === 0) fields = `<h3 class="step-title">Make the commute yours.</h3><label class="floating-field"><span>Your name</span><input name="name" value="${esc(draft.name)}" required maxlength="100" autocomplete="name"></label><p class="field-title">What would you like to travel in?</p>${vehicleChoices(draft.vehicle_preference)}<p class="fine-print">Choose car, bike, or either. You can change this for each trip.</p>`;
  if (step === 1) fields = `<h3 class="step-title">Where does your day start?</h3><div class="location-fields">${locationFields(draft, ['home', 'campus'], ['Home / pickup', 'Campus / destination'])}<button class="route-swap" type="button" data-swap-profile aria-label="Swap home and destination">${icon('swap')}</button></div>${mapPanel('profile-location-map', 'Your selected locations')}<p class="fine-print">Search and select a place to set its location. Initial demo points are sample stops, not a verified campus address.</p><details class="coordinate-fallback"><summary>Enter coordinates manually</summary><div class="coordinate-grid">${['home', 'campus'].map(endpoint => `<label>${endpoint === 'home' ? 'Home' : 'Campus'} latitude<input data-coordinate="${endpoint}_lat" type="number" step="any" value="${draft[endpoint].lat}" min="12.65" max="13.35"></label><label>${endpoint === 'home' ? 'Home' : 'Campus'} longitude<input data-coordinate="${endpoint}_lng" type="number" step="any" value="${draft[endpoint].lng}" min="77.2" max="77.95"></label>`).join('')}</div></details>`;
  if (step === 2) fields = `<h3 class="step-title">Your week, a little easier.</h3><div class="time-fields"><label class="floating-field"><span>Home → campus pickup</span><input name="outbound_time" type="time" value="${draft.outbound_time}" required></label><label class="floating-field"><span>Campus → home pickup</span><input name="return_time" type="time" value="${draft.return_time}" required></label></div><p class="field-title">Which days do you commute?</p><div class="weekday-choices">${[1, 2, 3, 4, 5, 6, 7].map(day => `<label><input type="checkbox" name="weekdays" value="${day}" ${draft.weekdays.includes(day) ? 'checked' : ''} aria-label="${['', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'][day]}"><span>${['', 'M', 'T', 'W', 'T', 'F', 'S', 'S'][day]}</span></label>`).join('')}</div><label class="floating-field"><span>Schedule</span><select name="schedule_mode"><option value="fixed" ${draft.schedule_mode === 'fixed' ? 'selected' : ''}>Fixed weekly schedule</option><option value="custom" ${draft.schedule_mode === 'custom' ? 'selected' : ''}>Custom weekdays</option><option value="timetable" ${draft.schedule_mode === 'timetable' ? 'selected' : ''}>Use my timetable as a guide</option></select></label><div class="timetable-area"><label class="text-action" for="timetable-photo">${icon('calendar')}Add a timetable photo</label><input id="timetable-photo" type="file" accept="image/*" hidden><div id="photo-preview"></div><p class="fine-print">Local preview only. Review your photo and confirm the days and times yourself.</p></div>`;
  const dialog = openSheet(state.profile?.onboarding_complete ? 'Edit planned rides' : 'Set up your commute', `${progress}<form id="profile-form">${fields}<p class="status" id="profile-status" role="status"></p><div class="sheet-form-actions">${step > 0 ? '<button class="button outline" type="button" data-profile-back>Back</button>' : ''}<button class="button primary" type="submit">${step === 2 ? 'Save my commute' : 'Continue'}${icon('arrow')}</button></div></form>`, 'profile');
  const form = dialog.querySelector('#profile-form');
  if (step === 1) mountFormMap(form, ['home', 'campus'], '#profile-location-map');
  form.onsubmit = event => { event.preventDefault(); if (!form.reportValidity()) return; collectProfile(form); if (step < 2) openProfile(step + 1, true); else void save(event); };
  dialog.querySelector('[data-profile-back]')?.addEventListener('click', () => { collectProfile(form); openProfile(step - 1, true); });
  dialog.querySelector('[data-swap-profile]')?.addEventListener('click', () => { collectProfile(form); [state.draft.home, state.draft.campus] = [state.draft.campus, state.draft.home]; openProfile(1, true); });
  dialog.querySelectorAll('[data-coordinate]').forEach(input => input.oninput = () => { form.elements[input.dataset.coordinate].value = input.value; });
  if (step === 1) {
    const version = state.sheetVersion, generation = state.generation;
    locationUI = mountLocationFields(form, { names: ['home', 'campus'], isCurrent: () => version === state.sheetVersion && generation === state.generation, onSelect: (name, point) => {
      state.draft[name] = point; form.dispatchEvent?.(new Event('input', {bubbles:true}));
      dialog.querySelectorAll('[data-coordinate]').forEach(input => { if (input.dataset.coordinate === `${name}_lat`) input.value = point.lat; if (input.dataset.coordinate === `${name}_lng`) input.value = point.lng; });
    } });
  }
  dialog.querySelector('#timetable-photo')?.addEventListener('change', event => previewTimetable(event, state.sheetVersion));
}
function previewTimetable(event, version) {
  const file = event.target.files?.[0]; if (!file) return;
  if (!file.type.startsWith('image/') || file.size > 10 * 1024 * 1024) return status('Choose an image smaller than 10 MB.', true, 'profile-status');
  const reader = new FileReader(); reader.onload = () => { if (version !== state.sheetVersion || !$('photo-preview')) return; const image = document.createElement('img'); image.src = reader.result; image.alt = 'Your timetable, local preview only'; $('photo-preview').replaceChildren(image); }; reader.readAsDataURL(file);
}
async function save(event) {
  event.preventDefault(); const generation = state.generation, version = state.sheetVersion;
  const form = event.currentTarget || $('profile-form'); collectProfile(form);
  if (!state.draft.weekdays.length) return status('Choose at least one commute day.', true, 'profile-status');
  const button = form.querySelector('[type="submit"]'); button.disabled = true;
  try {
    const data = await api('commute/profile', { method: 'PUT', body: state.draft });
    if (generation !== state.generation) return;
    applyMe(data); state.rides = []; state.searchContext = null; state.searchVersion++;
    if (version === state.sheetVersion) { closeSheet(); state.view = 'home'; render(); toast('Your commute is saved. Choose a day to find your ride.'); }
  } catch (error) { if (version === state.sheetVersion) { status(error.message, true, 'profile-status'); button.disabled = false; } }
}
function openRide(direction = state.direction, date = state.selectedDate) {
  if (!state.profile?.onboarding_complete) return openProfile(0);
  state.direction = direction; const profile = state.profile, context = state.searchContext;
  const value = context?.direction === direction && context?.date === date ? context.startTime : profile[direction === 'return' ? 'return_time' : 'outbound_time'];
  const endValue = context?.direction === direction && context?.date === date ? context.endTime : value;
  const publishing = state.role === 'driver';
  const timeFields = publishing
    ? `<label class="floating-field"><span>Departure time · IST</span><input name="start_time" type="time" value="${value}" required></label>`
    : `<label class="floating-field"><span>Earliest pickup · IST</span><input name="start_time" type="time" value="${value}" required></label><label class="floating-field"><span>Latest pickup · IST</span><input name="end_time" type="time" value="${endValue}" required></label>`;
  const dialog = openSheet(publishing ? 'Offer a ride' : 'Create new ride', `<form id="search-form"><div class="direction-switch"><label><input name="direction" type="radio" value="outbound" ${direction === 'outbound' ? 'checked' : ''}><span>To campus</span></label><label><input name="direction" type="radio" value="return" ${direction === 'return' ? 'checked' : ''}><span>Back home</span></label></div><div class="location-fields">${locationFields({ origin: profile[direction === 'return' ? 'campus' : 'home'], destination: profile[direction === 'return' ? 'home' : 'campus'] }, ['origin', 'destination'], ['Start point', 'End point'])}<button class="route-swap" type="button" data-swap-search aria-label="Swap journey direction">${icon('swap')}</button></div>${mapPanel('search-location-map', 'Your selected locations')}<div class="search-date-time"><label class="floating-field"><span>Ride date</span><input name="date" type="date" value="${date}" min="${today()}" max="${addDays(today(), 30)}" required></label>${timeFields}</div><p class="field-title">Your ride preference</p>${vehicleChoices(context?.vehicle || (publishing && profile.vehicle_preference === 'both' ? 'car' : profile.vehicle_preference), 'vehicle_type', publishing ? ['car', 'bike'] : undefined)}${publishing ? '<fieldset class="pickup-options"><legend>How should you meet?</legend><label><input type="radio" name="choice" value="ON_ROUTE_MEETING_POINT" checked><span><strong>Meet on my route</strong><small>Rider walks to a meeting point. I keep my road route.</small></span></label><label><input type="radio" name="choice" value="DIRECT_RIDER_PICKUP"><span><strong>Go to the rider</strong><small>I take a bounded detour. Rider agrees to the pickup extra.</small></span></label></fieldset><div class="time-fields"><label class="floating-field"><span>Maximum extra distance · km</span><input name="max_detour_km" type="number" min="0" max="10" step="0.5" value="3" required></label><label class="floating-field"><span>Maximum extra time · min</span><input name="max_detour_min" type="number" min="0" max="30" value="10" required></label></div><p class="fine-print">One demo rider per offer. Meeting mode keeps your route unchanged; these detour limits apply to direct pickup. Total rider walking stays under 1 km.</p>' : ''}${!publishing ? `<label class="floating-field"><span>Pickup option</span><select name="pickup_choice"><option value="both" ${(context?.pickupChoice || 'both') === 'both' ? 'selected' : ''}>Either option</option><option value="ON_ROUTE_MEETING_POINT" ${context?.pickupChoice === 'ON_ROUTE_MEETING_POINT' ? 'selected' : ''}>Walk to a meeting point</option><option value="DIRECT_RIDER_PICKUP" ${context?.pickupChoice === 'DIRECT_RIDER_PICKUP' ? 'selected' : ''}>Driver comes to me · pickup extra</option></select></label><label class="floating-field"><span>Maximum total walking · metres</span><input type="number" name="max_walk_meters" min="0" max="999" value="${profile.max_walk_meters ?? 999}" required></label><p class="fine-print">Includes the walk to pickup, from drop-off, and access gaps. Meeting mode has no direct-pickup extra.</p>` : ''}<div class="planned-hint">${icon('calendar')}<span>Want the same times every week?</span><button class="text-action" type="button" data-edit-profile>Edit planned rides</button></div><p class="status" id="ride-status" role="status"></p><button class="button primary full-width" type="submit">${publishing ? 'Publish ride' : 'Find rides'} ${icon('arrow')}</button></form>`, 'search');
  const form = dialog.querySelector('#search-form'); form.addEventListener('submit', search);
  mountFormMap(form, ['origin', 'destination'], '#search-location-map');
  const version = state.sheetVersion, generation = state.generation;
  locationUI = mountLocationFields(form, { names: ['origin', 'destination'], onSelect: () => form.dispatchEvent?.(new Event('input', {bubbles:true})), isCurrent: () => version === state.sheetVersion && generation === state.generation });
  const changeDirection = value => {
    locationUI?.dispose();
    const validity = [form.elements.origin_label.validationMessage || '', form.elements.destination_label.validationMessage || ''];
    state.direction = value;
    form.querySelectorAll('[name="direction"]').forEach(input => input.checked = input.value === value);
    for (const key of ['label', 'lat', 'lng']) [form.elements[`origin_${key}`].value, form.elements[`destination_${key}`].value] = [form.elements[`destination_${key}`].value, form.elements[`origin_${key}`].value];
    form.elements.origin_label.setCustomValidity(validity[1]); form.elements.destination_label.setCustomValidity(validity[0]);
    locationUI = mountLocationFields(form, { names: ['origin', 'destination'], onSelect: () => form.dispatchEvent?.(new Event('input', {bubbles:true})), isCurrent: () => version === state.sheetVersion && generation === state.generation });
    form.elements.start_time.value = profile[value === 'return' ? 'return_time' : 'outbound_time'];
    if (form.elements.end_time) form.elements.end_time.value = form.elements.start_time.value;
    form.dispatchEvent?.(new Event('input', {bubbles:true}));
  };
  form.querySelectorAll('[name="direction"]').forEach(input => input.onchange = () => { if (input.value !== state.direction) changeDirection(input.value); });
  form.querySelector('[data-swap-search]').onclick = () => changeDirection(state.direction === 'outbound' ? 'return' : 'outbound');
}
async function search(event) {
  event.preventDefault(); const form = event.currentTarget, data = new FormData(form), generation = state.generation, version = ++state.searchVersion;
  if (!form.reportValidity()) return;
  const publishing = state.role === 'driver';
  const context = { date: data.get('date'), startTime: data.get('start_time'), endTime: data.get('end_time') || data.get('start_time'), direction: data.get('direction'), vehicle: data.get('vehicle_type'), pickupChoice: data.get('pickup_choice') || 'both' };
  const button = form.querySelector('[type="submit"]'); button.disabled = true; status('Finding rides for your commute…', false, 'ride-status');
  const sheetVersion = state.sheetVersion;
  try {
    const point = name => ({ label: data.get(`${name}_label`).trim(), lat: Number(data.get(`${name}_lat`)), lng: Number(data.get(`${name}_lng`)) });
    const body = { ...state.profile, [context.direction === 'return' ? 'return_time' : 'outbound_time']: context.startTime, [context.direction === 'return' ? 'campus' : 'home']: point('origin'), [context.direction === 'return' ? 'home' : 'campus']: point('destination') };
    if (!publishing) body.max_walk_meters = Number(data.get('max_walk_meters') ?? state.profile.max_walk_meters ?? 999);
    if (publishing && context.vehicle === 'both') throw new Error('Choose car or bike for the ride you are offering.');
    const saved = await api('commute/profile', { method: 'PUT', body });
    if (generation !== state.generation || version !== state.searchVersion) return;
    applyMe(saved);
    if (publishing) {
      await mutate('commute/offers', { date: context.date, direction: context.direction, vehicle_type: context.vehicle, choice: data.get('choice'), max_detour_meters: Math.round(Number(data.get('max_detour_km')) * 1000), max_detour_seconds: Math.round(Number(data.get('max_detour_min')) * 60), commute: body });
      if (generation !== state.generation || sheetVersion !== state.sheetVersion) return;
      state.selectedDate = context.date; closeSheet(); state.view = 'home'; await render(); toast('Your ride is published. Matching riders can request it.'); return;
    }
    if (!publishing && context.startTime > context.endTime) throw new Error('Latest pickup must be at or after earliest pickup.');
    const result = await api(`commute/rides?direction=${context.direction}&date=${context.date}&start_time=${context.startTime}&end_time=${context.endTime}&vehicle_type=${context.vehicle}&pickup_choice=${context.pickupChoice || 'both'}`);
    if (generation !== state.generation || version !== state.searchVersion || sheetVersion !== state.sheetVersion) return;
    state.rides = result.data || []; state.searchContext = context; state.direction = context.direction; state.selectedDate = context.date;
    navigate('rides', 'results');
  } catch (error) { if (generation === state.generation && version === state.searchVersion && sheetVersion === state.sheetVersion) { status(error.message, true, 'ride-status'); button.disabled = false; } }
}
async function filterCars(onlyCars) {
  if (!state.searchContext) return; const version = ++state.searchVersion, generation = state.generation;
  const context = { ...state.searchContext, vehicle: onlyCars ? 'car' : 'both' };
  status('Updating rides…', false, 'rides-status');
  try {
    const result = await api(`commute/rides?direction=${context.direction}&date=${context.date}&start_time=${context.startTime}&end_time=${context.endTime}&vehicle_type=${context.vehicle}&pickup_choice=${context.pickupChoice || 'both'}`);
    if (version !== state.searchVersion || generation !== state.generation || state.view !== 'rides') return;
    state.rides = result.data || []; state.searchContext = context; render();
  } catch (error) { if (version === state.searchVersion && generation === state.generation) { status(error.message, true, 'rides-status'); const input = $('car-only'); if (input) input.checked = state.searchContext.vehicle === 'car'; } }
}
function showDetail(button) {
  const ride = state.rides.find(value => String(value.trip_id) === String(button.dataset.trip)); if (!ride) return;
  const dialog = openSheet('Your ride, at a glance', `<article id="ride-detail"><div class="host-identity review-host"><span class="host-avatar">${esc(initials(ride.driver_name))}</span><div><h3>${esc(ride.driver_name)}</h3><span class="host-label">Demo ${vehicleName(ride.vehicle_type).toLowerCase()} driver</span></div></div>${mapPanel('detail-route-map', 'Pickup & drop-off')}${matchingInfo(ride)}${meetingInfo(ride)}${routeRows(ride.pickup_label, ride.dropoff_label)}<div class="pickup-window">${icon('clock')}<span>Pickup window<strong>${pickupWindow(ride.departure_at)} IST</strong></span></div>${legBar(ride)}<p class="fine-print">Total walking: ${Number(ride.access_meters) + Number(ride.egress_meters)} m, including access and unmapped connections.</p><div class="fare-summary"><div><span>Base demo fare</span><strong>${money(ride.base_fare_minor)}</strong></div><div><span>${ride.choice === 'ON_ROUTE_MEETING_POINT' ? 'Meeting pickup extra' : 'Direct pickup extra'}</span><strong>+ ${money(ride.surcharge_minor)}</strong></div><div class="fare-total"><span>Total demo fare</span><strong>${money(ride.total_fare_minor)}</strong></div></div><p class="fine-print">Configurable demo pricing. This is a request; payment is confirmed separately after the driver accepts.</p><label class="fare-consent"><input id="fare-consent" type="checkbox"><span>${ride.choice === 'ON_ROUTE_MEETING_POINT' ? meetingConsent(ride) : 'I agree to this pickup window, journey and total demo fare.'}</span></label><p class="status" id="request-status" role="status"></p><button class="button primary full-width" id="request-confirm" type="button" disabled>Send ride request ${icon('arrow')}</button></article>`, 'review');
  mountSheetMap(dialog.querySelector('#detail-route-map'), mapForRide(ride, 'Pickup · '));
  const consent = dialog.querySelector('#fare-consent'), confirm = dialog.querySelector('#request-confirm');
  let sending = false; const version = state.sheetVersion;
  consent.onchange = () => { confirm.disabled = !consent.checked || sending; };
  confirm.onclick = async () => {
    if (!consent.checked || sending) return; sending = true; confirm.disabled = true;
    const generation = state.generation;
    try {
      await mutate(`commute/rides/${ride.trip_id}/request`, { quote_token: ride.quote_token });
      if (generation !== state.generation) return;
      void bookings().catch(() => {});
      if (version === state.sheetVersion) requestSuccess(ride);
    } catch (error) { if (generation === state.generation && version === state.sheetVersion) { status(error.message, true, 'request-status'); sending = false; confirm.disabled = !consent.checked; } }
  };
}
function requestSuccess(ride) {
  openSheet('Request sent', `<div class="request-success"><span class="success-mark">${icon('check')}</span><h3>You’re one step closer.</h3><p>${esc(ride.driver_name)} can now accept or decline your request.</p><div class="confirmation-summary"><span>${icon('clock')}${pickupWindow(ride.departure_at)} IST</span><strong>${money(ride.total_fare_minor)} demo fare</strong></div><span class="state-chip pending">Waiting for driver</span><button class="button primary full-width" data-nav-action="rides" type="button">View my ride ${icon('arrow')}</button><p class="fine-print">No demo credits are deducted until you confirm payment after acceptance.</p></div>`, 'confirmation');
}
function confirmCancel(id) {
  openSheet('Cancel this ride?', `<div class="cancel-content"><p>Your request will be cancelled. Any held demo payment for this planned ride will be refunded once.</p><p class="status" id="cancel-status" role="status"></p><button class="button primary full-width" id="confirm-cancel" type="button">Yes, cancel ride</button><button class="button outline full-width" type="button" data-close-confirm>Keep my ride</button></div>`, 'cancel');
  $('confirm-cancel').onclick = () => bookingAction('cancel', id);
  document.querySelector('[data-close-confirm]').onclick = closeSheet;
}
async function bookingAction(action, id) {
  const fingerprint = `${action}:${id}`; if (state.pendingActions.has(fingerprint)) return;
  state.pendingActions.add(fingerprint); state.bookingReadVersion++;
  const generation = state.generation;
  try {
    const result = await mutate(`commute/bookings/${encodeURIComponent(id)}/${action}`);
    if (generation !== state.generation) return;
    state.bookings = state.bookings.map(booking => String(booking.booking_id) === String(id) ? result.data : booking);
    if (action === 'cancel') closeSheet();
    render(); toast(({ accept: 'Request accepted. Waiting for rider payment.', reject: 'Request declined.', cancel: 'Ride cancelled. Any held demo fare has been refunded.', pay: 'Demo payment confirmed and held for your ride.', 'confirm-arrival': 'Arrival confirmed. The three-hour release hold has started.' })[action]);
    void bookings().catch(() => {});
  } catch (error) {
    if (generation !== state.generation) return;
    if (action === 'cancel' && state.sheet === 'cancel') status(error.message, true, 'cancel-status');
    else toast(error.message);
  } finally { state.pendingActions.delete(fingerprint); }
}
async function transition(action, trip, passenger) {
  const key = `transition:${trip}`; if (state.pendingActions.has(key)) return;
  state.pendingActions.add(key); state.bookingReadVersion++; const generation = state.generation;
  try { await mutate(`commute/trips/${trip}/transition`, { action, ...(passenger ? { passenger_id: Number(passenger) } : {}) }); if (generation !== state.generation) return; await bookings(); toast(({ start: 'Trip started.', board: 'Rider marked as boarded.', alight: 'Rider marked as alighted.', complete: 'Trip completed. The rider can now confirm arrival.' })[action]); }
  catch (error) { if (generation === state.generation) toast(error.message); }
  finally { state.pendingActions.delete(key); }
}
async function release() {
  const generation = state.generation;
  try { await mutate('commute/wallet/release-due'); if (generation !== state.generation) return; await bookings(); toast('Release eligibility checked. Completed holds appear in Wallet.'); }
  catch (error) { if (generation === state.generation) toast(error.message); }
}
function bindBookings(root) {
  root.querySelectorAll('[data-booking-map]').forEach(button => button.onclick = () => { const booking = state.bookings.find(row => String(row.booking_id) === button.dataset.bookingMap); if (booking) openRoute(booking); });
  root.querySelectorAll('.booking-action').forEach(button => button.onclick = () => {
    const { action, booking, trip, passenger } = button.dataset;
    if (action === 'cancel') confirmCancel(booking);
    else if (['accept', 'reject', 'pay', 'confirm-arrival'].includes(action)) void bookingAction(action, booking);
    else if (action === 'release-due') void release();
    else void transition(action, trip, passenger);
  });
  root.querySelectorAll('[data-chat-trip]').forEach(button => button.onclick = () => openChat(button.dataset.chatTrip));
}
async function switchRole(role) {
  if (role === state.role) return;
  const generation = state.generation;
  try {
    if (state.profile?.onboarding_complete) { const result = await api('commute/profile', { method: 'PUT', body: { ...state.profile, role } }); if (generation !== state.generation) return; applyMe(result); }
    state.role = role; state.rides = []; state.searchContext = null; state.searchVersion++; render();
    toast(`Switched to ${role} mode.${role === 'driver' && !state.driverApproved ? ' Driver approval is still required.' : ''}`);
  } catch (error) { if (generation === state.generation) status(error.message, true, 'mode-status'); }
}
function bindNavigation(root) {
  root.querySelectorAll('[data-nav-action]').forEach(button => button.onclick = () => navigate(button.dataset.navAction, 'bookings'));
  root.querySelectorAll('[data-day]').forEach(button => button.onclick = () => { state.selectedDate = button.dataset.day; render(); });
  root.querySelectorAll('[data-edit-profile]').forEach(button => button.onclick = () => openProfile(state.profile?.onboarding_complete ? 1 : 0));
  root.querySelectorAll('[data-create-ride]').forEach(button => button.onclick = () => openRide('outbound'));
  root.querySelectorAll('[data-find-direction], [data-edit-direction]').forEach(button => button.onclick = () => openRide(button.dataset.findDirection || button.dataset.editDirection));
  root.querySelectorAll('[data-change-time]').forEach(button => button.onclick = () => openRide(state.searchContext?.direction || state.direction, state.searchContext?.date || state.selectedDate));
  root.querySelectorAll('[data-role-switch]').forEach(button => button.onclick = () => switchRole(button.dataset.roleSwitch));
  root.querySelectorAll('[data-switch-demo]').forEach(button => button.onclick = () => { closeSheet(); signOut(false); void beginDemo(button.dataset.switchDemo); });
  root.querySelectorAll('[data-chat-trip]').forEach(button => button.onclick = () => openChat(button.dataset.chatTrip));
}
async function render() {
  pageMap?.dispose(); pageMap = null;
  chatUI?.dispose(); chatUI = null;
  const view = state.view, generation = state.generation;
  $('product-screen').dataset.view = view;
  document.title = `${state.user?.name || 'Mobility'} · Campus rides`;
  $('avatar-text').textContent = initials(state.user?.name);
  $('mode-label').textContent = state.role === 'driver' ? 'Driver' : 'Rider';
  $('page-title').textContent = ({ home: 'Your rides', rides: 'Find rides', wallet: 'Wallet', settings: 'Account', chats: 'Chats' })[view];
  ['home', 'rides', 'wallet', 'settings', 'chats'].forEach(name => { const section = $(`view-${name}`); section.classList.toggle('hidden', name !== view); section.innerHTML = ''; });
  document.querySelectorAll('[data-nav]').forEach(button => { const active = button.dataset.nav === view; button.classList.toggle('active', active); if (active) button.setAttribute('aria-current', 'page'); else button.removeAttribute('aria-current'); });
  const root = $(`view-${view}`);
  if (view === 'home' && state.role === 'driver' && state.driverApproved) {
    try { const result = await api('commute/offers'); if (generation !== state.generation || state.view !== view) return; state.offers = result.data || []; }
    catch (error) { if (generation !== state.generation) return; toast(error.message); }
  }
  if (view === 'wallet') {
    root.innerHTML = '<div class="loading-state">Loading your wallet…</div>';
    try { const wallet = await api('commute/wallet'); if (generation !== state.generation || state.view !== view) return; state.wallet = wallet; }
    catch (error) { if (generation === state.generation && state.view === view) root.innerHTML = `<div class="standard-panel"><h1>Wallet</h1><p class="notice">${esc(error.message)}</p></div>`; return; }
  }
  root.innerHTML = view === 'home' ? (state.role === 'driver' ? driverHome() : riderHome()) : view === 'rides' ? ridesView() : view === 'wallet' ? walletView() : view === 'chats' ? chatsView() : accountView();
  bindNavigation(root);
  const mapRoot = root.querySelector('#home-route-map, #results-route-map');
  if (mapRoot) { pageMap = mountMap(mapRoot, pageMapData(), {isCurrent: () => generation === state.generation && state.view === view}); }
  root.querySelectorAll('[data-result-map]').forEach(button => button.onclick = () => { const ride = state.rides.find(row => String(row.trip_id) === button.dataset.resultMap); if (ride) openRoute(ride); });
  root.querySelectorAll('[data-offer-map]').forEach(button => button.onclick = () => { const offer = state.offers.find(row => String(row.trip_id) === button.dataset.offerMap); if (offer) openRoute(offer); });
  const surface = $('booking-surface'); if (surface) { surface.innerHTML = bookingSurface(); bindBookings(surface); }
  root.querySelectorAll('.request-ride').forEach(button => button.onclick = () => showDetail(button));
  root.querySelector('#car-only')?.addEventListener('change', event => filterCars(event.target.checked));
  root.querySelector('#sign-out')?.addEventListener('click', () => signOut());
  root.querySelector('[data-chat-back]')?.addEventListener('click', () => { state.chatTrip = null; render(); });
  root.querySelector('#topup-form')?.addEventListener('submit', async event => {
    event.preventDefault(); const button = event.currentTarget.querySelector('button'); button.disabled = true;
    try { const wallet = await mutate('commute/wallet/top-up', { amount_minor: 50000 }); if (generation !== state.generation) return; state.wallet = wallet; if (state.view === 'wallet') render(); toast('500 demo credits added.'); }
    catch (error) { if (generation === state.generation && state.view === 'wallet') { status(error.message, true, 'wallet-status'); button.disabled = false; } }
  });
  if (view === 'chats') { if (state.chatTrip) mountChat(); else renderChatList(); }
  updateActivity();
}
function signOut(showMessage = true) {
  state.generation++; state.searchVersion++; state.bookingReadVersion++; clearInterval(state.poll); retryKeys.clear();
  chatUI?.dispose(); chatUI = null; pageMap?.dispose(); pageMap = null; closeSheet();
  Object.assign(state, { token: null, user: null, profile: null, role: 'rider', view: 'home', demo: false, driverApproved: false, rides: [], bookings: [], wallet: {}, chatTrip: null });
  $('product-screen').classList.add('hidden'); $('auth-screen').classList.remove('hidden');
  if (showMessage) status('Signed out. Choose a mode to try the demo again.');
}
if (typeof location !== 'undefined' && new URLSearchParams(location.search).get('demo') === 'campus-route') {
  document.querySelector('[data-demo-role="rider"] small').textContent = 'Laggere Rider · open saved commute';
  document.querySelector('[data-demo-role="driver"] small').textContent = 'Yeshwanthpur Driver · open saved commute';
  document.querySelector('.wordmark').href = '/mobility/?demo=campus-route';
}
document.querySelectorAll('[data-demo-role]').forEach(button => button.onclick = () => beginDemo(button.dataset.demoRole));
document.querySelectorAll('[data-nav]').forEach(button => button.onclick = () => navigate(button.dataset.nav));
$('profile-button').onclick = () => navigate('settings');
$('wallet-button').onclick = () => navigate('wallet');
$('activity-button').onclick = () => navigate('rides', 'bookings');
document.addEventListener('visibilitychange', () => { if (!document.hidden && state.token) void bookings().catch(() => {}); });
export { api, state, render, search, save, bookingAction, transition, beginDemo, navigate, openRide, openProfile, closeSheet, showDetail, filterCars, bookings, signOut };
