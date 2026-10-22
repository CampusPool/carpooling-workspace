const ENDPOINT_PATH = '/private-endpoints';
const QUOTE_PATH = '/private-quotes';
const SERVICE_AREA = Object.freeze({ minLat: 12.65, maxLat: 13.35, minLng: 77.2, maxLng: 77.95 });

export function pointFromFields(lat, lng) {
  if (lat === '' || lng === '' || lat == null || lng == null) return null;
  const point = { lat: Number(lat), lng: Number(lng) };
  return Number.isFinite(point.lat) && Number.isFinite(point.lng) && point.lat >= SERVICE_AREA.minLat && point.lat <= SERVICE_AREA.maxLat && point.lng >= SERVICE_AREA.minLng && point.lng <= SERVICE_AREA.maxLng ? point : null;
}
export function formatMoneyMinor(minor, currency = 'INR') { const value = Number(minor); return Number.isSafeInteger(value) && value >= 0 ? new Intl.NumberFormat('en-IN', { style: 'currency', currency, maximumFractionDigits: 2 }).format(value / 100) : 'Unavailable'; }
export function formatJourneyTime(value) {
  const text=String(value??''); const iso=/Z$|[+-]\d{2}:\d{2}$/.test(text)?text:text.replace(' ','T')+'Z';
  const date=new Date(iso); return Number.isNaN(date.getTime())?'Unavailable':new Intl.DateTimeFormat('en-IN',{dateStyle:'medium',timeStyle:'short',timeZone:'Asia/Kolkata'}).format(date)+' IST';
}
export function formatQuote(quote = {}) {
  const terms = quote.terms || {}, price = terms.pricing || {};
  return {
    base: formatMoneyMinor(price.base_fare_minor), surcharge: formatMoneyMinor(price.direct_pickup_surcharge_minor),
    total: formatMoneyMinor(price.total_fare_minor), driverExtra: formatMoneyMinor(price.driver_additional_compensation_minor),
    totalWalking: terms.total_walking_meters, maxWalk: terms.max_walk_meters,
    access: terms.access?.total_meters, egress: terms.egress?.total_meters,
    ridingDistance: terms.riding?.distance_meters, ridingDuration: terms.riding?.duration_seconds,
    expiresAt: quote.expires_at || null, hash: quote.quote_hash || '', version: quote.version
  };
}
export function createJourneyState() { return { session: 0, selection: null, originPoint: null, destinationPoint: null, originEndpointId: null, destinationEndpointId: null, quote: null, quoteId: null, scheduledTripId: null, scheduledCards: [] }; }
export function invalidateJourney(state) { state.session += 1; state.selection = null; state.originPoint = null; state.destinationPoint = null; state.originEndpointId = null; state.destinationEndpointId = null; state.quote = null; state.quoteId = null; state.scheduledTripId = null; state.scheduledCards = []; return state.session; }
export function selectionDto(point, source = 'map_pin') { const valid = pointFromFields(point?.lat, point?.lng); return valid ? { ...valid, source } : null; }
export function actionDto(expectedVersion, quoteHash, accessAcknowledged = false) { return { expected_version: expectedVersion, quote_hash: quoteHash, ...(accessAcknowledged ? { access_acknowledged: true } : {}) }; }
const text = value => String(value ?? '').trim();
const el = (tag, content, className) => { const node = document.createElement(tag); if (content !== undefined) node.textContent = content; if (className) node.className = className; return node; };
const endpointId = payload => payload?.data?.id ?? null;
const quotePayload = payload => payload?.data?.quote ?? null;
const apiError = error => error instanceof Error ? error.message : 'The private journey service is unavailable.';
const actorIs = (quote, id, role) => Number.isSafeInteger(Number(id)) && Number(id) > 0 && Number(quote?.terms?.[role]) === Number(id);

export function mountPrivateJourney(target, { api, getActorId = () => null, getSessionGeneration = () => 0, getSelection = () => null, onRequested = () => {}, onOpenTrip = () => {}, onPlanCreated = () => {} } = {}) {
  if (!target || typeof api !== 'function') throw new TypeError('mountPrivateJourney requires a target and api function.');
  const state = createJourneyState(); const listeners = []; const keys = new Map(); const planAttempts = new Map(); let disposed = false; let frame = null;
  const on = (node, type, fn) => { node.addEventListener(type, fn); if (node === window) listeners.push(() => node.removeEventListener(type, fn)); };
  const call = (path, method, body, headers = {}) => api(path, method, body, headers);
  const keyFor = fingerprint => { if (keys.has(fingerprint)) return keys.get(fingerprint); const value = globalThis.crypto?.randomUUID ? `private-${crypto.randomUUID()}` : `private-${Date.now()}-${Math.random().toString(36).slice(2)}`; keys.set(fingerprint, value); while (keys.size > 32) keys.delete(keys.keys().next().value); return value; };
  const generation = () => `${getSessionGeneration()}:${state.session}`; const current = token => !disposed && token === generation(); const status = el('p', '', 'private-journey-status'); status.setAttribute('role', 'status'); status.setAttribute('aria-live', 'polite');
  const root = el('section', undefined, 'private-journey'); const originCard = el('article', undefined, 'private-journey-card'); const destinationCard = el('article', undefined, 'private-journey-card'); const quoteCard = el('article', undefined, 'private-journey-card'); const recoverCard = el('article', undefined, 'private-journey-card'); const bookingsCard = el('article', undefined, 'private-journey-card'); root.append(el('h2', 'Private journey'), el('p', 'Agree the pickup, destination and demo fare, then request a ride. A seat is reserved after driver acceptance.'), status, originCard, destinationCard, quoteCard, bookingsCard, recoverCard); target.replaceChildren(root);
  const setStatus = (value, error = false) => { status.textContent = value; status.dataset.error = error ? 'true' : 'false'; }; const clear = node => node.replaceChildren(); const actor = () => getActorId(); const button = (label, fn) => { const b = el('button', label); b.type = 'button'; on(b, 'click', fn); return b; };
  function resetDestination() { state.session += 1; state.destinationPoint = null; state.destinationEndpointId = null; state.quote = null; state.quoteId = null; state.scheduledCards = []; }
  function renderSelection() { clear(originCard); clear(destinationCard); originCard.append(el('h3', 'Rider origin')); destinationCard.append(el('h3', 'Destination')); if (state.selection) { const point = state.selection.terms.rider_origin; originCard.append(el('p', 'Confirmed origin')); originCard.append(el('p', `${point.lat.toFixed(5)}, ${point.lng.toFixed(5)}`)); } else originCard.append(el('p', 'Waiting for a confirmed selection.')); if (!state.selection || Number(state.selection.terms.rider_id) !== Number(actor())) { destinationCard.append(el('p', 'The rider chooses the destination after confirming the pickup agreement.')); return; } destinationCard.append(el('p', 'Choose the destination on the map or enter coordinates.'));  frame = document.createElement('iframe'); frame.title = 'Choose destination on map'; frame.hidden = true; const showMap = button('Choose destination on map', () => { if (!frame.src) frame.src = '/mobility-pilot/maps?picker=destination'; frame.hidden = !frame.hidden; }); destinationCard.append(showMap); frame.loading = 'lazy'; frame.referrerPolicy = 'same-origin'; frame.className = 'private-journey-map'; destinationCard.append(frame); const form = document.createElement('form'); const lat = document.createElement('input'); lat.name = 'lat'; lat.type = 'number'; lat.step = 'any'; lat.min = String(SERVICE_AREA.minLat); lat.max = String(SERVICE_AREA.maxLat); lat.placeholder = 'Destination latitude'; lat.setAttribute('aria-label', 'Destination latitude'); lat.required = true; const lng = document.createElement('input'); lng.name = 'lng'; lng.type = 'number'; lng.step = 'any'; lng.min = String(SERVICE_AREA.minLng); lng.max = String(SERVICE_AREA.maxLng); lng.placeholder = 'Destination longitude'; lng.setAttribute('aria-label', 'Destination longitude'); lng.required = true; const submit = el('button', 'Use coordinates'); submit.type = 'submit'; form.append(lat, lng, submit); on(form, 'submit', event => { event.preventDefault(); void chooseDestination({ lat: lat.value, lng: lng.value }); }); destinationCard.append(form); }
  function renderQuote() { clear(quoteCard); quoteCard.append(el('h3', 'Pickup terms')); if (!state.quote) { quoteCard.append(el('p', 'No quote loaded.')); return; } const q = formatQuote(state.quote); quoteCard.append(el('p', 'Configurable demo pricing · INR · no payment is collected.'), el('p', 'Quote agreement: ' + state.quote.state), el('p', `Base fare: ${q.base}`), el('p', `Direct pickup surcharge: ${q.surcharge}`), el('p', `Total fare: ${q.total}`), el('p', `Driver additional compensation: ${q.driverExtra}`), el('p', `Walking: ${q.access ?? 'Unavailable'} m to pickup + ${q.egress ?? 'Unavailable'} m from drop-off = ${q.totalWalking ?? 'Unavailable'} m total · maximum ${q.maxWalk ?? 'Unavailable'} m`), el('p', `Riding: ${q.ridingDistance ?? 'Unavailable'} m · ${q.ridingDuration ?? 'Unavailable'} s`), el('p', q.expiresAt ? `Quote expires: ${formatJourneyTime(q.expiresAt)}` : 'Expiry was not provided.')); const review = el('label'); const check = document.createElement('input'); check.type = 'checkbox'; review.append(check, ' I agree to this exact journey and total fare. Walking access and stopping suitability remain unverified.'); if (state.quote.state === 'pending_driver' || state.quote.state === 'pending_rider') quoteCard.append(review); if (state.quote.state === 'pending_driver' && actorIs(state.quote, actor(), 'driver_id')) quoteCard.append(button('Driver approve', () => runAction('driver-approve', check))); if (state.quote.state === 'pending_rider' && actorIs(state.quote, actor(), 'rider_id')) quoteCard.append(button('Rider consent', () => runAction('rider-consent', check))); if (state.quote.state === 'accepted' && state.quote.terms?.ride_mode === 'on_demand' && actorIs(state.quote, actor(), 'rider_id')) quoteCard.append(button('Request this driver', () => requestDriver()));
    if (state.quote.state === 'accepted' && state.quote.terms?.ride_mode === 'scheduled') {
      if (actorIs(state.quote, actor(), 'driver_id')) { const form=document.createElement('form'); const date=document.createElement('input'); date.type='datetime-local'; date.required=true; date.setAttribute('aria-label','Scheduled departure in Bengaluru (IST)'); const seats=document.createElement('input'); seats.type='number'; seats.min='1'; seats.max=state.quote.terms.vehicle_type==='bike'?'1':'5'; seats.value=state.quote.terms.vehicle_type==='bike'?'1':'2'; seats.setAttribute('aria-label','Seats'); const submit=el('button','Publish scheduled ride'); submit.type='submit'; const dateLabel=el('label','Departure (Bengaluru, IST)');dateLabel.append(date);const seatsLabel=el('label','Seats offered');seatsLabel.append(seats);form.append(dateLabel,seatsLabel,submit); on(form,'submit',event=>{event.preventDefault();void publishScheduled(date.value,seats.value);}); quoteCard.append(el('p','Publish this immutable private route for the selected departure.'),form); }
      if (actorIs(state.quote, actor(), 'rider_id')) {
        quoteCard.append(button('Find compatible scheduled rides', () => void findScheduled()));
        for (const ride of state.scheduledCards) {
          const card = el('article', undefined, 'scheduled-ride-card');
          const pricing = ride.pricing || {};
          const time = value => new Intl.DateTimeFormat('en-IN', { dateStyle:'medium', timeStyle:'short', timeZone:'Asia/Kolkata' }).format(new Date(value));
          card.append(el('h4', `${ride.driver_name} · ${ride.vehicle_type === 'bike' ? 'Motorbike' : 'Car'} · ${ride.seats_available} seat(s) available`),
            el('p', `Departure ${time(ride.departure_at)} IST · estimated pickup ${time(ride.pickup_window.earliest)}–${time(ride.pickup_window.latest)} IST`),
            el('p', `Walk ${ride.access.meters} m → ride ${ride.riding.distance_meters} m (${Math.ceil(ride.riding.duration_seconds/60)} min) → walk ${ride.egress.meters} m · total walking ${ride.total_walking_meters} m`),
            el('p', `Demo base ${formatMoneyMinor(pricing.base_fare_minor)} + direct pickup ${formatMoneyMinor(pricing.direct_pickup_surcharge_minor)} = ${formatMoneyMinor(pricing.total_fare_minor)} · driver extra ${formatMoneyMinor(pricing.driver_additional_compensation_minor)}`),
            el('p', ride.rank_reason));
          const consent = el('label'); const agree = document.createElement('input'); agree.type='checkbox'; consent.append(agree, ' I agree to this departure, pickup window, exact journey and total demo fare.');
          if ((ride.booking_state === 0 && !ride.requires_consent_refresh) || ride.booking_state === 1) card.append(el('p', ride.booking_state === 1 ? 'Accepted · your seat is reserved' : 'Request pending driver approval'));
          else if (ride.booking_state === 2 || ride.booking_state === 3) card.append(el('p', 'This booking is closed. Agree a new journey quote before requesting again.'));
          else { if(ride.requires_consent_refresh)card.append(el('p','Review and confirm this current card to refresh your pending request.')); card.append(consent, button('Request this scheduled ride', () => { if (!agree.checked) { setStatus('Confirm this card’s departure, journey and total fare first.',true); return; } void requestScheduled(ride); })); }
          quoteCard.append(card);
        }
      }
    }
    if (['pending_driver','pending_rider','accepted'].includes(state.quote.state)) quoteCard.append(button('Revoke quote consent', () => runAction('revoke', check)));
    if (state.quote.state === 'pending_rider' && actorIs(state.quote, actor(), 'rider_id')) quoteCard.append(button('Decline', () => runAction('decline', check)));
    quoteCard.append(el('p', state.quote.terms?.ride_mode === 'on_demand' ? 'A request becomes a ride only after the selected driver accepts. No money is collected.' : 'A seat is reserved only after the driver accepts your request. Revoking quote consent affects future requests; cancel an existing booking separately. Pickup times are configurable demo estimates.'));  }
  function renderRecover() { clear(recoverCard); recoverCard.append(el('h3', 'Recover a quote')); const form = document.createElement('form'); const input = document.createElement('input'); input.name = 'id'; input.required = true; input.autocomplete = 'off'; input.placeholder = 'Quote ID'; input.setAttribute('aria-label', 'Private journey quote ID'); const submit = el('button', 'Load quote'); submit.type = 'submit'; form.append(input, submit); on(form, 'submit', event => { event.preventDefault(); void recoverQuote(text(input.value)); }); recoverCard.append(form); }
  async function createEndpoint(point, role) { const token = generation(); const dto = selectionDto(point); if (!dto) throw new Error('Choose a Bengaluru location.'); const response = await call(ENDPOINT_PATH, 'POST', dto, { 'Idempotency-Key': keyFor(`endpoint:${token}:${role}:${dto.lat}:${dto.lng}`) }); return current(token) ? endpointId(response) : null; }
  async function chooseDestination(point) { if (!state.selection) { setStatus('Confirm the rider origin first.', true); return; } if (!state.originEndpointId) { setStatus('Preparing the private origin. Try again shortly.', true); return; } resetDestination(); renderQuote(); const token = generation(); const dto = selectionDto(point); if (!dto) { setStatus('Choose a Bengaluru location.', true); return; } try { const destinationEndpointId = await createEndpoint(dto, 'destination'); if (!current(token)) return; state.destinationEndpointId = destinationEndpointId; state.destinationPoint = dto; const response = await call(QUOTE_PATH, 'POST', { selection_id: state.selection.id, origin_endpoint_id: state.originEndpointId, destination_endpoint_id: state.destinationEndpointId, expected_terms_hash: state.selection.terms_hash, expected_selection_version: state.selection.version }, { 'Idempotency-Key': keyFor(`quote:${token}:${state.selection.id}:${state.destinationEndpointId}`) }); if (!current(token)) return; state.quote = quotePayload(response); state.quoteId = state.quote?.id ?? null; renderQuote(); setStatus('Quote ready. Review the exact price and expiry.'); } catch (error) { if (current(token)) setStatus(apiError(error), true); } }
  async function loadOrigin(selection) { const point = selection?.terms?.rider_origin; if (selection?.state !== 'confirmed' || !selection?.id || !pointFromFields(point?.lat, point?.lng)) { setStatus('A confirmed rider origin is required.', true); return; } const token = generation(); try { state.selection = selection; renderSelection(); if (Number(selection.terms.rider_id) !== Number(actor())) return; const originEndpointId = await createEndpoint(point, 'origin'); if (!current(token)) return; state.originEndpointId = originEndpointId; state.selection = selection; state.originPoint = point; setStatus('Origin confirmed. Choose a destination.'); } catch (error) { if (current(token)) setStatus(apiError(error), true); } }
  async function recoverQuote(id) { state.session += 1; state.quote = null; state.quoteId = null; state.scheduledCards=[]; renderQuote(); const token = generation(); try { const response = await call(`${QUOTE_PATH}/${encodeURIComponent(id)}`, 'GET'); if (!current(token)) return; state.quote = quotePayload(response); state.quoteId = id; renderQuote(); setStatus('Quote recovered. Check its expiry before acting.'); } catch (error) { if (current(token)) setStatus(apiError(error), true); } }
  async function runAction(action, checkbox) { if (!state.quote || (['driver-approve', 'rider-consent'].includes(action) && !checkbox.checked)) { setStatus('Review and accept the exact price and revision first.', true); return; } const q = formatQuote(state.quote); const token = generation(); try { const response = await call(`${QUOTE_PATH}/${encodeURIComponent(state.quoteId)}/${action}`, 'POST', actionDto(q.version, q.hash, action === 'rider-consent'), { 'Idempotency-Key': keyFor(`action:${action}:${state.quoteId}:${q.version}:${q.hash}`) }); if (!current(token)) return; state.quote = quotePayload(response); renderQuote(); setStatus(`${action.replace('-', ' ')} recorded. The server saved this exact journey revision.`); } catch (error) { if (current(token)) setStatus(apiError(error), true); } }
  async function publishScheduled(departure,seats) { const quote=state.quote,token=generation(); if(!quote||!actorIs(quote,actor(),'driver_id'))return; try { const response=await call('/private-scheduled-publications','POST',{quote_id:quote.route_quote_id,trip_date:departure+':00+05:30',total_seats:Number(seats)},{'Idempotency-Key':keyFor('scheduled-publish:'+quote.id+':'+departure+':'+seats)}); if(!current(token))return; state.scheduledTripId=String(response?.data?.trip_id||response?.data?.id||''); setStatus('Scheduled ride published. The rider can find it from their agreed journey.'); renderQuote(); await loadBookings(); } catch(error){if(current(token))setStatus(apiError(error),true);} }
  async function findScheduled() {
    const quote=state.quote, token=generation(); if (!quote || !actorIs(quote,actor(),'rider_id')) return;
    try {
      const response=await call('/private-scheduled-publications?quote_id='+encodeURIComponent(quote.route_quote_id),'GET');
      if (!current(token) || quote !== state.quote) return;
      state.scheduledCards=Array.isArray(response?.data)?response.data:[]; renderQuote();
      setStatus(state.scheduledCards.length ? 'Compatible rides ranked by earliest departure. Review the exact card before requesting.' : 'No published ride matches this agreed route. Ask the driver to publish it or agree a new route.');
    } catch(error) { if(current(token)) setStatus(apiError(error),true); }
  }
  async function requestScheduled(ride) {
    const quote=state.quote, token=generation(); if(!quote || !actorIs(quote,actor(),'rider_id') || ride.quote_id!==quote.route_quote_id || ride.quote_hash!==quote.quote_hash) return;
    try {
      await call(`/trips/${encodeURIComponent(ride.trip_id)}/private-scheduled-request`,'POST',{quote_id:quote.route_quote_id,expected_quote_hash:ride.quote_hash,expected_departure_at:ride.departure_at,expected_interval_hash:ride.interval_hash},{'Idempotency-Key':keyFor('scheduled-request:'+ride.trip_id+':'+quote.id+':'+ride.interval_hash+':'+Boolean(ride.requires_consent_refresh))});
      if(!current(token))return;
      state.scheduledTripId=String(ride.trip_id); setStatus('Request saved. Your seat is pending driver approval.'); await loadBookings();
    } catch(error){if(current(token))setStatus(apiError(error),true);}
  }
  async function loadBookings() {
    const token=generation();
    try {
      const response=await call('/private-scheduled-bookings','GET'); if(!current(token))return;
      const trips=Array.isArray(response?.data)?response.data:[];
      if(state.scheduledCards.length){state.scheduledCards=state.scheduledCards.map(card=>{const trip=trips.find(item=>Number(item.trip_id)===Number(card.trip_id));const booking=trip?.bookings.filter(item=>Number(item.rider_id)===Number(actor())).at(-1);return trip&&booking?{...card,booking_state:booking.request_state,requires_consent_refresh:booking.requires_consent_refresh,seats_available:trip.seats_available??card.seats_available}:card;});renderQuote();}
      clear(bookingsCard); bookingsCard.append(el('h3','My scheduled rides'),button('Refresh scheduled bookings',()=>void loadBookings()));
      if (!trips.length) bookingsCard.append(el('p','No scheduled rides yet.'));
      const states={0:'Pending approval',1:'Accepted',2:'Rejected',3:'Cancelled'};
      for(const trip of trips){
        const card=el('article',undefined,'scheduled-booking-card'); const when=new Intl.DateTimeFormat('en-IN',{dateStyle:'medium',timeStyle:'short',timeZone:'Asia/Kolkata'}).format(new Date(trip.departure_at));
        card.append(el('h4',`${trip.owner?'Driving':'Riding'} · ${when} IST · ${trip.state}`));
        if(trip.owner && trip.state==='planned' && trip.publication_id && trip.route_hash) card.append(button('Create private invite for another rider',()=>void createPublicationPlan(trip)));
        for(const booking of trip.bookings){
          const row=el('div'); row.append(el('p',`${booking.rider_name} · ${states[booking.request_state]||'Unknown'} · exact demo total ${formatMoneyMinor(booking.fare_minor)}`));
          if(trip.state==='planned'){
            if(booking.requires_consent_refresh)row.append(el('p','The rider must find this ride and confirm its current card before approval. If the quote has expired, cancel this request and agree a new journey.'));
            if(trip.owner && booking.request_state===0){if(!booking.requires_consent_refresh)row.append(button('Accept '+booking.rider_name,()=>void bookingAction(trip,booking,'accept')));row.append(button('Reject '+booking.rider_name,()=>void bookingAction(trip,booking,'reject')));}
            if(booking.request_state===0 || booking.request_state===1) row.append(button(trip.owner?'Cancel rider booking':'Cancel my booking',()=>void bookingAction(trip,booking,'cancel')));
          }
          card.append(row);
        }
        if(trip.owner || trip.bookings.some(booking=>booking.request_state===1)) card.append(button('Open trip and tracking',()=>void onOpenTrip(trip.trip_id)));
        bookingsCard.append(card);
      }
    }catch(error){if(current(token))setStatus(apiError(error),true);}
  }
  async function createPublicationPlan(trip){
    const token=generation();
    if(!planAttempts.has(trip.publication_id))planAttempts.set(trip.publication_id,globalThis.crypto?.randomUUID?.()||String(Date.now()));
    const attempt=planAttempts.get(trip.publication_id);
    try{
      const result=await call('/pickup-plans','POST',{source_publication_id:trip.publication_id,expected_route_hash:trip.route_hash},{'Idempotency-Key':keyFor('publication-plan:'+trip.publication_id+':'+trip.route_hash+':'+attempt)});
      if(!current(token))return;
      planAttempts.delete(trip.publication_id);
      setStatus('Private route invite created. Copy it privately from the driver route plan. Existing bookings keep their agreed route.');
      await onPlanCreated(result);
    }catch(error){if(current(token))setStatus(apiError(error),true);}
  }
  async function bookingAction(trip,booking,action){
    const token=generation();
    try {await call(`/trips/${trip.trip_id}/private-scheduled-bookings/${booking.rider_id}/${action}`,'POST',{}, {'Idempotency-Key':keyFor('scheduled-action:'+trip.trip_id+':'+booking.booking_id+':'+action)}); if(!current(token))return; setStatus('Booking '+action+' recorded.'); await loadBookings();}
    catch(error){if(current(token))setStatus(apiError(error),true);}
  }
  async function requestDriver() {
    const quote = state.quote, token = generation();
    if (!quote || quote.state !== 'accepted' || !actorIs(quote,actor(),'rider_id')) return;
    try {
      const request = await call('/dispatch/requests','POST',{quote_id:quote.route_quote_id},{'Idempotency-Key':keyFor('request:'+quote.route_quote_id)});
      if (!current(token)) return;
      setStatus('Request saved. Waiting for the selected driver.');
      await onRequested(request);
    } catch (error) { if (current(token)) setStatus(apiError(error),true); }
  }
  const picker = event => { if (event.origin !== location.origin || event.source !== frame?.contentWindow || event.data?.type !== 'private-journey-selection' || event.data.endpoint !== 'destination') return; void chooseDestination(event.data.point); };
  on(window, 'message', picker); renderSelection(); renderQuote(); renderRecover(); bookingsCard.append(el('h3','My scheduled rides'),button('Refresh scheduled bookings',()=>void loadBookings())); void loadOrigin(getSelection());
  return { clear() { planAttempts.clear(); invalidateJourney(state); renderSelection(); renderQuote(); clear(bookingsCard); setStatus('Journey cleared.'); }, dispose() { disposed = true; listeners.splice(0).forEach(fn => fn()); target.replaceChildren(); }, state };
}
