'use strict';
(() => {
  // Evaluation only. JWT is never written to disk, cookies or browser storage.
  let token = null;
  const tripChats = new Map();
  let chatNotificationTimer = null, chatNotificationLoading = false;
  let identity = null;
  let membership = null;
  let capabilities = { discover: false, request: false, publish: false };
  let working = false;
  let routeQuote = null;
  let publicationAttempt = null;
  let pickupCursor = null;
  let searchPointCursor = null;
  let discoveryCursor = null;
  let discoveryQuery = null;
  let programCursor = null;
  let cashDisputeCursor = null;
  let cashDisputeLoading = false;
  let cashDisputeSessionEpoch = 0;
  let reportSessionEpoch = 0;
  let reportIdempotencyKey = null;
  let reportKeyFingerprint = null;
  let supportTicketSessionEpoch = 0;
  let supportTicketPage = 1;
  let supportTicketLastPage = 1;
  let userTicketEntries = [];
  let userTicketVisible = 25;
  let selectedProgram = null;
  const programAttempts = new Map();
  const programPrices = new Map();
  const matchAttempts = new Map();
  const viaSelects = () => Array.from($('quote-vias').querySelectorAll('select'));
  const $ = (id) => document.getElementById(id);
  const hidden = (node, yes) => node.classList.toggle('hidden', yes);
  function notice(text, error = false, login = false) {
    const target = $(login ? 'login-status' : 'action-status');
    target.textContent = text;
    target.classList.toggle('error', error);
  }
  function formNotice(id, text, error = false) {
    const target = $(id); if (!target) return;
    target.textContent = text; target.classList.toggle('error', error);
  }
  function el(name, text, className) {
    const node = document.createElement(name);
    if (text !== undefined && text !== null) node.textContent = String(text);
    if (className) node.className = className;
    return node;
  }
  function empty(target, text) {
    target.replaceChildren(el('div', text, 'empty'));
  }
  function list(json) {
    if (Array.isArray(json?.data)) return json.data;
    if (Array.isArray(json?.data?.data)) return json.data.data;
    return Array.isArray(json) ? json : [];
  }
  function tripId(trip) {
    const id = Number(trip?.id ?? trip?.trip_id);
    return Number.isSafeInteger(id) && id > 0 ? id : null;
  }
  function newReportKey() {
    if (globalThis.crypto?.randomUUID) return 'web-report-' + crypto.randomUUID();
    const bytes = new Uint8Array(16); crypto.getRandomValues(bytes);
    return 'web-report-' + Array.from(bytes, (value) => value.toString(16).padStart(2, '0')).join('');
  }
  function reportSessionValid(epoch, sessionToken) { return epoch === reportSessionEpoch && sessionToken === token; }
  function errorLabel(body, status) {
    const code = typeof body?.error === 'string' ? body.error :
      typeof body?.error?.code === 'string' ? body.error.code :
      typeof body?.message === 'string' ? body.message : 'Request failed';
    const plain = { DRIVER_ADMISSION_BUSY: 'This driver is handling another booking. Try again shortly.', DRIVER_ROUTE_OVERLAP: 'The driver has an accepted ride during this time. Choose another departure.', DRIVER_ACTIVE_TRIP: 'The driver must finish or cancel their active trip before accepting another ride.', DRIVER_UNRESOLVED_TRIP: 'An earlier accepted ride needs driver or operator recovery before another booking.', MATCH_REFERENCE_EXPIRED: 'This match expired. Search again for a fresh seat.', MATCH_ELIGIBILITY_BUSY: 'This ride is being updated. Try again shortly.', MATCH_ROUTE_CHANGED: 'The route changed. Search again for current rides.', MATCH_VEHICLE_CHANGED: 'The host vehicle changed. Search again for current rides.', MATCH_REFERENCE_STALE: 'This match changed. Search again.', BOOKING_ALREADY_ACTIVE: 'You already have a request for this ride.', PROGRAM_PAUSED: 'The host paused new requests for this repeating plan.', OFFER_EXPIRED: 'This offer expired. Refresh dispatch for a fresh offer.', REQUEST_UNAVAILABLE: 'This request is no longer active.', DRIVER_UNAVAILABLE: 'The driver is no longer online. Refresh dispatch.', ELIGIBILITY_CHANGED: 'The approved vehicle or pickup zone changed. Refresh dispatch.', MEMBERSHIP_REQUIRED: 'Approved college access is required for dispatch.', QUOTE_UNAVAILABLE: 'This quote is stale or already reserved. Get a fresh quote.', REQUEST_TERMINAL: 'This request is already closed.' };
    const pickupErrors = {
      PICKUP_WALK_LIMIT_EXCEEDED: 'Walking must stay below 1 km. Choose a closer pickup point or refresh this request.', MALFORMED_OPTION: 'These route details cannot be used. Evaluate a fresh pickup preview.', LINKAGE_MISMATCH: 'This proposal no longer matches the ride inputs. Refresh the plan and preview.', SELECTION_FORBIDDEN: 'Only the rider and driver can view this pickup agreement.', WRONG_ACTOR: 'This action belongs to the other participant.', IDEMPOTENCY_CONFLICT: 'This retry no longer matches the original request. Reload the current agreement.', CONFIRMATION_REQUIRES_REVOKE: 'Revoke an agreed pickup before proposing another arrangement.', VEHICLE_CHANGED: 'The approved vehicle changed. Refresh the plan and pickup proposal.', PICKUP_UNAVAILABLE: 'Pickup is unavailable right now. Reload before retrying.',
      PLAN_EXPIRED: 'This route plan expired. Create a current plan.', INTENT_EXPIRED: 'This pickup request expired. Share a current origin.',
      PREVIEW_EXPIRED: 'This preview expired. Evaluate the current route again.', PREVIEW_STALE: 'The route or origin changed. Evaluate a new preview.',
      INTENT_STALE: 'The driver route changed. Update your origin request before previewing.', PREVIEW_VERSION_STALE: 'The route or origin changed. Refresh before retrying.',
      PLAN_VERSION_STALE: 'The route changed. Load its current version.', INTENT_VERSION_STALE: 'Your origin changed. Load the current request.',
      PICKUP_GRAPH_MISMATCH: 'Routing information changed. Refresh the plan and preview.', ROUTE_GRAPH_MISMATCH: 'Routing information changed. Refresh the plan and preview.',
      ROUTING_MISCONFIGURED: 'Pickup routing is not available in this environment yet.', ROUTE_PROVIDER_UNAVAILABLE: 'Routing is unavailable. Try again shortly.',
      PICKUP_ROUTING_DEADLINE: 'Routing took too long. Retry the preview.', NO_ROUTE: 'No suitable route was found for these locations.',
      PLAN_INVITE_INVALID: 'The private invite is invalid or has changed. Ask the driver for the current invite.',
      INTENT_FORBIDDEN: 'Only this rider and driver can view the pickup request.', PLAN_OWNER_REQUIRED: 'Only the route owner can perform this action.', SELECTION_EXPIRED: 'This pickup proposal expired. Refresh the current preview.', REVISION_MISMATCH: 'These pickup terms are stale. Refresh the current plan and preview.', VERSION_MISMATCH: 'The pickup agreement changed. Reload the current selection.', HASH_MISMATCH: 'The pickup terms changed. Reload the current selection.', SELECTION_CLOSED: 'This pickup agreement is already closed.', CHOICE_UNAVAILABLE: 'That pickup option is unavailable. Refresh the preview.'
    };
    const scheduledErrors = {
      PUBLICATION_PLAN_IMMUTABLE: 'This invite preserves an existing ride. Create a new route publication to change its path.',
      PRIVATE_PUBLICATION_BUSY: 'This scheduled ride is being updated. Retry shortly.',
      PRIVATE_PUBLICATION_STALE: 'The published route changed. Refresh your scheduled rides before creating another invite.',
      PRIVATE_PUBLICATION_INVALID: 'This published ride is closed or its route changed. Refresh your scheduled rides.',
      PUBLICATION_ROUTE_INCOMPATIBLE: 'These stops require a different route. Choose on-route meeting points or agree a separate ride.',
      PRIVATE_CONSENT_CHANGED: 'The journey, departure or price changed. Find the ride again and confirm its current card.',
      PRIVATE_SCHEDULE_CARD_CONSENT_REQUIRED: 'Open the agreed journey, find the scheduled ride and confirm its exact card first.',
      PRIVATE_SCHEDULE_CONSENT_REFRESH_REQUIRED: 'The rider must confirm the current scheduled ride card before approval.',
      PRIVATE_ROUTE_INCOMPATIBLE: 'This journey does not follow the unchanged driver route. Agree a compatible pickup and destination.',
      PRIVATE_WALKING_LIMIT_EXCEEDED: 'Total walking, including access gaps, exceeds your preference or reaches 1 km. Choose closer stops.',
      PRIVATE_QUOTE_UNAVAILABLE: 'This quote expired or belongs to another ride. Agree a fresh journey; accepted bookings remain saved.',
      PRIVATE_SCHEDULE_NOT_PLANNED: 'This ride has departed or is closed for new bookings. Refresh your scheduled rides.',
      NOT_SEAT_AVAILABLE: 'This portion of the route is full. Try another ride or retry if a rider cancels.',
      PRIVATE_SCHEDULE_CANCEL_REQUIRES_RECOVERY: 'This trip has started. Use trip reporting or operator recovery for help.',
      BOARDING_CAPACITY_EXCEEDED: 'Alight the previous rider before boarding another passenger.',
      PARTICIPANT_BLOCKED: 'A participant block prevents this new booking or message. Existing accepted rides remain saved.'
    };
    if (scheduledErrors[code]) return scheduledErrors[code];
    if (pickupErrors[code]) return pickupErrors[code];
    if (plain[code]) return plain[code];
    const details = body?.error?.validation;
    const messages = details && typeof details === 'object' ? Object.values(details).flat() : [];
    const first = messages.find((value) => typeof value === 'string');
    return String(first || code).slice(0, 180) + ' (HTTP ' + status + ')';
  }
  async function api(path, method = 'GET', data, includeToken = true, extraHeaders = {}) {
    if (includeToken && !token) throw new Error('Please sign in first.');
    const headers = { 'Accept': 'application/json', ...extraHeaders };
    if (includeToken) headers.Authorization = 'Bearer ' + token;
    if (data !== undefined) headers['Content-Type'] = 'application/json';
    const response = await fetch('/api/' + path.replace(/^\//, ''), {
      method, headers, ...(data !== undefined ? { body: JSON.stringify(data) } : {}),
      cache: 'no-store', credentials: 'same-origin'
    });
    let json;
    try { json = await response.json(); } catch { json = {}; }
    if (!response.ok) {
      if (response.status === 401 && includeToken) signOut();
      const error = new Error(errorLabel(json, response.status));
      error.status = response.status;
      error.code = typeof json?.error === 'string' ? json.error : json?.error?.code;
      throw error;
    }
    return json;
  }
  async function task(fn) {
    if (working) return;
    working = true;
    notice('');
    try { await fn(); } catch (error) { notice(error.message || 'Could not complete request.', true); }
    finally { working = false; }
  }
  function signOut() {
    clearInterval(chatNotificationTimer); chatNotificationTimer = null;
    for (const controller of tripChats.values()) controller.dispose(); tripChats.clear();
    if ($('chat-notifications')) { $('chat-notifications').hidden = true; $('chat-notifications').textContent = 'Messages'; }
    trackingBestEffortStop();
    trackingStopAll();
    if (typeof driverSessionEpoch === 'number') driverSessionEpoch++;
    for (const close of driverPreviewClosers) close();
    if (typeof driverOnboardings !== 'undefined') driverOnboardings = [];
    pickupSessionEpoch++; pickupPlan = null; pickupIntent = null; pickupPreview = null; pickupSelection = null; pickupAttemptKeys.clear(); pickupSelectionKeys.clear();
    stopDispatchPolling();
    dispatchSessionEpoch++; dispatchKeys?.clear?.(); dispatchQuote = null; dispatchRequestId = null; dispatchOnline = false;
    cashDisputeSessionEpoch++;
    reportSessionEpoch++; supportTicketSessionEpoch++; reportIdempotencyKey = null; reportKeyFingerprint = null;
    cashDisputeLoading = false;
    token = null;
    programAttempts.clear(); programPrices.clear(); matchAttempts.clear(); selectedProgram = null;
    $('quote-vias').replaceChildren();
    invalidateQuote();
    identity = null;
    membership = null;
    capabilities = { discover: false, request: false, publish: false };
    $('login-form').reset();
    hidden($('auth-panel'), false);
    hidden($('dashboard'), true);
    hidden($('logout'), true);
    $('trip-results').replaceChildren();
    $('hosted-list').replaceChildren();
    $('booking-list').replaceChildren();
    $('cash-disputes-queue').replaceChildren();
    $('cash-disputes-status').textContent = '';
    $('cash-disputes-status').classList.remove('error');
    $('more-cash-disputes').hidden = true;
    cashDisputeCursor = null;
    $('dispatch-request-card').replaceChildren(el('p', 'No request loaded.', 'empty'));
    $('dispatch-offers').replaceChildren(el('p', 'No pending offers.', 'empty'));
    $('dispatch-trip-controls').replaceChildren(el('p', 'Accept an offer or recover a request to load the assigned trip.', 'empty'));
    $('dispatch-request-form').reset(); $('dispatch-recover-form').reset(); $('dispatch-availability-form').reset();
    $('trip-report-form').reset(); $('report-trip-id').replaceChildren(el('option', 'Load your trips first'));
    $('trip-report-tickets').replaceChildren(); $('trip-report-detail').replaceChildren(); hidden($('trip-report-detail'), true);
    $('support-tickets-queue').replaceChildren(); $('support-ticket-detail').replaceChildren(); hidden($('support-ticket-detail'), true); supportTicketPage = 1; supportTicketLastPage = 1; userTicketEntries = []; userTicketVisible = 25;
    $('dispatch-request').disabled = true; $('dispatch-request-status').textContent = '';
    $('pickup-plan-form').reset(); $('pickup-intent-form').reset(); $('pickup-preview-form').reset(); $('pickup-plan-recover-form').reset(); $('pickup-intent-recover-form').reset(); $('pickup-preview-recover-form').reset();
    $('pickup-plan-result').textContent = 'No plan loaded. The invite token is shown once; copy it privately.'; $('pickup-intent-result').textContent = 'No intent loaded. No booking is created.'; $('pickup-preview-result').replaceChildren(el('p', 'No preview loaded.', 'empty')); $('pickup-recover-status').textContent = ''; $('pickup-selection-recover-form').reset(); clearPickupSelection(); hidden($('pickup-agreement-panel'), true);
    hidden($('operator-tab'), true);
    $('membership-summary').textContent = 'Sign in to check your membership status.';
    $('membership-badge').textContent = 'Signed out';
    notice('Signed out. No login token was saved.', false, true);
  }
  $('logout').addEventListener('click', signOut);
  $('register-form').addEventListener('submit', (event) => {
    event.preventDefault();
    const form = event.currentTarget;
    void task(async () => {
      const values = Object.fromEntries(new FormData(form));
      if (values.password !== values.password_confirmation) throw new Error('Passwords do not match.');
      formNotice('register-status', 'Creating account…');
      const response = await api('users', 'POST', values, false);
      formNotice('register-status', response?.token ? 'Account created and ready to sign in.' : 'Account created. Activate it from the email or with an administrator-provided token before signing in.');
      form.reset();
    });
  });
  $('activation-form').addEventListener('submit', (event) => {
    event.preventDefault();
    const form = event.currentTarget;
    void task(async () => {
      const tokenValue = String(new FormData(form).get('token') || '').trim();
      if (!tokenValue) throw new Error('Enter an activation token.');
      formNotice('activation-status', 'Activating account…');
      const response = await api('activate/' + encodeURIComponent(tokenValue), 'POST', undefined, false);
      if (typeof response?.token !== 'string' || response.token.length < 20) throw new Error('Activation did not return a login token.');
      await enterSession(response.token);
      formNotice('activation-status', 'Account activated.');
      form.reset();
    });
  });
  $('reset-form').addEventListener('submit', (event) => {
    event.preventDefault();
    const form = event.currentTarget;
    void task(async () => {
      formNotice('reset-status', 'Requesting reset email…');
      await api('reset-password', 'POST', Object.fromEntries(new FormData(form)), false);
      formNotice('reset-status', 'If the account is eligible, a reset email was requested. No email delivery is claimed by this pilot.');
      form.reset();
    });
  });
  async function enterSession(nextToken) {
    dispatchSessionEpoch++;
    cashDisputeSessionEpoch++;
    cashDisputeLoading = false;
    token = nextToken;
    reportSessionEpoch++; supportTicketSessionEpoch++;
    const profile = await api('users/me');
    identity = profile?.data ?? profile;
    if (!Number.isSafeInteger(Number(identity?.id))) throw new Error('Server did not return an account ID.');
    hidden($('auth-panel'), true); hidden(document.querySelector('.auth-tools'), true);
    hidden($('dashboard'), false); hidden($('logout'), false);
    $('identity').textContent = 'Signed in as ' + String(identity.name || identity.email || 'Pilot rider');
    if (identity.is_admin === true || identity.is_admin === 1) { hidden($('operator-tab'), false); try { await loadOperator(); } catch (error) { empty($('membership-queue'), 'Operator review is unavailable right now.'); empty($('host-queue'), 'Operator review is unavailable right now.'); } }
    try { await loadMembership(); } catch (error) {
      if (!String(error.message).includes('HTTP 503')) throw error;
      showMembershipUnavailable();
      // The cohort service is unavailable, but existing authenticated users
      // retain the canonical own-ride recovery view.
      await task(loadMine);
    }
    if (typeof loadDriverSession === 'function') await loadDriverSession();
  }
  $('login-form').addEventListener('submit', async (event) => {
    event.preventDefault();
    const credentials = Object.fromEntries(new FormData(event.currentTarget));
    notice('Signing in…', false, true);
    try {
      const login = await api('login', 'POST', credentials, false);
      if (typeof login.token !== 'string' || login.token.length < 20) throw new Error('Server did not return a login token.');
      await enterSession(login.token);
      $('login-form').reset();
      notice('', false, true);
    } catch (error) {
      signOut();
      notice(error.message || 'Sign-in failed.', true, true);
    }
  });
  function showView(view) {
    if (view !== 'dispatch') stopDispatchPolling();
    for (const section of document.querySelectorAll('.view')) hidden(section, section.id !== view);
    for (const button of document.querySelectorAll('.tab')) {
      const yes = button.dataset.view === view;
      button.classList.toggle('active', yes);
      button.setAttribute('aria-selected', String(yes));
    }
  }
  const pickupTab = el('button', 'Pickup preview', 'tab');
  pickupTab.type = 'button'; pickupTab.dataset.view = 'pickup-preview'; pickupTab.setAttribute('aria-selected', 'false');
  document.querySelector('.tabs').insertBefore(pickupTab, document.querySelector('[data-view="dispatch"]'));
  pickupTab.addEventListener('click', () => { showView('pickup-preview'); void task(async () => { await loadPickupPreviewView(); if (!privateJourneyPanel) await refreshPrivateJourneyPanel(); }); });
  for (const button of document.querySelectorAll('.tab')) button.addEventListener('click', () => {
    showView(button.dataset.view);
    if (button.dataset.view === 'mine') void task(loadMine);
    if (button.dataset.view === 'programs') void task(() => loadPrograms());
    if (button.dataset.view === 'dispatch') void task(loadDispatch);
  });

  async function loadMembership() {
    const response = await api('mobility/membership/me');
    membership = response?.membership ?? response?.data?.membership ?? null;
    capabilities = response?.capabilities ?? response?.data?.capabilities ?? {};
    capabilities = { discover: capabilities.discover === true, request: capabilities.request === true, publish: capabilities.publish === true };
    renderMembership(response);
    if (capabilities.publish) { await loadDemoRoutes(); } else { $('demo-route').replaceChildren(el('option', 'Membership and host approval required')); }
    configureQuotedOffers(response);
    if (capabilities.discover) {
      if (!$('ride-date').value) $('ride-date').value = indiaDate();
      await loadSearchPoints();
      await loadTrips();
    } else empty($('trip-results'), 'Membership approval is required before available rides can be shown.');
  }
  function showMembershipUnavailable() {
    membership = null; capabilities = { discover: false, request: false, publish: false };
    $('membership-badge').textContent = 'Service unavailable';
    $('membership-summary').textContent = 'College access is temporarily unavailable because this service has not been configured. Try again later or contact the operator.';
    $('membership-form').classList.add('hidden');
    $('host-approval-area').replaceChildren();
    invalidateQuote();
    $('quote-car').replaceChildren(el('option', 'College access service unavailable'));
    $('demo-route').replaceChildren(el('option', 'College access service unavailable'));
    empty($('trip-results'), 'Ride discovery is unavailable until college access is configured. Your existing rides remain available under My rides.');
  }
  function renderMembership(response) {
    const status = String(membership?.status || 'not_applied').toLowerCase();
    const labels = { approved: 'Approved', pending: 'Pending review', rejected: 'Rejected', suspended: 'Suspended', not_applied: 'Not applied' };
    $('membership-badge').textContent = labels[status] || status;
    const next = { approved: 'You can discover rides and request seats. Host approval is still required before publishing.', pending: 'An operator needs to review your college application.', rejected: 'This application was rejected. Contact the operator for next steps.', suspended: 'Your access is suspended. Existing trip recovery remains available.', not_applied: 'Apply with your college code and affiliation to request access.' };
    $('membership-summary').textContent = next[status] || 'Membership status is unavailable.';
    $('membership-form').classList.toggle('hidden', status !== 'not_applied');
    const approvals = response?.host_applications ?? response?.data?.host_applications ?? [];
    const area = $('host-approval-area'); area.replaceChildren();
    area.append(el('h3', 'Host applications'));
    if (!approvals.length) area.append(el('p', 'No vehicle applications yet.'));
    for (const app of approvals) area.append(el('p', 'Vehicle #' + String(app.car_id) + ' · ' + String(app.vehicle_type) + ' · ' + String(app.status)));
    if (status === 'approved') area.append(el('p', 'Use the Driver onboarding tab to register an owned vehicle and submit the private checklist for manual review.', 'note'));
  }
  $('membership-form').addEventListener('submit', (event) => {
    event.preventDefault();
    void task(async () => { const result = await api('mobility/membership/apply', 'POST', Object.fromEntries(new FormData(event.currentTarget))); formNotice('membership-status', 'Application submitted. Status: ' + String(result.status || 'pending') + '.'); await loadMembership(); });
  });
  const cashCurrency = new Intl.NumberFormat('en-IN', { style: 'currency', currency: 'INR' });
  function cashMinorINR(value) {
    const amount = Number(value);
    return Number.isSafeInteger(amount) ? cashCurrency.format(amount / 100) : 'Amount unavailable';
  }
  function cashValue(value, fallback = 'Unavailable') {
    return value === null || value === undefined || value === '' ? fallback : String(value);
  }
  function cashFact(label, value, className = 'cash-fact') {
    const paragraph = el('p', null, className);
    paragraph.append(el('strong', label + ': '), el('span', cashValue(value)));
    return paragraph;
  }
  function cashDisputeSessionValid(epoch, sessionToken) {
    return epoch === cashDisputeSessionEpoch && sessionToken === token;
  }
  function cashDisputeCard(item) {
    const receiptId = Number(item.receipt_id);
    const node = card(Number.isSafeInteger(receiptId) ? 'Receipt #' + receiptId : 'Cash receipt', 'Trip #' + cashValue(item.trip_id) + ' · Booking #' + cashValue(item.booking_id));
    const facts = el('div', null, 'cash-facts');
    facts.append(cashFact('Rider', 'User #' + cashValue(item.rider_id)), cashFact('Driver', 'User #' + cashValue(item.driver_id)), cashFact('Gross amount', cashMinorINR(item.gross_amount)), cashFact('Commission snapshot', cashMinorINR(item.commission_amount)), cashFact('Rider acknowledged', item.rider_acknowledged === true ? 'Yes' : 'No'), cashFact('Driver acknowledged', item.driver_acknowledged === true ? 'Yes' : 'No'), cashFact('Provider attempt', item.has_provider_attempt === true ? 'Yes' : 'No'), cashFact('Disputed at', cashValue(item.disputed_at, 'No dispute timestamp')), cashFact('Reconciliation', item.reconciliation_state || 'Unavailable', 'cash-fact cash-state'), cashFact('Cash state', item.cash_state || 'Unavailable', 'cash-fact cash-state'), cashFact('Booking state', item.booking_state), cashFact('Trip state', item.trip_state), cashFact('Commission', item.cash_commission_status || 'Unavailable'), cashFact('Settlement', item.settlement_status || 'Unavailable'));
    node.append(facts);
    return node;
  }
  function renderCashDisputes(entries) {
    const target = $('cash-disputes-queue');
    target.replaceChildren();
    if (!entries.length) { empty(target, 'No disputed cash receipts or channel conflicts were found.'); return; }
    for (const item of entries) target.append(cashDisputeCard(item));
  }
  async function loadCashDisputes(append = false) {
    if (cashDisputeLoading) return;
    if (!(identity?.is_admin === true || identity?.is_admin === 1) || !token) return;
    const epoch = cashDisputeSessionEpoch;
    const sessionToken = token;
    cashDisputeLoading = true;
    const status = $('cash-disputes-status');
    const target = $('cash-disputes-queue');
    const nextButton = $('more-cash-disputes');
    if (!append) {
      cashDisputeCursor = null;
      target.replaceChildren(el('div', 'Loading cash review queue…', 'empty'));
      nextButton.hidden = true;
    } else {
      status.textContent = 'Loading next cash page…';
      status.classList.remove('error');
    }
    try {
      const query = 'page_size=50' + (append && cashDisputeCursor !== null ? '&after=' + encodeURIComponent(String(cashDisputeCursor)) : '');
      const response = await api('mobility/operator/cash-disputes?' + query);
      if (!cashDisputeSessionValid(epoch, sessionToken)) return;
      const entries = list(response);
      if (!append) renderCashDisputes(entries);
      else for (const item of entries) target.append(cashDisputeCard(item));
      const pagination = response?.pagination || response?.data?.pagination || {};
      const next = Number(pagination.next_after);
      cashDisputeCursor = pagination.has_more === true && Number.isSafeInteger(next) && next >= 0 ? next : null;
      nextButton.hidden = cashDisputeCursor === null;
      status.textContent = entries.length ? (append ? 'Next cash page loaded.' : 'Cash review queue loaded.') : (append ? 'No more cash records.' : 'Cash review queue is empty.');
      status.classList.remove('error');
    } catch (error) {
      if (!cashDisputeSessionValid(epoch, sessionToken)) return;
      if (!append) empty(target, 'Cash review queue could not be loaded.');
      status.textContent = error.message || 'Cash review queue could not be loaded.';
      status.classList.add('error');
      nextButton.hidden = true;
    } finally {
      if (cashDisputeSessionValid(epoch, sessionToken)) cashDisputeLoading = false;
    }
  }
  async function loadOperator() {
    if (!(identity?.is_admin === true || identity?.is_admin === 1)) return;
    empty($('membership-queue'), 'Loading applications…'); empty($('host-queue'), 'Loading host applications…');
    const response = await api('mobility/operator/memberships?page_size=50');
    const entries = list(response); $('membership-queue').replaceChildren();
    if (!entries.length) empty($('membership-queue'), 'No membership applications.');
    for (const item of entries) {
      const node = card(String(item.name || item.email || 'Applicant #' + item.user_id), String(item.college_code || '') + ' · ' + String(item.affiliation || '') + ' · ' + String(item.status || 'pending'));
      addReviewControls(node, item, 'memberships', item.user_id);
      $('membership-queue').append(node);
    }
    try { const hosts = await api('mobility/operator/host-applications?page_size=50'); renderHostQueue(list(hosts)); } catch (error) { empty($('host-queue'), 'Host review queue is not available in this server build.'); }
    try { await loadCashDisputes(); } catch (error) { $('cash-disputes-status').textContent = error.message || 'Cash review queue is unavailable.'; $('cash-disputes-status').classList.add('error'); }
    try { await loadSupportTickets(); } catch (error) { $('support-tickets-status').textContent = error.message || 'Trip report queue is unavailable.'; $('support-tickets-status').classList.add('error'); }
  }
  async function loadSupportTickets() {
    if (!token || !(identity?.is_admin === true || identity?.is_admin === 1)) return;
    const epoch = supportTicketSessionEpoch, sessionToken = token; const target = $('support-tickets-queue'); const status = $('support-tickets-status');
    target.replaceChildren(el('div', 'Loading report queue…', 'empty')); status.textContent = ''; status.classList.remove('error');
    const response = await api('admin/support/tickets?type=report&per_page=20&page=' + supportTicketPage);
    if (epoch !== supportTicketSessionEpoch || sessionToken !== token) return;
    const entries = list(response).filter((ticket) => String(ticket?.type || 'report') === 'report'); target.replaceChildren();
    const pagination = response?.meta?.pagination || response?.data?.meta?.pagination || {};
    supportTicketLastPage = Number.isSafeInteger(Number(pagination.total_pages)) ? Number(pagination.total_pages) : supportTicketPage;
    if (!entries.length) { empty(target, 'No trip reports in the queue.'); const more = document.getElementById('more-support-tickets'); if (more) more.hidden = true; return; }
    for (const ticket of entries) {
      const node = card(String(ticket?.subject || 'Trip report'), reportTicketSummary(ticket));
      addButton(actions(node), 'Open case', () => void loadAdminTicket(Number(ticket?.id ?? ticket?.ticket_id)));
      target.append(node);
    }
    status.textContent = entries.length + ' report' + (entries.length === 1 ? '' : 's') + ' loaded.';
    let more = document.getElementById('more-support-tickets'); if (!more) { more = el('button', 'Next report page', 'secondary'); more.type = 'button'; more.id = 'more-support-tickets'; target.parentElement.insertBefore(more, $('support-ticket-detail')); }
    more.hidden = supportTicketPage >= supportTicketLastPage; more.textContent = supportTicketPage < supportTicketLastPage ? 'Next report page' : 'No more report pages';
    more.onclick = () => { if (supportTicketPage >= supportTicketLastPage || epoch !== supportTicketSessionEpoch || sessionToken !== token) return; supportTicketPage++; void task(loadSupportTickets); };
  }
  async function loadAdminTicket(id) {
    if (!Number.isSafeInteger(id) || id < 1) return;
    const epoch = supportTicketSessionEpoch, sessionToken = token; const target = $('support-ticket-detail'); hidden(target, false); target.replaceChildren(el('p', 'Loading case…', 'empty'));
    try {
      const response = await api('admin/support/tickets/' + encodeURIComponent(String(id))); if (epoch !== supportTicketSessionEpoch || sessionToken !== token) return;
      const ticket = response?.data ?? response; target.replaceChildren(el('h3', String(ticket?.subject || 'Trip report')), el('p', reportTicketSummary(ticket), 'subtle'));
      if (ticket?.internal_note_markdown) target.append(el('p', 'Private note: ' + String(ticket.internal_note_markdown), 'note'));
      const thread = el('div', null, 'ticket-messages'); renderTicketMessages(thread, Array.isArray(ticket?.replies) ? ticket.replies : []); target.append(thread);
      const controls = actions(target); const mutate = (path, body = {}) => async () => { if (epoch !== supportTicketSessionEpoch || sessionToken !== token) return; await api('admin/support/tickets/' + id + path, 'POST', body); if (epoch !== supportTicketSessionEpoch || sessionToken !== token) return; await loadAdminTicket(id); await loadSupportTickets(); }; addButton(controls, 'Assign to me', mutate('/assign-me'));
      if (String(ticket?.status || '') !== 'Necesita revisión') addButton(controls, 'Mark needs review', mutate('/needs-review'));
      addButton(controls, 'Resolve', mutate('/resolve'));
      const form = document.createElement('form'); form.className = 'form-grid ticket-reply-form'; const label = document.createElement('label'); label.className = 'wide'; label.append('Operator reply'); const textarea = document.createElement('textarea'); textarea.name = 'message'; textarea.maxLength = 4000; textarea.required = true; textarea.rows = 3; label.append(textarea); const button = el('button', 'Reply', 'secondary'); button.type = 'submit'; form.append(label, button); target.append(form);
      form.addEventListener('submit', (event) => { event.preventDefault(); void task(async () => { if (epoch !== supportTicketSessionEpoch || sessionToken !== token) return; const value = textarea.value.trim(); if (!value) throw new Error('Enter a reply.'); await api('admin/support/tickets/' + id + '/replies', 'POST', { message_markdown: value }); if (epoch !== supportTicketSessionEpoch || sessionToken !== token) return; await loadAdminTicket(id); await loadSupportTickets(); }); });
    } catch (error) { if (epoch === supportTicketSessionEpoch && sessionToken === token) target.replaceChildren(el('p', error.message || 'Case unavailable. Permission or server error.', 'status error')); }
  }
  function renderHostQueue(entries) {
    $('host-queue').replaceChildren();
    if (!entries.length) { empty($('host-queue'), 'No host applications.'); return; }
    for (const item of entries) {
      const node = card('Application #' + String(item.id), 'User #' + String(item.user_id) + ' · Vehicle #' + String(item.car_id) + ' · ' + String(item.vehicle_type) + ' · ' + String(item.status));
      addReviewControls(node, item, 'host-applications', item.id);
      $('host-queue').append(node);
    }
  }
  function addReviewControls(node, item, kind, id) {
    const statuses = decisionStatuses(item.status);
    if (!statuses.length) return;
    const label = el('label', 'Review reason (required)');
    const reason = document.createElement('textarea');
    reason.required = true; reason.maxLength = 500; reason.rows = 2;
    label.append(reason); node.append(label);
    const row = actions(node);
    for (const status of statuses) addButton(row, status[0].toUpperCase() + status.slice(1), async () => {
      const explanation = reason.value.trim();
      if (!explanation || explanation.length > 500) { reason.focus(); throw new Error('Enter a reason between 1 and 500 characters.'); }
      await api('mobility/operator/' + kind + '/' + encodeURIComponent(String(id)) + '/decision', 'POST', { expected_version: Number(item.version), status, reason: explanation });
      notice('Review decision recorded.'); await loadOperator();
    }, status === 'approved');
  }
  function decisionStatuses(current) {
    const status = String(current || 'pending').toLowerCase();
    if (status === 'pending') return ['approved', 'rejected'];
    if (status === 'approved') return ['suspended'];
    if (status === 'suspended') return ['approved'];
    return [];
  }
  $('refresh-operator').addEventListener('click', () => void task(loadOperator));
  $('refresh-support-tickets').addEventListener('click', () => void task(async () => { supportTicketPage = 1; await loadSupportTickets(); }));
  $('refresh-cash-disputes').addEventListener('click', () => void task(() => loadCashDisputes()));
  $('more-cash-disputes').addEventListener('click', () => void task(() => loadCashDisputes(true)));

  async function loadDemoRoutes() {
    const select = $('demo-route');
    select.replaceChildren(el('option', 'Loading routes…'));
    const response = await api('mobility/pilot/routes');
    if (response.synthetic_only !== true || !Array.isArray(response.data)) {
      throw new Error('Expected test-only route catalog.');
    }
    select.replaceChildren();
    for (const route of response.data) {
      const option = el('option', String(route.from) + ' → ' + String(route.to));
      option.value = String(route.id);
      select.append(option);
    }
    if (!select.options.length) throw new Error('No synthetic demo routes are configured.');
  }
  function invalidateQuote(message = 'Select pickup and drop-off to get a quote. A quote does not reserve a seat.') {
    routeQuote = null; publicationAttempt = null;
    $('publish-quoted-offer').disabled = true;
    $('route-quote-summary').textContent = message;
  }
  function quoteMode() { return $('quote-car').selectedOptions[0]?.dataset.mode || ''; }
  function configureQuotedOffers(response) {
    invalidateQuote();
    const select = $('quote-car'); select.replaceChildren();
    const approvals = response?.host_applications ?? response?.data?.host_applications ?? [];
    for (const approval of approvals) {
      if (!capabilities.publish || approval.status !== 'approved' || !['car', 'bike'].includes(approval.vehicle_type)) continue;
      const option = el('option', (approval.vehicle_type === 'bike' ? 'Motorbike' : 'Car') + ' #' + String(approval.car_id));
      option.value = String(approval.car_id); option.dataset.mode = approval.vehicle_type; select.append(option);
    }
    if (!select.options.length) { const option = el('option', 'Approved vehicle required'); option.value = ''; select.append(option); }
    void refreshPickupPoints().catch((error) => { $('quote-catalog-status').textContent = error.message; });
  }
  async function refreshPickupPoints(append = false) {
    const mode = quoteMode();
    if (!append) {
      pickupCursor = null;
      for (const select of [$('quote-pickup'), ...viaSelects(), $('quote-dropoff')]) { const option = el('option', 'Choose a point'); option.value = ''; select.replaceChildren(option); }
    }
    $('quote-more-points').hidden = true;
    $('quote-seats').max = mode === 'bike' ? '1' : '5';
    if (mode === 'bike') $('quote-seats').value = '1';
    if (!mode) { $('quote-catalog-status').textContent = 'An approved vehicle is required to offer a ride.'; return; }
    const response = await api('mobility/pickup-points?vehicle_type=' + encodeURIComponent(mode) + '&page_size=50' + (append && pickupCursor ? '&after=' + pickupCursor : ''));
    for (const point of list(response)) {
      if (!Number.isSafeInteger(Number(point.id)) || Number(point.id) <= 0) continue;
      for (const select of [$('quote-pickup'), ...viaSelects(), $('quote-dropoff')]) { const option = el('option', String(point.name)); option.value = String(point.id); select.append(option); }
    }
    const next = Number(response.next_cursor ?? response.meta?.next_cursor);
    pickupCursor = Number.isSafeInteger(next) && next > 0 ? next : null;
    $('quote-more-points').hidden = !pickupCursor;
    $('quote-catalog-status').textContent = $('quote-pickup').options.length > 1 ? 'Choose from the configured pickup catalog.' : 'No pickup points are configured for this vehicle. An operator must prepare the catalog before route quotes are available.';
  }
  $('quote-car').addEventListener('change', () => { invalidateQuote(); void task(() => refreshPickupPoints()); });
  for (const id of ['quote-pickup', 'quote-dropoff']) $(id).addEventListener('change', () => invalidateQuote());
  $('quote-more-points').addEventListener('click', () => void task(() => refreshPickupPoints(true)));
  $('add-quote-via').addEventListener('click', () => {
    if (viaSelects().length >= 6) { notice('A route can have up to six stops between pickup and drop-off.', true); return; }
    const row = el('div'); row.className = 'program-actions'; const label = el('label', 'Stop on the way');
    const select = el('select'); select.required = true;
    for (const option of $('quote-pickup').options) { const copy = el('option', option.textContent); copy.value = option.value; select.append(copy); }
    select.addEventListener('change', () => invalidateQuote()); label.append(select); row.append(label);
    const remove = el('button', 'Remove stop', 'secondary'); remove.type = 'button'; remove.addEventListener('click', () => { row.remove(); invalidateQuote(); }); row.append(remove);
    $('quote-vias').append(row); invalidateQuote();
  });
  $('request-route-quote').addEventListener('click', () => void task(async () => {
    invalidateQuote('Getting route quote…');
    const pickup = Number($('quote-pickup').value); const dropoff = Number($('quote-dropoff').value);
    if (!pickup || !dropoff || pickup === dropoff) throw new Error('Choose different pickup and drop-off points.');
    const stops = [pickup, ...viaSelects().map((select) => Number(select.value)), dropoff];
    if (stops.some((id) => !id) || new Set(stops).size !== stops.length) throw new Error('Choose a different point for every stop.');
    const input = stops.length > 2 ? { stop_ids: stops, vehicle_type: quoteMode() } : { pickup_point_id: pickup, dropoff_point_id: dropoff, vehicle_type: quoteMode() };
    const response = await api('mobility/routes/quote', 'POST', input);
    const quote = response?.data;
    if (!quote || typeof quote.id !== 'string' || quote.currency !== 'INR' || !Number.isSafeInteger(quote.seat_price_minor) || quote.seat_price_minor < 0 || !Number.isFinite(Date.parse(quote.expires_at))) throw new Error('The server returned an invalid quote.');
    routeQuote = quote;
    const expiry = new Date(quote.expires_at).toLocaleTimeString('en-IN', { timeZone: 'Asia/Kolkata' });
    const price = new Intl.NumberFormat('en-IN', { style: 'currency', currency: 'INR' }).format(quote.seat_price_minor / 100);
    $('route-quote-summary').textContent = (quote.synthetic_only ? 'SYNTHETIC TEST QUOTE · ' : '') + (Number(quote.distance_meters) / 1000).toFixed(1) + ' km · ' + Math.ceil(Number(quote.duration_seconds) / 60) + ' min · ' + price + ' per passenger · Expires ' + expiry + ' IST. No money collected.';
    $('publish-quoted-offer').disabled = false;
  }));
  $('quote-offer-form').addEventListener('submit', (event) => {
    event.preventDefault();
    void task(async () => {
      if (!capabilities.publish || !routeQuote) throw new Error('Get a route quote using an approved vehicle first.');
      const values = Object.fromEntries(new FormData($('quote-offer-form')));
      let data = { quote_id: routeQuote.id, car_id: Number(values.car_id), trip_date: String(values.trip_date), total_seats: Number(values.total_seats), description: String(values.description || '') };
      const recurring = $('repeat-weekly').checked;
      if (recurring) {
        const form = new FormData($('quote-offer-form'));
        const mask = form.getAll('weekday').reduce((sum, bit) => sum | Number(bit), 0);
        if (!mask || !values.repeat_until) throw new Error('Choose commute weekdays and an end date.');
        data = { quote_id: routeQuote.id, car_id: Number(values.car_id), weekly_schedule: mask,
          departure_time: String(values.trip_date).slice(11, 16), date_from: String(values.trip_date).slice(0, 10),
          date_to: String(values.repeat_until), total_seats: Number(values.total_seats), description: String(values.description || '') };
      }
      const signature = JSON.stringify({ recurring, data });
      const sameAttempt = publicationAttempt?.signature === signature;
      if (!sameAttempt && Date.parse(routeQuote.expires_at) <= Date.now()) { invalidateQuote('This quote expired. Get a fresh route quote.'); throw new Error('This quote expired. Get a fresh route quote.'); }
      const departure = new Date(String(values.trip_date) + ':00+05:30');
      if (!Number.isFinite(departure.getTime()) || departure.getTime() <= Date.now() + 15 * 60 * 1000) throw new Error('Choose a departure more than 15 minutes from now in Asia/Kolkata.');
      if (!sameAttempt) publicationAttempt = { signature, key: crypto.randomUUID() };
      const response = await api(recurring ? 'mobility/recurring-programs' : 'mobility/pool-offers', 'POST', data, true, { 'Idempotency-Key': publicationAttempt.key });
      if (recurring) {
        if (typeof response?.data?.program?.id !== 'string') throw new Error('Unexpected plan response. Refresh Repeating rides before trying again.');
        invalidateQuote('Repeating plan saved. Get a new quote for another ride.');
        $('quote-offer-form').reset(); $('quote-vias').replaceChildren(); setRepeatOptions(); showView('programs');
        await loadPrograms(); await showProgram(response.data.program.id);
        notice('Repeating plan saved. Each published date has its own seats and riders.'); return;
      }
      if (!tripId(response.data)) throw new Error('Unexpected publish response. Check My rides before trying again.');
      const synthetic = routeQuote.synthetic_only;
      invalidateQuote('Ride published. Get a new quote to offer another ride.');
      $('quote-offer-form').reset(); $('quote-vias').replaceChildren(); setRepeatOptions(); showView('mine'); await loadMine();
      notice((synthetic ? 'Synthetic test ride #' : 'Ride #') + tripId(response.data) + (response.data.existing ? ' recovered from your earlier request.' : ' published. Riders can request a seat.'));
    });
  });
  function setRepeatOptions() {
    const repeat = $('repeat-weekly').checked;
    hidden($('repeat-options'), !repeat); $('repeat-until').required = repeat;
    $('publish-quoted-offer').textContent = repeat ? 'Create repeating plan' : 'Publish quoted ride';
  }
  $('repeat-weekly').addEventListener('change', setRepeatOptions);
  function weekdayNames(mask) { return ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'].filter((_, index) => Number(mask) & (1 << index)).join(', '); }
  function money(minor) { return new Intl.NumberFormat('en-IN', { style: 'currency', currency: 'INR' }).format(Number(minor) / 100); }
  function inputField(form, text, name, value, type = 'text') {
    const label = el('label', text); const input = el('input'); input.name = name; input.type = type; input.value = String(value ?? ''); label.append(input); form.append(label); return input;
  }
  async function programAction(id, action, body) {
    const path = 'mobility/recurring-programs/' + encodeURIComponent(id) + '/' + action;
    const signature = JSON.stringify({ path, body });
    if (!programAttempts.has(signature)) {
      if (programAttempts.size >= 100) programAttempts.delete(programAttempts.keys().next().value);
      programAttempts.set(signature, crypto.randomUUID());
    }
    return api(path, 'POST', body, true, { 'Idempotency-Key': programAttempts.get(signature) });
  }
  async function loadPrograms(append = false) {
    if (!append) { programCursor = null; empty($('program-list'), 'Loading repeating plans…'); }
    const response = await api('mobility/recurring-programs?page_size=20' + (append && programCursor ? '&after=' + encodeURIComponent(programCursor) : ''));
    if (!append) $('program-list').replaceChildren();
    const programs = list(response);
    if (!programs.length && !append) empty($('program-list'), 'No repeating plans yet. Choose Repeat weekly when offering a quoted ride.');
    for (const program of programs) {
      const node = card(String(program.from_name || 'Pickup') + ' → ' + String(program.to_name || 'Drop-off'), weekdayNames(program.weekly_schedule) + ' at ' + String(program.departure_time) + ' IST · ' + String(program.state) + (program.synthetic_only ? ' · SYNTHETIC TEST PLAN' : ''));
      node.append(el('p', String(program.date_from) + ' to ' + String(program.date_to) + ' · Up to ' + money(program.max_fare_minor) + ' per passenger'));
      addButton(actions(node), 'Manage plan', () => showProgram(program.id)); $('program-list').append(node);
    }
    programCursor = typeof response.next_cursor === 'string' && response.next_cursor ? response.next_cursor : null;
    $('more-programs').hidden = !programCursor;
  }
  async function showProgram(id, afterDate = null) {
    const response = await api('mobility/recurring-programs/' + encodeURIComponent(id) + '?page_size=30' + (afterDate ? '&after_date=' + encodeURIComponent(afterDate) : ''));
    const data = response?.data; const program = data?.program;
    if (!program || typeof program.id !== 'string') throw new Error('The plan could not be loaded.');
    selectedProgram = program.id;
    const area = $('program-detail'); area.replaceChildren(); hidden(area, false);
    area.append(el('h3', String(program.from_name) + ' → ' + String(program.to_name)), el('p', weekdayNames(program.weekly_schedule) + ' · ' + String(program.departure_time) + ' IST · ' + String(program.state)));
    area.append(el('p', 'Published through ' + String(program.generated_through || 'No dates yet') + '. Published rides keep their current time, seats and fare.', 'subtle'));
    if (program.state === 'paused') area.append(el('p', 'Paused: new requests and acceptances are stopped. Already accepted rides remain scheduled.', 'note'));
    if (program.last_generation_code) area.append(el('p', program.last_generation_code === 'PROGRAM_REQUOTE_REQUIRED' ? 'Future dates need a fresh route price review.' : 'Future date generation needs attention. Your published rides are unchanged.', 'note'));
    const controls = el('div', null, 'program-actions'); area.append(controls);
    const stateForm = el('form'); const stateAction = program.state === 'paused' ? 'resume' : 'pause';
    const stateReason = inputField(stateForm, 'Reason to ' + stateAction, 'reason', ''); stateReason.required = true; stateReason.maxLength = 500;
    stateForm.append(el('button', stateAction === 'pause' ? 'Pause new requests' : 'Resume plan', 'secondary'));
    stateForm.addEventListener('submit', (event) => { event.preventDefault(); void task(async () => { await programAction(id, 'control', { expected_version: program.version, action: stateAction, reason: stateReason.value }); await loadPrograms(); await showProgram(id); notice('Plan ' + (stateAction === 'pause' ? 'paused. Accepted rides remain scheduled.' : 'resumed.')); }); }); controls.append(stateForm);
    const generation = el('form'); const until = inputField(generation, 'Publish dates through', 'until', indiaDate(new Date(Date.now() + 14 * 86400000)), 'date'); until.required = true; until.max = indiaDate(new Date(Date.now() + 28 * 86400000));
    generation.append(el('button', 'Publish future dates', 'secondary'));
    generation.addEventListener('submit', (event) => { event.preventDefault(); void task(async () => { await programAction(id, 'generate', { expected_version: program.version, until: until.value }); await loadPrograms(); await showProgram(id); notice('Future date generation checked. Existing rides were kept.'); }); }); controls.append(generation);
    const skipForm = el('form'); const skipDate = inputField(skipForm, 'Cancel one future date', 'service_date', '', 'date'); skipDate.required = true; skipDate.min = indiaDate(); skipDate.max = String(program.date_to);
    const skipReason = inputField(skipForm, 'Cancellation reason', 'reason', ''); skipReason.required = true; skipReason.maxLength = 500;
    skipForm.append(el('p', 'Cancels this date for all riders. A collected payment may need operator refund review.', 'subtle'), el('button', 'Cancel this date', 'secondary'));
    skipForm.addEventListener('submit', (event) => { event.preventDefault(); void task(async () => { const result = await programAction(id, 'exceptions', { expected_version: program.version, service_date: skipDate.value, action: 'skip', reason: skipReason.value }); await loadPrograms(); await showProgram(id); notice(result?.data?.financial_recovery_required ? 'Date cancelled. Payment recovery needs operator review; no refund is confirmed.' : 'Date cancelled. Other departures are unchanged.'); }); }); controls.append(skipForm);
    const edit = el('form'); edit.className = 'program-actions'; edit.append(el('h4', 'Change unpublished dates'));
    const effective = inputField(edit, 'Changes begin', 'effective_from', program.next_edit_from, 'date'); effective.readOnly = true;
    const time = inputField(edit, 'Future departure (IST)', 'departure_time', program.departure_time, 'time'); time.required = true;
    const seats = inputField(edit, 'Passenger seats', 'total_seats', program.total_seats, 'number'); seats.min = '1'; seats.max = program.vehicle_type === 'bike' ? '1' : '5'; seats.required = true;
    const endDate = inputField(edit, 'Repeat until', 'date_to', program.date_to, 'date'); endDate.required = true;
    const days = el('div', null, 'weekday-options wide'); const checkboxes = [];
    ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'].forEach((name, index) => { const label = el('label', name); const box = el('input'); box.type = 'checkbox'; box.value = String(1 << index); box.checked = Boolean(Number(program.weekly_schedule) & (1 << index)); label.prepend(box); days.append(label); checkboxes.push(box); }); edit.append(days);
    const priceSummary = el('p', 'Current future maximum: ' + money(program.max_fare_minor) + ' per passenger.', 'wide subtle'); edit.append(priceSummary);
    const acceptLabel = el('label', 'Accept the new maximum for future dates'); acceptLabel.className = 'wide repeat-toggle'; const acceptPrice = el('input'); acceptPrice.type = 'checkbox'; acceptPrice.disabled = true; acceptLabel.prepend(acceptPrice); edit.append(acceptLabel);
    const refreshPrice = el('button', 'Review updated route price', 'secondary'); refreshPrice.type = 'button'; edit.append(refreshPrice);
    refreshPrice.addEventListener('click', () => void task(async () => {
      if (!program.pickup_point_id || !program.dropoff_point_id) throw new Error('This plan has no usable pickup catalog reference.');
      const quoteInput = Array.isArray(program.stop_ids) && program.stop_ids.length > 2 ? { stop_ids: program.stop_ids, vehicle_type: program.vehicle_type } : { pickup_point_id: program.pickup_point_id, dropoff_point_id: program.dropoff_point_id, vehicle_type: program.vehicle_type };
      const result = await api('mobility/routes/quote', 'POST', quoteInput);
      if (result?.data?.currency !== 'INR' || !Number.isSafeInteger(result.data.seat_price_minor)) throw new Error('Invalid route price response.');
      programPrices.set(id, result.data); acceptPrice.disabled = false; acceptPrice.checked = false;
      priceSummary.textContent = (result.data.synthetic_only ? 'SYNTHETIC · ' : '') + 'New future maximum: ' + money(result.data.seat_price_minor) + ' per passenger. Expires in five minutes. Published fares are unchanged.';
    }));
    const reason = inputField(edit, 'Reason for change', 'reason', ''); reason.required = true; reason.maxLength = 500;
    edit.append(el('p', 'Changes start after already published dates. Cancel and replace an individual published ride if its time must change.', 'wide subtle'), el('button', 'Save future changes', 'secondary'));
    edit.addEventListener('submit', (event) => { event.preventDefault(); void task(async () => {
      const mask = checkboxes.reduce((sum, box) => box.checked ? sum | Number(box.value) : sum, 0); if (!mask) throw new Error('Choose at least one weekday.');
      const body = { expected_version: program.version, effective_from: effective.value, weekly_schedule: mask, departure_time: time.value, total_seats: Number(seats.value), date_to: endDate.value, reason: reason.value };
      if (acceptPrice.checked) { const quote = programPrices.get(id); if (!quote || Date.parse(quote.expires_at) <= Date.now()) throw new Error('Get and review a fresh route price.'); body.quote_id = quote.id; }
      await programAction(id, 'edit', body); programPrices.delete(id); await loadPrograms(); await showProgram(id); notice('Future plan settings saved. Published rides were kept.');
    }); }); area.append(edit);
    area.append(el('h4', 'Published departures'));
    if (!Array.isArray(data.occurrences) || !data.occurrences.length) area.append(el('p', 'No published dates in this page.'));
    for (const occurrence of data.occurrences || []) area.append(el('p', String(occurrence.service_date) + ' · ' + String(occurrence.departure_local) + ' · ' + String(occurrence.lifecycle_state) + ' · ' + String(occurrence.seats_available) + '/' + String(occurrence.total_seats) + ' seats · ' + money(occurrence.seat_price_minor) + ' · Ride #' + String(occurrence.trip_id)));
    if (data.next_cursor) addButton(actions(area), 'Next departure page', () => showProgram(id, data.next_cursor));
    if (Array.isArray(data.exceptions) && data.exceptions.length) area.append(el('p', 'Cancelled dates in this page: ' + data.exceptions.map((item) => String(item.service_date)).join(', '), 'subtle'));
  }
  $('refresh-programs').addEventListener('click', () => void task(async () => { await loadPrograms(); if (selectedProgram) await showProgram(selectedProgram); }));
  $('more-programs').addEventListener('click', () => void task(() => loadPrograms(true)));
  function card(title, subtitle) {
    const node = el('article', null, 'card');
    node.append(el('strong', title), el('p', subtitle));
    return node;
  }
  function addButton(parent, text, action, primary = false) {
    const button = el('button', text, primary ? 'primary' : 'secondary');
    button.type = 'button';
    button.addEventListener('click', () => void task(action));
    parent.append(button);
    return button;
  }
  function actions(node) {
    const row = el('div', null, 'actions');
    node.append(row);
    return row;
  }
  function routeName(trip) {
    const from = trip.from_town || trip.punto_partida || 'Pickup';
    const to = trip.to_town || trip.punto_llegada || 'Dropoff';
    return String(from) + ' → ' + String(to);
  }
  function rideDetails(trip) {
    const when = String(trip.trip_date || trip.date || 'Date not supplied');
    const seats = trip.seats_available ?? trip.total_seats ?? '?';
    return when + ' · Seats listed: ' + seats + ' · Trip #' + tripId(trip);
  }
  function indiaDate(date = new Date()) {
    const parts = new Intl.DateTimeFormat('en-GB', { timeZone: 'Asia/Kolkata', year: 'numeric', month: '2-digit', day: '2-digit' }).formatToParts(date);
    const value = (type) => parts.find((part) => part.type === type).value;
    return value('year') + '-' + value('month') + '-' + value('day');
  }
  async function loadSearchPoints(append = false) {
    if (!append) {
      searchPointCursor = null;
      for (const [id, text] of [['search-pickup', 'Any pickup'], ['search-dropoff', 'Any drop-off']]) { const option = el('option', text); option.value = ''; $(id).replaceChildren(option); }
    }
    const response = await api('mobility/pickup-points?page_size=50' + (append && searchPointCursor ? '&after=' + searchPointCursor : ''));
    for (const point of list(response)) {
      for (const id of ['search-pickup', 'search-dropoff']) { const option = el('option', String(point.name)); option.value = String(point.id); $(id).append(option); }
    }
    const next = Number(response.next_cursor);
    searchPointCursor = Number.isSafeInteger(next) && next > 0 ? next : null;
    $('search-more-points').hidden = !searchPointCursor;
  }
  async function loadTrips(append = false) {
    const params = new URLSearchParams();
    if (!append) {
      discoveryCursor = null;
      const values = Object.fromEntries(new FormData($('search-form')));
      params.set('service_date', String(values.date || indiaDate())); params.set('page_size', '20');
      for (const key of ['from_time', 'to_time', 'vehicle_type', 'pickup_point_id', 'dropoff_point_id']) if (values[key]) params.set(key, String(values[key]));
      discoveryQuery = params.toString();
      empty($('trip-results'), 'Loading available rides…');
    }
    $('more-trips').hidden = true;
    const selected = new URLSearchParams(discoveryQuery);
    if (selected.get('pickup_point_id') && selected.get('dropoff_point_id')) return loadMatches(selected);
    const json = await api('mobility/pool-offers?' + discoveryQuery + (append && discoveryCursor ? '&after=' + discoveryCursor : ''));
    const results = list(json);
    if (!append) $('trip-results').replaceChildren();
    const next = Number(json.next_cursor); discoveryCursor = Number.isSafeInteger(next) && next > 0 ? next : null;
    $('more-trips').hidden = !discoveryCursor;
    if (!results.length && !append) empty($('trip-results'), discoveryCursor ? 'No matching rides in this batch. Load more to continue.' : 'No matching rides for this date and pickup selection.');
    for (const trip of results) {
      const price = new Intl.NumberFormat('en-IN', { style: 'currency', currency: 'INR' }).format(Number(trip.seat_price_minor) / 100);
      const node = card(routeName(trip), rideDetails(trip) + ' · ' + (trip.vehicle_type === 'bike' ? 'Motorbike' : 'Car') + ' · ' + price + ' per passenger' + (trip.synthetic_only ? ' · SYNTHETIC TEST RIDE' : ''));
      const row = actions(node);
      const request = addButton(row, Number(trip.seats_available) > 0 ? 'Request a seat' : 'No seats available', async () => {
        if (!capabilities.request) throw new Error('Membership approval is required before requesting a seat.');
        await api('trips/' + tripId(trip) + '/requests', 'POST', {});
        notice('Seat requested. The host must still accept it.');
        await loadMine();
      }, true);
      request.disabled = Number(trip.seats_available) <= 0;
      $('trip-results').append(node);
    }
  }
  async function loadMatches(params) {
    const query = Object.fromEntries(params); query.pickup_point_id = Number(query.pickup_point_id); query.dropoff_point_id = Number(query.dropoff_point_id); query.page_size = 20;
    const response = await api('mobility/pool-matches', 'POST', query); $('trip-results').replaceChildren(); $('more-trips').hidden = true;
    const pickup = $('search-pickup').selectedOptions[0]?.textContent || 'Pickup'; const dropoff = $('search-dropoff').selectedOptions[0]?.textContent || 'Drop-off';
    if (!list(response).length) empty($('trip-results'), 'No matching seats in this date and pickup window. Try a different time or pickup.');
    for (const match of list(response)) {
      const pickupTime = new Date(match.pickup_local).toLocaleString('en-IN', { timeZone: 'Asia/Kolkata' });
      const dropoffTime = new Date(match.dropoff_local).toLocaleTimeString('en-IN', { timeZone: 'Asia/Kolkata' });
      const node = card(pickup + ' → ' + dropoff, pickupTime + ' → ' + dropoffTime + ' IST · ' + money(match.seat_price_minor) + ' per passenger · ' + match.seats_available + ' seats' + (match.synthetic_only ? ' · SYNTHETIC TEST RIDE' : ''));
      addButton(actions(node), 'Request this part of the ride', async () => {
        if (!capabilities.request) throw new Error('Membership approval is required.');
        if (!matchAttempts.has(match.match_id)) {
          if (Date.parse(match.expires_at) <= Date.now()) throw new Error('This match expired. Search again for a fresh seat.');
          matchAttempts.set(match.match_id, crypto.randomUUID());
        }
        const result = await api('mobility/pool-matches/' + encodeURIComponent(match.match_id) + '/requests', 'POST', {}, true, { 'Idempotency-Key': matchAttempts.get(match.match_id) });
        notice(result?.data?.request_state === 1 ? 'Your seat is confirmed.' : 'Seat requested. The host must still accept it.'); await loadMine();
      }, true); $('trip-results').append(node);
    }
  }
  $('more-trips').addEventListener('click', () => void task(() => loadTrips(true)));
  $('search-more-points').addEventListener('click', () => void task(() => loadSearchPoints(true)));
  $('search-form').addEventListener('submit', (event) => { event.preventDefault(); void task(loadTrips); });
  $('refresh-trips').addEventListener('click', () => void task(loadTrips));

  $('offer-form').addEventListener('submit', (event) => {
    event.preventDefault();
    void task(async () => {
      const values = Object.fromEntries(new FormData($('offer-form')));
      if (!capabilities.publish) throw new Error('Approved membership and host approval are required before publishing.');
      const departure = String(values.trip_date || '');
      const departureDate = new Date(departure);
      if (!Number.isFinite(departureDate.getTime()) || departureDate.getTime() <= Date.now()) {
        throw new Error('Choose a future departure time.');
      }
      const data = {
        route_id: String(values.route_id),
        trip_date: departure,
        total_seats: Number(values.total_seats),
        description: String(values.description || '')
      };
      const response = await api('mobility/pilot/offers', 'POST', data);
      if (response.synthetic_only !== true || !tripId(response.data)) throw new Error('Unexpected publish response.');
      $('offer-form').reset();
      showView('mine');
      await loadMine();
      notice('Synthetic trip #' + tripId(response.data) +
        (response.data.existing ? ' already exists.' : ' published. Rider can now request a seat.'));
    });
  });

  async function loadMine() {
    startChatNotifications();
    const mineEpoch = reportSessionEpoch, mineToken = token;
    empty($('hosted-list'), 'Loading…');
    empty($('booking-list'), 'Loading…');
    const [trips, bookings] = await Promise.all([
      api('users/my-trips'), api('users/seat-requests')
    ]);
    if (!reportSessionValid(mineEpoch, mineToken)) return;
    const own = list(trips).filter((trip) => (
      tripId(trip) && Number(trip.user_id ?? trip.user?.id) === Number(identity?.id)
    ));
    $('hosted-list').replaceChildren();
    if (!own.length) empty($('hosted-list'), 'No hosted rides yet.');
    for (const trip of own) {
      const node = card(routeName(trip), rideDetails(trip));
      const row = actions(node);
      addButton(row, 'Review requests', async () => {
        const response = await api('trips/' + tripId(trip) + '/requests');
        const requests = list(response);
        renderPeople(node, 'Pending seat requests', requests, 'accept', tripId(trip));
      });
      addButton(row, 'Lifecycle', async () => {
        const response = await api('mobility/trips/' + tripId(trip) + '/lifecycle');
        const state = String(response.state || 'planned');
        const riders = Array.isArray(response.participants) ? response.participants : [];
        renderLifecycle(node, tripId(trip), state, riders);
      });
      trackingMount(node, trip, 'driver');
      $('hosted-list').append(node);
    }
    const requests = list(bookings);
    renderReportTripOptions(own, requests);
    await loadUserTickets();
    $('booking-list').replaceChildren();
    if (!requests.length) empty($('booking-list'), 'No seat requests yet.');
    const states = {0:'Pending host approval', 1:'Accepted', 2:'Rejected', 3:'Cancelled', 4:'Awaiting payment (disabled)'};
    for (const request of requests) {
      const trip = request.trip ?? {};
      const id = tripId(trip) || Number(request.trip_id);
      const state = Number(request.request_state);
      const node = card(routeName(trip) || 'Trip #' + id,
        'Trip #' + id + ' · ' + (states[state] || 'Unknown request state'));
      // The upstream endpoint enforces rider identity, booking state and
      // lifecycle rules. UI visibility is not authorization.
      if (Number.isSafeInteger(id) && id > 0 && (state === 0 || state === 1)) {
        addButton(actions(node), 'Cancel my request', async () => {
          await api('trips/' + id + '/requests/' + Number(identity.id) + '/cancel', 'POST', {});
          await loadMine();
          notice('Seat request cancelled. If accepted, its capacity has been released.');
        });
      }
      if (Number.isSafeInteger(id) && id > 0 && state === 1) trackingMount(node, trip, 'rider');
      $('booking-list').append(node);
    }
  }
  function renderReportTripOptions(ownedTrips, bookingEntries) {
    const target = $('report-trip-id');
    const ids = new Map();
    for (const trip of ownedTrips) { const id = tripId(trip); if (id) ids.set(id, 'Hosted trip #' + id + ' · ' + routeName(trip)); }
    for (const request of bookingEntries) { const id = tripId(request.trip) || Number(request.trip_id); if (Number.isSafeInteger(id) && id > 0) ids.set(id, 'Joined trip #' + id + ' · ' + routeName(request.trip || {})); }
    const selected = target.value;
    target.replaceChildren(el('option', ids.size ? 'Choose a trip' : 'No owned or joined trips found'));
    target.options[0].value = '';
    for (const [id, label] of ids) { const option = el('option', label); option.value = String(id); target.append(option); }
    if (selected && ids.has(Number(selected))) target.value = selected;
  }
  function reportTicketSummary(ticket) {
    const id = Number(ticket?.id ?? ticket?.ticket_id);
    return 'Case #' + (Number.isSafeInteger(id) ? id : '—') + ' · Trip #' + String(ticket?.trip_id ?? '—') + ' · ' + String(ticket?.status || 'Open');
  }
  function reportTicketNode(ticket, detailTarget) {
    const node = card(String(ticket?.subject || 'Trip report'), reportTicketSummary(ticket));
    addButton(actions(node), 'View case', () => void loadUserTicket(Number(ticket?.id ?? ticket?.ticket_id), detailTarget));
    return node;
  }
  function renderTicketMessages(target, messages) {
    target.replaceChildren();
    for (const message of messages) {
      const item = el('article', null, 'ticket-message');
      item.append(el('strong', message?.is_admin ? 'Support' : 'You'), el('p', String(message?.message_markdown ?? message?.message ?? message?.body ?? '')));
      if (message?.created_at) item.append(el('small', String(message.created_at), 'subtle'));
      target.append(item);
    }
    if (!messages.length) target.append(el('p', 'No replies yet.', 'empty'));
  }
  async function loadUserTickets() {
    const epoch = reportSessionEpoch, sessionToken = token;
    const target = $('trip-report-tickets');
    target.replaceChildren(el('div', 'Loading your reports…', 'empty'));
    try {
      const response = await api('support/tickets');
      if (!reportSessionValid(epoch, sessionToken)) return;
      userTicketEntries = list(response).filter((ticket) => String(ticket?.type || 'report') === 'report'); userTicketVisible = 25;
      renderUserTickets();
    } catch (error) { if (reportSessionValid(epoch, sessionToken)) empty(target, 'Your reports are temporarily unavailable.'); }
  }
  function renderUserTickets() {
    const target = $('trip-report-tickets'); target.replaceChildren();
    if (!userTicketEntries.length) { target.append(el('p', 'No trip reports yet.', 'empty')); return; }
    for (const ticket of userTicketEntries.slice(0, userTicketVisible)) target.append(reportTicketNode(ticket, $('trip-report-detail')));
    if (userTicketVisible < userTicketEntries.length) { const more = el('button', 'Show more reports', 'secondary'); more.type = 'button'; more.addEventListener('click', () => { userTicketVisible += 25; renderUserTickets(); }); target.append(more); }
  }
  async function loadUserTicket(id, detailTarget) {
    if (!Number.isSafeInteger(id) || id < 1) return;
    const epoch = reportSessionEpoch, sessionToken = token;
    detailTarget.replaceChildren(el('p', 'Loading case…', 'empty')); hidden(detailTarget, false);
    try {
      const response = await api('support/tickets/' + encodeURIComponent(String(id)));
      if (!reportSessionValid(epoch, sessionToken)) return;
      const ticket = response?.data ?? response;
      detailTarget.replaceChildren(el('h3', String(ticket?.subject || 'Trip report')), el('p', reportTicketSummary(ticket), 'subtle'));
      const messages = Array.isArray(ticket?.replies) ? ticket.replies : [];
      const thread = el('div', null, 'ticket-messages'); renderTicketMessages(thread, messages); detailTarget.append(thread);
      const form = document.createElement('form'); form.className = 'form-grid ticket-reply-form';
      const label = document.createElement('label'); label.className = 'wide'; label.append('Reply'); const textarea = document.createElement('textarea'); textarea.name = 'message'; textarea.maxLength = 4000; textarea.required = true; textarea.rows = 3; label.append(textarea);
      const button = el('button', 'Send reply', 'secondary'); button.type = 'submit'; form.append(label, button); const status = el('p', '', 'status wide'); form.append(status); detailTarget.append(form);
      form.addEventListener('submit', (event) => { event.preventDefault(); void task(async () => { if (!reportSessionValid(epoch, sessionToken)) return; const value = textarea.value.trim(); if (!value) throw new Error('Enter a reply.'); await api('support/tickets/' + encodeURIComponent(String(id)) + '/replies', 'POST', { message_markdown: value }); if (!reportSessionValid(epoch, sessionToken)) return; status.textContent = 'Reply sent.'; await loadUserTicket(id, detailTarget); await loadUserTickets(); }); });
    } catch (error) { if (reportSessionValid(epoch, sessionToken)) detailTarget.replaceChildren(el('p', error.message || 'Case unavailable.', 'status error')); }
  }
  function resetTripReport() { reportIdempotencyKey = newReportKey(); reportKeyFingerprint = null; $('trip-report-form').reset(); $('trip-report-status').textContent = 'New report ready. Choose a trip and enter the issue.'; $('trip-report-status').classList.remove('error'); }
  $('new-trip-report').addEventListener('click', resetTripReport);
  $('trip-report-form').addEventListener('submit', (event) => { event.preventDefault(); void task(async () => {
    const epoch = reportSessionEpoch, sessionToken = token; const values = Object.fromEntries(new FormData(event.currentTarget)); const id = Number(values.trip_id); const subject = String(values.subject || '').trim(); const message = String(values.message_markdown || '').trim();
    if (!Number.isSafeInteger(id) || id < 1) throw new Error('Choose a trip.'); if (subject.length < 3 || subject.length > 160) throw new Error('Subject must be 3–160 characters.'); if (!message || message.length > 4000) throw new Error('Message must be 1–4000 characters.');
    const fingerprint = id + '\u0000' + subject + '\u0000' + message; if (!reportIdempotencyKey || (reportKeyFingerprint && reportKeyFingerprint !== fingerprint)) reportIdempotencyKey = newReportKey(); reportKeyFingerprint = fingerprint; const status = $('trip-report-status'); status.textContent = 'Submitting report…'; status.classList.remove('error');
    try { if (!reportSessionValid(epoch, sessionToken)) return; const response = await api('mobility/trips/' + encodeURIComponent(String(id)) + '/reports', 'POST', { subject, message_markdown: message }, true, { 'Idempotency-Key': reportIdempotencyKey }); if (!reportSessionValid(epoch, sessionToken)) return; const ticket = response?.data ?? response; status.textContent = 'Report submitted as case #' + String(ticket?.id || '—') + '. You can retry safely if the connection was uncertain.'; await loadUserTickets(); }
    catch (error) { if (reportSessionValid(epoch, sessionToken)) { status.textContent = error.message || 'Report submission failed.'; status.classList.add('error'); } }
  }); });
  $('chat-notifications').addEventListener('click', () => { showView('mine'); void task(loadMine); });
  $('refresh-mine').addEventListener('click', () => void task(loadMine));
  function renderPeople(cardNode, heading, entries, type, id) {
    cardNode.querySelectorAll('.dynamic').forEach((x) => x.remove());
    const section = el('div', null, 'dynamic');
    section.append(el('p', heading));
    const pending = entries.filter((item) => Number(item.state ?? item.request_state) === 0);
    if (!pending.length) section.append(el('p', 'No pending requests.'));
    for (const item of pending) {
      const userId = Number(item.user_id ?? item.user?.id);
      if (!Number.isSafeInteger(userId) || userId <= 0) continue;
      const row = actions(section);
      row.append(el('span', 'Rider #' + userId));
      if (type === 'accept') addButton(row, 'Accept', async () => {
        await api('trips/' + id + '/requests/' + userId + '/accept', 'POST', {});
        notice('Host approval recorded for rider #' + userId + '.');
        await loadMine();
      }, true);
    }
    cardNode.append(section);
  }
  function renderLifecycle(node, id, state, riders) {
    node.querySelectorAll('.dynamic').forEach((x) => x.remove());
    const container = el('div', null, 'dynamic');
    container.append(el('p', 'Lifecycle: ' + state));
    const row = actions(container);
    const send = async (action, passengerId) => {
      const body = { action };
      if (passengerId) body.passenger_id = passengerId;
      await apiTransition(id, body);
      notice('Recorded: ' + action + '. Refresh lifecycle to see current state.');
      await loadMine();
    };
    if (state === 'planned') {
      addButton(row, 'Start trip', () => send('start'), true);
      addButton(row, 'Cancel trip', () => send('cancel'));
    }
    if (state === 'in_progress') {
      for (const participant of riders) {
        const riderId = Number(participant.passenger_id ?? participant.user_id);
        if (!Number.isSafeInteger(riderId)) continue;
        const status = participant.state;
        if (status === 'accepted') addButton(row, 'Board rider #' + riderId, () => send('board', riderId));
        if (status === 'boarded') addButton(row, 'Alight rider #' + riderId, () => send('alight', riderId));
      }
      addButton(row, 'Complete trip', () => send('complete'), true);
    }
    node.append(container);
  }
  async function apiTransition(id, body, stableKey = crypto.randomUUID()) {
    if (!token) throw new Error('Please sign in.');
    const response = await fetch('/api/mobility/trips/' + id + '/transitions', {
      method: 'POST', cache: 'no-store', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json','Accept': 'application/json',
        'Authorization': 'Bearer ' + token, 'Idempotency-Key': stableKey },
      body: JSON.stringify(body)
    });
    const json = await response.json().catch(() => ({}));
    if (!response.ok) throw new Error(errorLabel(json,response.status));
    return json;
  }
  let pickupSessionEpoch = 0;
  let pickupPlan = null;
  let pickupIntent = null;
  let pickupPreview = null;
  const pickupAttemptKeys = new Map();
  function pickupKey(kind, body) { const fingerprint = kind + ':' + JSON.stringify(body); if (!pickupAttemptKeys.has(fingerprint)) pickupAttemptKeys.set(fingerprint, crypto.randomUUID()); return pickupAttemptKeys.get(fingerprint); }
  const pickupSessionValid = (epoch, sessionToken) => epoch === pickupSessionEpoch && sessionToken === token;
  function pickupPoint(values, prefix) {
    return { lat: Number(values[prefix + '_lat']), lng: Number(values[prefix + '_lng']) };
  }
  function pickupPlanSummary(plan) {
    const path = plan?.path || {}; return 'Plan ' + String(plan?.id || '—') + ' · ' + String(plan?.vehicle_type || 'vehicle') + ' · version ' + String(plan?.version || '—') + ' · expires ' + (plan?.expires_at ? new Date(plan.expires_at).toLocaleString('en-IN', { timeZone: 'Asia/Kolkata' }) : '—') + ' · road distance ' + String(path.distance_meters ?? '—') + ' m';
  }
  function renderPickupPlanResult(data) {
    const target=$('pickup-plan-result'); target.replaceChildren(el('p',pickupPlanSummary(data?.plan)));
    if(!data?.invite_token){target.append(el('p','The one-time invite is not retained for retries. Create a fresh private invite if it was not copied.'));return;}
    const label=el('label','Invite token (copy privately once)');const field=document.createElement('input');field.type='password';field.readOnly=true;field.autocomplete='new-password';field.value=data.invite_token;label.append(field);
    const copy=el('button','Copy private plan invite','secondary');copy.type='button';copy.addEventListener('click',async()=>{try{await navigator.clipboard.writeText('Plan ID: '+data.plan.id+'\nInvite token: '+data.invite_token);formNotice('pickup-plan-status','Private invite copied. Share it only with the intended rider.');}catch{formNotice('pickup-plan-status','Clipboard is unavailable. Select and copy the private invite field manually.',true);}});
    target.append(label,copy);
  }

  function renderPickupGeometry(target, geometry) {
    if (!Array.isArray(geometry) || geometry.length < 2) return;
    const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg'); svg.setAttribute('viewBox', '0 0 100 60'); svg.setAttribute('role', 'img'); svg.setAttribute('aria-label', 'Route geometry preview');
    const xs = geometry.map((point) => Number(point?.[0])).filter(Number.isFinite), ys = geometry.map((point) => Number(point?.[1])).filter(Number.isFinite); if (!xs.length || !ys.length) return;
    const minX = Math.min(...xs), maxX = Math.max(...xs), minY = Math.min(...ys), maxY = Math.max(...ys); const dx = maxX - minX || 1e-6, dy = maxY - minY || 1e-6;
    const points = geometry.map((point) => (((Number(point[0]) - minX) / dx) * 92 + 4).toFixed(2) + ',' + (56 - ((Number(point[1]) - minY) / dy) * 52).toFixed(2)).join(' ');
    const line = document.createElementNS('http://www.w3.org/2000/svg', 'polyline'); line.setAttribute('points', points); line.setAttribute('fill', 'none'); line.setAttribute('stroke', '#26765a'); line.setAttribute('stroke-width', '2.2'); line.setAttribute('stroke-linecap', 'round'); line.setAttribute('stroke-linejoin', 'round'); svg.append(line); target.append(svg);
  }
  function previewMetric(label, value, suffix = '') { return el('p', label + ': ' + (value === undefined || value === null ? 'Unavailable' : String(value)) + suffix); }
  function pickupOptionCard(title, option) {
    const node = card(title, option?.available === false ? 'Unavailable for this preview' : 'Display-only route option');
    if (!option || option.available === false) { node.append(el('p', 'The provider could not return this option. No fallback route is claimed.', 'subtle')); return node; }
    const path = option.path || option.vehicle_path || {}; const detour = option.detour || {};
    node.append(previewMetric('Driving distance', path.distance_meters, ' m'), previewMetric('Driving duration', path.duration_seconds, ' s'), previewMetric('Walking distance', option.walking_distance_meters, ' m'), previewMetric('Walking duration', option.walking_duration_seconds, ' s'), previewMetric('Conservative total pickup access', option.total_access_meters, ' m'), previewMetric('Driver detour', detour.added_distance_meters, ' m'), previewMetric('Handoff gap', option.handoff_gap_meters, ' m'), previewMetric('Unmapped access gaps', option.unmapped_access_gap_meters, ' m'), previewMetric('Added driving time', detour.added_duration_seconds, ' s'), el('p', 'Access and stopping suitability remain unverified.', 'subtle'));
    return node;
  }
  let pickupSelection = null;
  let privateJourneyPanel = null;
  let privateJourneyMountGeneration = 0;
  async function refreshPrivateJourneyPanel() {
    const epoch = pickupSessionEpoch, selected = pickupSelection, generation = ++privateJourneyMountGeneration;
    privateJourneyPanel?.dispose(); privateJourneyPanel = null;
    const module = await import('/mobility-pilot/private-journey-ui.mjs?v=20261022-published-1');
    if (!token || epoch !== pickupSessionEpoch || selected !== pickupSelection || generation !== privateJourneyMountGeneration) return;
    privateJourneyPanel = module.mountPrivateJourney($('private-journey-panel'), {
      api: (path, method, body, headers) => api('mobility' + path, method, body, true, headers),
      getActorId: () => identity?.id, getSessionGeneration: () => pickupSessionEpoch, getSelection: () => pickupSelection,
      onPlanCreated: async result => {
        pickupPlan=result?.data?.plan; pickupIntent=null; pickupPreview=null;
        clearPickupSelection('Private publication invite created. Use this new plan for the next rider.');
        renderPickupPlanResult(result?.data);
        formNotice('pickup-plan-status','Private invite created from the unchanged published route. Copy it privately for the next rider.');
        await refreshPrivateJourneyPanel();
      },
      onOpenTrip: async () => { showView('mine'); await loadMine(); },
      onRequested: async request => { dispatchRequestId = String(request.id); $('dispatch-request-id').value = dispatchRequestId; showView('dispatch'); await loadDispatch(); }
    });
  }
  const pickupSelectionKeys = new Map();
  function pickupSelectionKey(action, id, body) {
    const fingerprint = action + ':' + String(id) + ':' + JSON.stringify(body);
    if (!pickupSelectionKeys.has(fingerprint)) pickupSelectionKeys.set(fingerprint, crypto.randomUUID());
    return pickupSelectionKeys.get(fingerprint);
  }
  function clearPickupSelection(message = 'Load a current preview to choose a pickup option.') {
    privateJourneyMountGeneration++; privateJourneyPanel?.dispose(); privateJourneyPanel = null; $('private-journey-panel').replaceChildren();
    pickupSelection = null;
    $('pickup-selection-choices').replaceChildren(el('p', message, 'empty'));
    $('pickup-selection-review').replaceChildren(); hidden($('pickup-selection-review'), true);
    $('pickup-selection-status').textContent = '';
  }
  function selectionData(json) {
    const value = json?.data?.selection;
    if (!value?.id || value.scope !== 'pickup_only' || value.booking_enabled !== false) throw new Error('Pickup agreement response is incomplete. Reload it before acting.');
    return value;
  }
  function selectionTerms(selection) { return selection?.terms || selection?.terms_json || {}; }
  function selectionExpectedVersions(selection) {
    const terms = selectionTerms(selection);
    return { plan: Number(selection?.plan_version ?? terms.plan_version), intent: Number(selection?.intent_version ?? terms.intent_version) };
  }
  function pointText(point) {
    const lng = Array.isArray(point) ? point[0] : point?.lng;
    const lat = Array.isArray(point) ? point[1] : point?.lat;
    return Number.isFinite(lat) && Number.isFinite(lng) ? String(lat) + ', ' + String(lng) : 'Not evaluated';
  }
  function compactMetric(target, label, value, suffix = '') {
    if (value === undefined || value === null) return;
    target.append(el('p', label + ': ' + String(value) + suffix));
  }
  function renderSelectionReview(selection) {
    void refreshPrivateJourneyPanel().catch(error => formNotice('pickup-selection-status', error.message, true));
    const target = $('pickup-selection-review'); target.replaceChildren(); hidden(target, false);
    const terms = selectionTerms(selection); const state = String(selection?.state || 'unknown');
    const option = terms.selected_option || {}; const path = option.vehicle_path || option.path || {};
    const detour = option.detour || {};
    target.append(el('h4', 'Review the pickup'), el('p', 'Selection ID: ' + String(selection?.id || 'Unavailable')),
      el('p', 'State: ' + state + ' · proposal ' + String(selection?.version ?? '—')),
      el('p', 'Expires: ' + String(selection?.expires_at || 'Unavailable')));
    $('pickup-selection-recover-form').elements.namedItem('id').value = String(selection?.id || '');
    const facts = el('div', null, 'selection-facts');
    compactMetric(facts, 'Pickup choice', selection?.choice === 'DIRECT_RIDER_PICKUP' ? 'Direct rider pickup' : 'On-route meeting point');
    compactMetric(facts, 'Rider origin', pointText(terms.rider_origin));
    compactMetric(facts, 'Vehicle pickup', pointText(option.vehicle_pickup_location));
    if (option.pedestrian_pickup_location) compactMetric(facts, 'Pedestrian meeting point', pointText(option.pedestrian_pickup_location));
    compactMetric(facts, 'Driver start', pointText(terms.driver_endpoints?.[0]));
    compactMetric(facts, 'Driver destination', pointText(terms.driver_endpoints?.[1]));
    compactMetric(facts, 'Driving distance', path.distance_meters, ' m');
    compactMetric(facts, 'Driving duration', path.duration_seconds, ' s');
    compactMetric(facts, 'Walking distance', option.walking_distance_meters, ' m');
    compactMetric(facts, 'Walking duration', option.walking_duration_seconds, ' s');
    compactMetric(facts, 'Conservative total pickup access', option.total_access_meters, ' m');
    compactMetric(facts, 'Extra driving', detour.added_distance_meters, ' m');
    compactMetric(facts, 'Extra driving time', detour.added_duration_seconds, ' s');
    compactMetric(facts, 'Vehicle snap gap', option.vehicle_snap_meters, ' m');
    compactMetric(facts, 'Handoff gap', option.handoff_gap_meters, ' m');
    compactMetric(facts, 'Unmapped access gaps', option.unmapped_access_gap_meters, ' m');
    if (selection?.choice === 'DIRECT_RIDER_PICKUP') facts.append(el('p', 'Walking access has not been evaluated for direct pickup.'));
    const message = {confirmed: 'Pickup agreed; ride not booked.', pending: 'Waiting for rider confirmation; ride not booked.',
      declined: 'Pickup proposal declined; ride not booked.', revoked: 'Pickup agreement revoked; ride not booked.', superseded: 'A newer pickup proposal replaced this agreement; ride not booked.'};
    target.append(facts, el('p', message[state] || 'Pickup unavailable; ride not booked.', 'note'),
      el('p', 'Access and stopping suitability remain unverified. No fare, seat or driver assignment is created here.', 'subtle'));
    const actorId = Number(identity?.id), riderId = Number(terms.rider_id), driverId = Number(terms.driver_id);
    const known = [actorId, riderId, driverId].every((id) => Number.isSafeInteger(id) && id > 0);
    const open = state === 'pending' || state === 'confirmed';
    const isRider = known && actorId === riderId, isDriver = known && actorId === driverId;
    const canRevoke = open && (isRider || isDriver);
    const canConfirmOrDecline = isRider && state === 'pending' && selection.actionable === true;
    if (!canRevoke && !canConfirmOrDecline) return;
    const actionForm = document.createElement('form'); actionForm.className = 'selection-confirm form-grid';
    if (canConfirmOrDecline) {
      const label = document.createElement('label'); label.className = 'wide';
      const checkbox = document.createElement('input'); checkbox.type = 'checkbox'; checkbox.name = 'access_acknowledged'; checkbox.required = true;
      label.append(checkbox, el('span', 'I agree to this pickup point and route details. Access and stopping suitability remain unverified.')); actionForm.append(label);
      const confirm = el('button', 'Confirm pickup agreement', 'primary'); confirm.type = 'submit'; actionForm.append(confirm);
      actionForm.addEventListener('submit', (event) => {
        event.preventDefault();
        if (!checkbox.checked) { formNotice('pickup-selection-status', 'Check the acknowledgment before confirming.', true); return; }
        void task(() => changePickupSelection('confirm', selection, { expected_version: Number(selection.version), terms_hash: selection.terms_hash, access_acknowledged: true }));
      });
    }
    const actions = el('div', null, 'actions');
    for (const [action, title] of [...(canConfirmOrDecline ? [['decline', 'Decline']] : []), ...(canRevoke ? [['revoke', 'Revoke']] : [])]) {
      const button = el('button', title, 'secondary'); button.type = 'button';
      button.addEventListener('click', () => void task(() => changePickupSelection(action, selection, { expected_version: Number(selection.version), terms_hash: selection.terms_hash })));
      actions.append(button);
    }
    actionForm.append(actions); target.append(actionForm);
  }
  async function changePickupSelection(action, selection, body) {
    const epoch = pickupSessionEpoch, sessionToken = token; if (!selection?.id || !body.terms_hash) throw new Error('Pickup terms are incomplete. Refresh the agreement.');
    let result;
    try { result = await api('mobility/pickup-selections/' + encodeURIComponent(selection.id) + '/' + action, 'POST', body, true, { 'Idempotency-Key': pickupSelectionKey(action, selection.id, body) }); } catch (error) { if (pickupSessionValid(epoch, sessionToken)) { clearPickupSelection('Pickup agreement action failed. Refresh the current selection before retrying.'); } throw error; }
    if (!pickupSessionValid(epoch, sessionToken)) return;
    pickupSelection = selectionData(result) || selection; renderSelectionReview(pickupSelection); formNotice('pickup-selection-status', pickupSelection.state === 'confirmed' ? 'Pickup agreed; ride not booked.' : 'Pickup agreement closed.');
  }
  async function createPickupSelection(choice) {
    const epoch = pickupSessionEpoch, sessionToken = token; const preview = pickupPreview; if (!preview?.id) throw new Error('Load a current preview first.');
    const versions = selectionExpectedVersions(preview); if (!Number.isSafeInteger(versions.plan) || !Number.isSafeInteger(versions.intent)) throw new Error('Preview versions are unavailable. Refresh the preview.');
    const body = { choice, expected_plan_version: versions.plan, expected_intent_version: versions.intent };
    let result;
    try { result = await api('mobility/pickup-previews/' + encodeURIComponent(preview.id) + '/selections', 'POST', body, true, { 'Idempotency-Key': pickupSelectionKey('select', preview.id, body) }); } catch (error) { if (pickupSessionValid(epoch, sessionToken)) clearPickupSelection('Pickup choice failed. Refresh the current preview before retrying.'); throw error; }
    if (!pickupSessionValid(epoch, sessionToken)) return;
    pickupSelection = selectionData(result); if (!pickupSelection?.id) throw new Error('Server did not return a pickup selection.'); renderSelectionReview(pickupSelection); formNotice('pickup-selection-status', 'Pickup option selected. Review the exact terms before confirming.');
  }
  function renderPickupSelectionChoices(preview) {
    const target = $('pickup-selection-choices'); target.replaceChildren(); const snapshot = preview?.snapshot || {};
    for (const option of [{ key: 'DIRECT_RIDER_PICKUP', title: 'Direct rider pickup', value: snapshot.direct }, { key: 'ON_ROUTE_MEETING_POINT', title: 'On-route meeting point', value: snapshot.meeting }]) {
      const node = card(option.title, option.value?.available !== true ? 'Unavailable for this preview.' : 'Driver choice');
      const button = el('button', option.value?.available !== true ? 'Unavailable' : 'Choose this pickup', 'primary'); button.type = 'button'; button.disabled = option.value?.available !== true || Number(preview?.driver_id) !== Number(identity?.id); button.addEventListener('click', () => void task(() => createPickupSelection(option.key))); node.append(button); target.append(node);
    }
  }
  async function recoverPickupSelection() {
    const epoch = pickupSessionEpoch, sessionToken = token; const id = String(new FormData($('pickup-selection-recover-form')).get('id') || '').trim(); if (!id) throw new Error('Enter a selection ID.');
    try { const response = await api('mobility/pickup-selections/' + encodeURIComponent(id)); if (!pickupSessionValid(epoch, sessionToken)) return; pickupSelection = selectionData(response); if (!pickupSelection) throw new Error('Selection response was empty.'); renderSelectionReview(pickupSelection); formNotice('pickup-selection-status', 'Pickup agreement loaded.'); } catch (error) { if (pickupSessionValid(epoch, sessionToken)) { clearPickupSelection('Pickup agreement unavailable. Refresh the current preview and selection.'); formNotice('pickup-selection-status', error.message || 'Pickup agreement unavailable.', true); } throw error; }
  }
  function renderPickupPreview(preview) {
    const target = $('pickup-preview-result'); target.replaceChildren(); const snapshot = preview?.snapshot || {};
    target.append(el('p', 'Preview only — no ride booked.', 'note'), pickupOptionCard('Direct rider pickup', snapshot.direct), pickupOptionCard('Best evaluated on-route meeting point', snapshot.meeting)); renderPickupSelectionChoices(preview);
    if (Number.isSafeInteger(Number(snapshot.evaluated_candidates))) target.append(el('p', 'Evaluated candidates: ' + String(snapshot.evaluated_candidates) + '. Best among evaluated candidates, not a global guarantee.', 'subtle'));
    const geometry = snapshot?.direct?.path?.geometry || snapshot?.direct?.vehicle_path?.geometry || snapshot?.meeting?.vehicle_path?.geometry || snapshot?.geometry; renderPickupGeometry(target, geometry);
  }
  function syncPickupCars() {
    const target = $('pickup-plan-car'); target.replaceChildren(); for (const option of $('quote-car').options) if (option.value) { const copy = el('option', option.textContent); copy.value = option.value; target.append(copy); } if (!target.options.length) target.append(el('option', 'Approved vehicle required'));
  }
  async function loadPickupPreviewView() { if (!token) return; hidden($('pickup-agreement-panel'), false); syncPickupCars(); }
  $('pickup-plan-form').addEventListener('submit', (event) => { event.preventDefault(); void task(async () => { const epoch = pickupSessionEpoch, sessionToken = token, values = Object.fromEntries(new FormData(event.currentTarget)); const points = [pickupPoint(values, 'start'), pickupPoint(values, 'end')]; if (!points.every((point) => Number.isFinite(point.lat) && Number.isFinite(point.lng))) throw new Error('Enter finite route coordinates.'); const result = await api('mobility/pickup-plans', 'POST', { car_id: Number(values.car_id), vehicle_type: values.vehicle_type, ride_mode: values.ride_mode, points }, true, { 'Idempotency-Key': pickupKey('plan', values) }); if (!pickupSessionValid(epoch, sessionToken)) return; pickupPlan = result?.data?.plan; clearPickupSelection('Plan changed. Evaluate a fresh preview before selecting pickup terms.'); renderPickupPlanResult(result?.data); formNotice('pickup-plan-status', 'Private plan created. No ride was booked.'); }); });
  $('pickup-intent-form').addEventListener('submit', (event) => { event.preventDefault(); void task(async () => { const epoch = pickupSessionEpoch, sessionToken = token, values = Object.fromEntries(new FormData(event.currentTarget)); const result = await api('mobility/pickup-intents', 'POST', { plan_id: values.plan_id.trim(), invite_token: values.invite_token.trim(), origin: pickupPoint(values, 'origin'), max_walk_meters: Number(values.max_walk_meters) }, true, { 'Idempotency-Key': pickupKey('intent', values) }); if (!pickupSessionValid(epoch, sessionToken)) return; pickupIntent = result?.data?.intent; clearPickupSelection('Intent changed. Evaluate a fresh preview before selecting pickup terms.'); $('pickup-intent-result').textContent = 'Intent ' + String(pickupIntent?.id || '—') + ' shared privately · version ' + String(pickupIntent?.version || '—') + '. No booking was created.'; formNotice('pickup-intent-status', 'Origin shared with the plan owner.'); }); });
  $('pickup-preview-form').addEventListener('submit', (event) => { event.preventDefault(); void task(async () => { const epoch = pickupSessionEpoch, sessionToken = token, values = Object.fromEntries(new FormData(event.currentTarget)); const intentId = values.intent_id.trim(); pickupPreview = null; clearPickupSelection('Evaluating a new preview…'); $('pickup-preview-result').replaceChildren(el('p', 'Evaluating current route options…', 'empty')); const intentResponse = await api('mobility/pickup-intents/' + encodeURIComponent(intentId)); const intent = intentResponse?.data?.intent; if (!pickupSessionValid(epoch, sessionToken)) return; const result = await api('mobility/pickup-intents/' + encodeURIComponent(intentId) + '/previews', 'POST', { expected_plan_version: Number(intent?.plan_version), expected_intent_version: Number(intent?.version), max_detour_meters: Number(values.max_detour_meters), max_detour_seconds: Number(values.max_detour_seconds) }, true, { 'Idempotency-Key': pickupKey('preview', [values, intent?.plan_version, intent?.version]) }); if (!pickupSessionValid(epoch, sessionToken)) return; pickupPreview = result?.data?.preview; renderPickupPreview(pickupPreview); formNotice('pickup-preview-status', 'Preview loaded. The driver can propose an available pickup option.'); }); });
  async function recoverPickup(path, target, status) { const epoch = pickupSessionEpoch, sessionToken = token; const id = String(new FormData($('pickup-' + target + '-recover-form')).get('id') || '').trim(); if (!id) throw new Error('Enter an ID.'); let response; try { response = await api(path + encodeURIComponent(id)); } catch (error) { if (pickupSessionValid(epoch, sessionToken)) { pickupPreview = null; clearPickupSelection('Pickup preview unavailable. Refresh the current plan and rider origin.'); $('pickup-preview-result').replaceChildren(el('p', 'Preview unavailable. Refresh the current plan and rider origin.', 'empty')); } throw error; } if (!pickupSessionValid(epoch, sessionToken)) return; const value = response?.data?.[target]; if (target === 'plan') { pickupPlan = value; clearPickupSelection('Plan refreshed. Evaluate a fresh preview before selecting pickup terms.'); $('pickup-plan-result').textContent = pickupPlanSummary(value); } else if (target === 'intent') { pickupIntent = value; clearPickupSelection('Intent refreshed. Evaluate a fresh preview before selecting pickup terms.'); $('pickup-intent-result').textContent = 'Intent ' + String(value?.id || id) + ' loaded · version ' + String(value?.version || '—') + '. No booking was created.'; } else { pickupPreview = value; renderPickupPreview(value); } $(status).textContent = target + ' loaded. Private access is current.'; }
  $('pickup-plan-recover-form').addEventListener('submit', (event) => { event.preventDefault(); void task(() => recoverPickup('mobility/pickup-plans/', 'plan', 'pickup-recover-status')); });
  $('pickup-intent-recover-form').addEventListener('submit', (event) => { event.preventDefault(); void task(() => recoverPickup('mobility/pickup-intents/', 'intent', 'pickup-recover-status')); });
  $('pickup-preview-recover-form').addEventListener('submit', (event) => { event.preventDefault(); void task(() => recoverPickup('mobility/pickup-previews/', 'preview', 'pickup-recover-status')); });
  $('pickup-selection-recover-form').addEventListener('submit', (event) => { event.preventDefault(); void task(recoverPickupSelection); });
  // On-demand dispatch is deliberately a thin client over the durable API. It
  // never stores credentials or invents route, fare, driver, or lifecycle facts.
  let dispatchQuote = null;
  let dispatchRequestId = null;
  let dispatchPollTimer = null;
  let dispatchHeartbeatTimer = null;
  let dispatchOnline = false;
  let dispatchSessionEpoch = 0;
  const dispatchKeys = new Map();
  const dispatchKey = (name) => {
    if (!dispatchKeys.has(name)) dispatchKeys.set(name, crypto.randomUUID());
    return dispatchKeys.get(name);
  };
  const dispatchSessionValid = (epoch, sessionToken) => epoch === dispatchSessionEpoch && sessionToken === token;
  function stopDispatchPolling() {
    if (dispatchPollTimer) { clearTimeout(dispatchPollTimer); dispatchPollTimer = null; }
    if (dispatchHeartbeatTimer) { clearInterval(dispatchHeartbeatTimer); dispatchHeartbeatTimer = null; }
  }
  function startDispatchHeartbeat(carId, pickupPointId, scope = 'catalog') {
    if (dispatchHeartbeatTimer) clearInterval(dispatchHeartbeatTimer);
    const capturedEpoch = dispatchSessionEpoch; const capturedToken = token;
    dispatchHeartbeatTimer = setInterval(() => {
      if (!dispatchIsActive() || !dispatchOnline || !dispatchSessionValid(capturedEpoch, capturedToken)) return;
      void api('mobility/dispatch/availability', 'PUT', { online: true, car_id: Number(carId), ...(scope === 'private' ? {scope:'private'} : {pickup_point_id:Number(pickupPointId)}) }).catch((error) => {
        if (!dispatchSessionValid(capturedEpoch, capturedToken)) return;
        dispatchOnline = false; if (dispatchHeartbeatTimer) { clearInterval(dispatchHeartbeatTimer); dispatchHeartbeatTimer = null; }
        if (dispatchSessionValid(capturedEpoch, capturedToken)) $('dispatch-availability-status').textContent = 'Heartbeat stopped: ' + error.message;
      });
    }, 25000);
  }
  function dispatchIsActive() {
    return Boolean(token && !document.hidden && $('dispatch') && !$('dispatch').classList.contains('hidden'));
  }
  function dispatchSchedulePoll() {
    if (!dispatchIsActive()) { stopDispatchPolling(); return; }
    if (dispatchPollTimer) clearTimeout(dispatchPollTimer);
    dispatchPollTimer = setTimeout(() => { dispatchPollTimer = null; void refreshDispatchData().finally(dispatchSchedulePoll); }, 5000);
  }
  function dispatchCard(target, title, subtitle) {
    const node = card(title, subtitle); target.replaceChildren(node); return node;
  }
  function dispatchRequestLabel(request) {
    const state = String(request?.state || 'unknown');
    const deadline = request?.expires_at ? new Date(request.expires_at).toLocaleString('en-IN', { timeZone: 'Asia/Kolkata' }) : '—';
    const fare = request?.fare_minor !== undefined ? money(request.fare_minor) + ' · ' + String(request.currency || 'INR') : '';
    return state.toUpperCase() + ' · deadline ' + deadline + (fare ? ' · ' + fare : '');
  }
  async function renderDispatchRequest(request) {
    dispatchRequestId = String(request?.id || dispatchRequestId || '');
    if (!dispatchRequestId) return;
    const area = $('dispatch-request-card');
    const node = dispatchCard(area, 'Request ' + dispatchRequestId, dispatchRequestLabel(request));
    node.append(el('p', (request.synthetic_only ? 'SYNTHETIC QUOTE · ' : '') + (request.scope === 'private' ? 'Agreed private pickup' : 'Pickup zone #' + String(request.pickup_point_id || '—')) + ' · ' + String(request.vehicle_type || 'vehicle')));
    if (request.pricing) node.append(el('p','Demo base '+money(request.pricing.base_fare_minor)+' + direct pickup '+money(request.pricing.direct_pickup_surcharge_minor)+' = '+money(request.pricing.total_fare_minor)));
    const row = actions(node);
    if (['searching', 'offered', 'assigned'].includes(String(request.state))) addButton(row, 'Cancel request', async () => {
      await api('mobility/dispatch/requests/' + encodeURIComponent(dispatchRequestId) + '/cancel', 'POST', undefined, true, { 'Idempotency-Key': dispatchKey('cancel:' + dispatchRequestId) });
      await refreshDispatchData(); notice('Dispatch request cancelled.');
    });
    if (request.assignment?.trip_id) await renderDispatchTrip(Number(request.assignment.trip_id), request.assignment.booking_id, node);
    else $('dispatch-trip-controls').replaceChildren(el('p', 'No canonical trip is assigned yet.', 'empty'));
  }
  async function loadDispatchRequest(id) {
    const epoch = dispatchSessionEpoch; const sessionToken = token;
    const clean = String(id || '').trim();
    if (!/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i.test(clean)) throw new Error('Enter a valid request UUID.');
    const request = await api('mobility/dispatch/requests/' + encodeURIComponent(clean));
    if (!dispatchSessionValid(epoch, sessionToken)) return null;
    await renderDispatchRequest(request); return request;
  }
  async function loadDispatchOffers() {
    const epoch = dispatchSessionEpoch; const sessionToken = token;
    const area = $('dispatch-offers'); area.replaceChildren();
    const offers = list(await api('mobility/dispatch/offers'));
    if (!dispatchSessionValid(epoch, sessionToken)) return;
    if (!offers.length) { area.append(el('p', 'No pending offers.', 'empty')); return; }
    for (const offer of offers) {
      const points = Array.isArray(offer.points) ? offer.points.map((point) => point.address || 'Catalog point').join(' → ') : 'Catalog route';
      const node = card('Offer ' + String(offer.id), points + ' · ' + money(offer.fare_minor) + ' · expires ' + new Date(offer.expires_at).toLocaleTimeString('en-IN', { timeZone: 'Asia/Kolkata' }));
      node.append(el('p', (offer.synthetic_only ? 'SYNTHETIC · ' : '') + String(offer.vehicle_type || 'vehicle') + (offer.scope === 'private' ? ' · Agreed private pickup' : ' · pickup zone #' + String(offer.pickup_point_id))));
      if (offer.pricing) node.append(el('p','Demo base '+money(offer.pricing.base_fare_minor)+' + direct pickup '+money(offer.pricing.direct_pickup_surcharge_minor)+' = '+money(offer.pricing.total_fare_minor)));
      const row = actions(node);
      addButton(row, 'Accept offer', async () => { const accepted = await api('mobility/dispatch/offers/' + encodeURIComponent(offer.id) + '/accept', 'POST', undefined, true, { 'Idempotency-Key': dispatchKey('accept:' + offer.id) }); dispatchRequestId = String(offer.request_id); await loadDispatchRequest(dispatchRequestId); notice('Offer accepted. Canonical trip #' + String(accepted.trip_id) + ' is ready for lifecycle controls.'); }, true);
      addButton(row, 'Decline', async () => { await api('mobility/dispatch/offers/' + encodeURIComponent(offer.id) + '/decline', 'POST', undefined, true, { 'Idempotency-Key': dispatchKey('decline:' + offer.id) }); await loadDispatchOffers(); notice('Offer declined.'); });
      area.append(node);
    }
  }
  async function renderDispatchTrip(tripIdValue, bookingId, requestNode) {
    const epoch = dispatchSessionEpoch; const sessionToken = token;
    const response = await api('mobility/trips/' + tripIdValue + '/lifecycle');
    if (!dispatchSessionValid(epoch, sessionToken)) return;
    const area = $('dispatch-trip-controls'); area.replaceChildren();
    const state = String(response.state || 'planned');
    const node = card('Canonical trip #' + tripIdValue, 'Lifecycle: ' + state + (bookingId ? ' · booking #' + bookingId : ''));
    const owner = Number(response.owner_id) === Number(identity?.id);
    const row = actions(node); const send = async (action, passengerId) => { const body = { action }; if (passengerId) body.passenger_id = passengerId; await apiTransition(tripIdValue, body, dispatchKey('transition:' + tripIdValue + ':' + action + ':' + String(passengerId || ''))); notice('Recorded canonical action: ' + action + '.'); await renderDispatchTrip(tripIdValue, bookingId, requestNode); };
    if (owner) {
      if (state === 'planned') { addButton(row, 'Declare arrival', () => send('arrive'), true); addButton(row, 'Start trip', () => send('start'), true); }
      if (state === 'in_progress') {
        for (const participant of (Array.isArray(response.participants) ? response.participants : [])) { const riderId = Number(participant.passenger_id ?? participant.user_id); if (!Number.isSafeInteger(riderId)) continue; if (participant.state === 'accepted') addButton(row, 'Board rider #' + riderId, () => send('board', riderId)); if (participant.state === 'boarded') addButton(row, 'Alight rider #' + riderId, () => send('alight', riderId)); }
        addButton(row, 'Complete trip', () => send('complete'), true);
      }
    } else {
      node.append(el('p', 'Rider view · Your trip status is ' + state + '. Driver lifecycle controls are unavailable to riders.', 'subtle'));
      const participant = (response.participants || []).find((item) => Number(item.passenger_id) === Number(identity?.id));
      if (participant?.state === 'alighted' || state === 'completed') addButton(row, 'Confirm drop-off', async () => { await api('mobility/trips/' + tripIdValue + '/dropoff-confirmation', 'POST', undefined, true, { 'Idempotency-Key': dispatchKey('dropoff:' + tripIdValue + ':' + String(bookingId || '')) }); notice('Drop-off confirmed.'); }, true);
    }
    if (state === 'completed' || state === 'cancelled') node.append(el('p', 'Terminal canonical state. Dispatch history remains available for recovery.', 'subtle'));
    trackingMount(node, { id: tripIdValue }, owner ? 'driver' : 'rider');
    area.append(node); if (requestNode) requestNode.append(el('p', 'Trip controls are shown in the Assigned trip panel.', 'subtle'));
  }
  async function loadDispatchCatalog() {
    const epoch = dispatchSessionEpoch; const sessionToken = token;
    const response = await api('mobility/pickup-points?page_size=50&vehicle_type=' + encodeURIComponent($('dispatch-vehicle').value || 'car'));
    if (!dispatchSessionValid(epoch, sessionToken)) return;
    for (const id of ['dispatch-pickup', 'dispatch-dropoff', 'dispatch-zone']) { const target = $(id); target.replaceChildren(el('option', 'Choose a catalog point')); target.options[0].value = ''; }
    for (const point of list(response)) for (const id of ['dispatch-pickup', 'dispatch-dropoff', 'dispatch-zone']) { const option = el('option', String(point.name)); option.value = String(point.id); $(id).append(option); }
  }
  function syncDispatchCars() {
    const target = $('dispatch-car'); target.replaceChildren();
    for (const option of $('quote-car').options) if (option.value) { const copy = el('option', option.textContent); copy.value = option.value; target.append(copy); }
    if (!target.options.length) target.append(el('option', 'Approved vehicle required'));
  }
  async function refreshDispatchData() {
    const epoch = dispatchSessionEpoch; const sessionToken = token;
    if (!token || !dispatchIsActive()) return;
    try { await loadDispatchOffers(); } catch (error) { if (dispatchSessionValid(epoch, sessionToken)) $('dispatch-offers').replaceChildren(el('p', 'Offers temporarily unavailable. Refresh to reconnect.', 'empty')); }
    if (!dispatchRequestId) { const requests = list(await api('mobility/dispatch/requests')); dispatchRequestId = requests.find(item => ['searching','offered','assigned'].includes(item.state))?.id || requests[0]?.id || null; }
    if (dispatchRequestId) { try { await loadDispatchRequest(dispatchRequestId); } catch (error) { if (dispatchSessionValid(epoch, sessionToken)) $('dispatch-request-status').textContent = 'Request refresh failed: ' + error.message; } }
    if (!dispatchSessionValid(epoch, sessionToken)) return;
  }
  async function loadDispatch() {
    const epoch = dispatchSessionEpoch; const sessionToken = token;
    if (!token) return;
    syncDispatchCars();
    await loadDispatchCatalog();
    try { const availability = await api('mobility/dispatch/availability'); if (!dispatchSessionValid(epoch, sessionToken)) return; const fresh = availability.online && Date.parse(availability.fresh_until) > Date.now(); $('dispatch-availability-status').textContent = fresh ? 'Online until ' + new Date(availability.fresh_until).toLocaleTimeString('en-IN', { timeZone: 'Asia/Kolkata' }) + ' IST.' : 'Offline or expired.'; dispatchOnline = Boolean(fresh); if (availability.car_id) $('dispatch-car').value = String(availability.car_id); if (availability.pickup_point_id) $('dispatch-zone').value = String(availability.pickup_point_id); if (availability.scope) $('dispatch-scope').value = availability.scope; updateDispatchScope(); if (fresh) startDispatchHeartbeat(availability.car_id, availability.pickup_point_id, availability.scope); } catch (error) { if (dispatchSessionValid(epoch, sessionToken)) $('dispatch-availability-status').textContent = 'Availability unavailable: ' + error.message; }
    await refreshDispatchData();
    if (dispatchSessionValid(epoch, sessionToken)) dispatchSchedulePoll();
  }
  $('dispatch-vehicle').addEventListener('change', () => void task(loadDispatchCatalog));
  const invalidateDispatchQuote = () => { dispatchQuote = null; $('dispatch-request').disabled = true; $('dispatch-quote-summary').textContent = 'Route changed. Get a fresh trusted quote.'; };
  for (const id of ['dispatch-vehicle', 'dispatch-pickup', 'dispatch-dropoff']) $(id).addEventListener('change', invalidateDispatchQuote);
  $('dispatch-get-quote').addEventListener('click', () => void task(async () => {
    const epoch = dispatchSessionEpoch, sessionToken = token, mode = $('dispatch-vehicle').value;
    const pickup = Number($('dispatch-pickup').value), dropoff = Number($('dispatch-dropoff').value); if (!pickup || !dropoff || pickup === dropoff) throw new Error('Choose two distinct catalog stops.');
    const response = await api('mobility/routes/quote', 'POST', { pickup_point_id: pickup, dropoff_point_id: dropoff, vehicle_type: $('dispatch-vehicle').value });
    if (!dispatchSessionValid(epoch, sessionToken)) return;
    if (mode !== $('dispatch-vehicle').value || pickup !== Number($('dispatch-pickup').value) || dropoff !== Number($('dispatch-dropoff').value)) throw new Error('Route changed while quoting. Get a fresh quote.');
    const quote = response?.data; if (!quote || typeof quote.id !== 'string' || quote.currency !== 'INR' || !Number.isSafeInteger(quote.seat_price_minor) || !Number.isFinite(Date.parse(quote.expires_at))) throw new Error('The server returned an invalid quote.');
    dispatchQuote = quote; $('dispatch-request').disabled = false; $('dispatch-quote-summary').textContent = (quote.synthetic_only ? 'SYNTHETIC TEST QUOTE · ' : '') + money(quote.seat_price_minor) + ' per rider · expires ' + new Date(quote.expires_at).toLocaleTimeString('en-IN', { timeZone: 'Asia/Kolkata' }) + ' IST. Server-owned fare; no money collected.';
  }));
  $('dispatch-request-form').addEventListener('submit', (event) => { event.preventDefault(); void task(async () => {
    const epoch = dispatchSessionEpoch, sessionToken = token;
    if (!dispatchQuote || Date.parse(dispatchQuote.expires_at) <= Date.now()) throw new Error('This quote expired. Get a fresh trusted quote.');
    const key = dispatchKey('request:' + dispatchQuote.id); const result = await api('mobility/dispatch/requests', 'POST', { quote_id: dispatchQuote.id }, true, { 'Idempotency-Key': key }); if (!dispatchSessionValid(epoch, sessionToken)) return; dispatchRequestId = String(result.id); $('dispatch-request-id').value = dispatchRequestId; $('dispatch-request-status').textContent = 'Request ' + dispatchRequestId + ' is searching. Matching updates are saved; refresh reconnects.'; await loadDispatchRequest(dispatchRequestId); dispatchSchedulePoll();
  }); });
  $('dispatch-recover-form').addEventListener('submit', (event) => { event.preventDefault(); void task(async () => { await loadDispatchRequest(new FormData(event.currentTarget).get('request_id')); dispatchSchedulePoll(); }); });
  $('dispatch-refresh').addEventListener('click', () => void task(loadDispatch));
  function updateDispatchScope() { const privateScope = $('dispatch-scope').value === 'private'; $('dispatch-zone').required = !privateScope; $('dispatch-zone').disabled = privateScope; }
  $('dispatch-scope').addEventListener('change', updateDispatchScope);
  $('dispatch-availability-form').addEventListener('submit', (event) => { event.preventDefault(); void task(async () => { const epoch = dispatchSessionEpoch, sessionToken = token; const values = Object.fromEntries(new FormData(event.currentTarget)); const carId = Number(values.car_id), zoneId = Number(values.pickup_point_id), scope = values.scope || 'catalog'; const result = await api('mobility/dispatch/availability', 'PUT', { online: true, car_id: carId, ...(scope === 'private' ? {scope:'private'} : {pickup_point_id:zoneId}) }); if (!dispatchSessionValid(epoch, sessionToken)) return; dispatchOnline = true; $('dispatch-availability-status').textContent = 'Online until ' + new Date(result.fresh_until).toLocaleTimeString('en-IN', { timeZone: 'Asia/Kolkata' }) + ' IST. Heartbeat is server-clock based.'; startDispatchHeartbeat(carId, zoneId, scope); }); });
  $('dispatch-offline').addEventListener('click', () => void task(async () => { await api('mobility/dispatch/availability', 'PUT', { online: false }); dispatchOnline = false; if (dispatchHeartbeatTimer) { clearInterval(dispatchHeartbeatTimer); dispatchHeartbeatTimer = null; } $('dispatch-availability-status').textContent = 'Offline. Existing assigned trips are retained.'; }));
  /* Private canonical trip tracking. Coordinates and queues live only in this
     page. The server remains authoritative for membership, consent and order. */
  const trackingSessions = new Map();
  const trackingReaders = new Map();
  let trackingGeneration = 0;
  const TRACKING_QUEUE_LIMIT = 20;
  const TRACKING_POLL_MS = 5000;
  const TRACKING_RETRY_MS = 3000;
  function trackingKey(kind, trip, suffix = '') { return 'web-tracking-' + kind + '-' + trip + (suffix ? '-' + suffix : ''); }
  function trackingTripId(value) { const id = Number(value?.id ?? value?.trip_id ?? value); return Number.isSafeInteger(id) && id > 0 ? id : null; }
  function trackingPosition(position) {
    const coords = position?.coords || {};
    const lat = Number(coords.latitude), lng = Number(coords.longitude), accuracy = Number(coords.accuracy);
    const timestamp = Number(position?.timestamp);
    if (![lat, lng, accuracy, timestamp].every(Number.isFinite) || lat < -90 || lat > 90 || lng < -180 || lng > 180 || accuracy <= 0 || accuracy > 500 || timestamp <= 0) return null;
    const recordedAt = new Date(timestamp);
    if (!Number.isFinite(recordedAt.getTime())) return null;
    return { lat, lng, accuracy_meters: accuracy, recorded_at: recordedAt.toISOString() };
  }
  function trackingErrorTerminal(error) { return ['TRACKING_STOPPED', 'TRACKING_DRIVER_FORBIDDEN', 'TRACKING_FORBIDDEN', 'TRACKING_SESSION_STALE', 'TRACKING_UNAVAILABLE', 'TRIP_NOT_FOUND', 'ACCOUNT_RESTRICTED', 'PRIVATE_TRACKING_REQUIRES_COHORT'].includes(String(error?.code || '')); }
  function trackingSessionValid(state) { return state && state.generation === trackingGeneration && state.token === token && !state.stopped; }
  function trackingText(target, text, error = false) { if (!target) return; target.textContent = text; target.classList.toggle('error', error); }
  function trackingRenderLocation(target, payload) {
    const location = payload?.location;
    if (!location || payload?.stale) { trackingMapUpdate(target, null); trackingText(target, 'Location is stale or unavailable. Waiting for a fresh driver sample.'); return; }
    const when = new Date(String(location.recorded_at));
    const age = Number.isFinite(when.getTime()) ? Math.max(0, Math.round((Date.now() - when.getTime()) / 1000)) : null;
    trackingMapUpdate(target, location);
    trackingText(target, 'Last seen ' + (age === null ? 'recently' : age + 's ago') + ' · accuracy ±' + Number(location.accuracy_meters).toFixed(0) + ' m · sequence ' + String(location.sequence || '—'));
  }
  function trackingMapUpdate(status, location) {
    const frame = status?.closest('.tracking-panel')?.querySelector('iframe');
    if (!frame?.contentWindow) return;
    frame.contentWindow.postMessage({ type: 'mobility-private-tracking', location: location ? { lat: location.lat, lng: location.lng, accuracy_meters: location.accuracy_meters, recorded_at: location.recorded_at } : null }, window.location.origin);
  }
  function trackingPanel(tripIdValue, role) {
    const panel = el('div', null, 'tracking-panel');
    panel.dataset.trackingTrip = String(tripIdValue);
    const title = el('strong', role === 'driver' ? 'Private location sharing' : 'Driver location');
    const status = el('p', role === 'driver' ? 'Sharing is off until you give explicit consent.' : 'No current driver location.', 'tracking-status');
    panel.append(title, status);
    if (role === 'driver') {
      const row = actions(panel); const start = el('button', 'Start private sharing', 'secondary'); start.type = 'button';
      const stop = el('button', 'Stop sharing', 'secondary'); stop.type = 'button'; stop.hidden = true;
      row.append(start, stop); panel.append(el('p', 'Foreground browser GPS only. Keep this page visible; Android companion support is needed for screen lock.', 'subtle'));
      start.addEventListener('click', () => void task(() => trackingStart(tripIdValue, panel, start, stop, status)));
      stop.addEventListener('click', () => void task(() => trackingStop(tripIdValue, panel, start, stop, status)));
    } else {
      const mapButton = el('button', 'Show driver on Google map', 'secondary'); mapButton.type = 'button';
      panel.append(mapButton, el('p', 'Opening the map sends map requests to Google. Precise locations stay out of URLs and browser storage.', 'subtle'));
      mapButton.addEventListener('click', () => {
        const current = panel.querySelector('iframe');
        if (current) { current.remove(); mapButton.textContent = 'Show driver on Google map'; return; }
        const frame = document.createElement('iframe'); frame.title = 'Private live driver map'; frame.src = '/mobility-pilot/maps?view=tracking'; frame.className = 'private-journey-map'; frame.referrerPolicy = 'same-origin';
        panel.append(frame); mapButton.textContent = 'Close driver map';
      });
      trackingReaderStart(tripIdValue, status);
    }
    return panel;
  }
  async function refreshChatNotifications() {
    if (!token || document.hidden || chatNotificationLoading) return;
    const sessionToken = token, epoch = reportSessionEpoch;
    chatNotificationLoading = true;
    try {
      const result = await api('mobility/notifications');
      if (sessionToken !== token || epoch !== reportSessionEpoch) return;
      const count = Number(result?.data?.unread_messages || 0), button = $('chat-notifications');
      button.hidden = false; button.textContent = count ? 'Messages · ' + count + ' unread' : 'Messages';
    } catch { /* Chat availability is separately reported by each trip panel. */ }
    finally { chatNotificationLoading = false; }
  }
  function startChatNotifications() {
    if (!chatNotificationTimer) chatNotificationTimer = setInterval(() => void refreshChatNotifications(), 15000);
    void refreshChatNotifications();
  }
  async function mountChat(slot, id) {
    const sessionToken = token, epoch = reportSessionEpoch;
    const module = await import('/mobility-pilot/trip-chat-ui.mjs?v=20261021-chat-1');
    if (!slot.isConnected || sessionToken !== token || epoch !== reportSessionEpoch) return;
    const controller = module.mountTripChat(slot, { tripId: id, getActorId: () => identity?.id, getSessionGeneration: () => reportSessionEpoch,
      api: (path, method, body, headers) => api('mobility' + path, method, body, true, headers), onNotificationChange: refreshChatNotifications });
    tripChats.set(slot, controller);
  }
  function trackingMount(node, trip, role) {
    const id = trackingTripId(trip); if (!id) return;
    node.querySelectorAll('[data-tracking-trip="' + id + '"]').forEach((item) => item.remove());
    node.querySelectorAll('.trip-chat-slot').forEach(slot => { tripChats.get(slot)?.dispose(); tripChats.delete(slot); slot.remove(); });
    for (const [slot, controller] of tripChats) if (!slot.isConnected) { controller.dispose(); tripChats.delete(slot); }
    node.append(trackingPanel(id, role));
    const slot = el('div', null, 'trip-chat-slot'); node.append(slot);
    void mountChat(slot, id).catch(() => { if (slot.isConnected) slot.textContent = 'Trip chat could not load. Refresh to retry.'; });
  }
  async function trackingStart(trip, panel, start, stop, status) {
    if (trackingSessions.has(trip)) return;
    const state = { trip, generation: trackingGeneration, token, sessionId: null, sessionKey: trackingKey('session', trip, crypto.randomUUID()), stopKey: trackingKey('stop', trip, crypto.randomUUID()), sequence: 0, lastRecordedAt: 0, queue: [], watchId: null, stopped: false, flushing: false, retryTimer: null };
    trackingSessions.set(trip, state); start.disabled = true; trackingText(status, 'Requesting explicit sharing consent…');
    try {
      const response = await api('mobility/trips/' + trip + '/tracking/session', 'POST', { consent: true }, true, { 'Idempotency-Key': state.sessionKey });
      state.sessionId = String(response?.data?.session_id || response?.session_id || '');
      if (!state.sessionId) throw new Error('Tracking session was not returned.');
      if ((response?.data ?? response)?.active !== true) throw new Error('This sharing session ended. Start a new session.');
      if (!trackingSessionValid(state)) { trackingBestEffortStopFor(state.trip, state.sessionId, state.stopKey, state.token); return; }
      trackingText(status, 'Sharing privately while this page is visible.'); start.hidden = true; stop.hidden = false;
      trackingWatch(state, panel, status);
    } catch (error) { if (trackingSessions.get(trip) === state) { trackingSessions.delete(trip); trackingText(status, error.message || 'Sharing could not start.', true); start.disabled = false; } }
  }
  function trackingWatch(state, panel, status) {
    if (!trackingSessionValid(state) || document.hidden || state.watchId !== null) return;
    if (!navigator.geolocation?.watchPosition) { trackingText(status, 'This browser does not provide foreground location.', true); return; }
    state.watchId = navigator.geolocation.watchPosition((position) => {
      if (!trackingSessionValid(state)) return;
      const sample = trackingPosition(position);
      if (!sample) { trackingText(status, 'GPS sample rejected: accuracy, range or timestamp was invalid.', true); return; }
      const recordedAt = Date.parse(sample.recorded_at);
      if (!Number.isFinite(recordedAt) || recordedAt <= state.lastRecordedAt) { trackingText(status, 'GPS sample rejected: timestamp was older than the last queued sample.', true); return; }
      state.lastRecordedAt = recordedAt;
      if (state.queue.length >= TRACKING_QUEUE_LIMIT) state.queue.shift();
      state.sequence += 1; state.queue.push({ ...sample, sequence: state.sequence });
      trackingFlush(state, status);
    }, () => trackingText(status, 'Location is unavailable. Sharing will retry while this page stays visible.', true), { enableHighAccuracy: true, maximumAge: 5000, timeout: 10000 });
  }
  async function trackingFlush(state, status) {
    if (!trackingSessionValid(state) || state.flushing || state.retryTimer || !state.sessionId || !state.queue.length || document.hidden) return;
    state.flushing = true; const item = state.queue[0];
    try {
      await api('mobility/trips/' + state.trip + '/tracking/locations', 'POST', { session_id: state.sessionId, sequence: item.sequence, lat: item.lat, lng: item.lng, accuracy_meters: item.accuracy_meters, recorded_at: item.recorded_at }, true, { 'Idempotency-Key': trackingKey('location', state.trip, state.sessionId + '-' + item.sequence) });
      if (trackingSessionValid(state) && state.queue[0] === item) state.queue.shift();
      if (trackingSessionValid(state)) trackingText(status, 'Sharing privately · last sample sent at ' + new Date().toLocaleTimeString('en-IN'));
    } catch (error) {
      if (trackingSessionValid(state) && trackingErrorTerminal(error)) { trackingText(status, 'Sharing stopped: ' + error.message, true); void trackingStop(state.trip, null, null, null, status, true); }
      else if (trackingSessionValid(state)) { clearTimeout(state.retryTimer); state.retryTimer = setTimeout(() => { state.retryTimer = null; void trackingFlush(state, status); }, TRACKING_RETRY_MS); trackingText(status, 'Connection unavailable. Retrying the same sample safely…', true); }
    } finally { state.flushing = false; if (trackingSessionValid(state) && state.queue.length && !state.retryTimer && !document.hidden) void trackingFlush(state, status); }
  }
  async function trackingStop(trip, panel, start, stop, status, automatic = false) {
    const state = trackingSessions.get(trip); if (!state) return;
    state.stopped = true; if (state.watchId !== null) navigator.geolocation?.clearWatch?.(state.watchId); clearTimeout(state.retryTimer); state.queue.length = 0; trackingSessions.delete(trip);
    let confirmed = false;
    try { await api('mobility/trips/' + trip + '/tracking/stop', 'POST', { session_id: state.sessionId }, true, { 'Idempotency-Key': state.stopKey }); confirmed = true; } catch (error) { if (!automatic) trackingText(status, 'Sharing stopped locally; server stop could not be confirmed.', true); }
    if (panel) { if (start) { start.hidden = false; start.disabled = false; } if (stop) stop.hidden = true; if (!automatic && confirmed) trackingText(status, 'Private sharing stopped.'); }
  }
  function trackingReaderStart(trip, target) {
    const previous = trackingReaders.get(trip); if (previous) previous.targets.add(target); else trackingReaders.set(trip, { trip, generation: trackingGeneration, token, targets: new Set([target]), timer: null, inFlight: false, stopped: false });
    const reader = trackingReaders.get(trip); if (!reader.timer) trackingReaderPoll(reader);
  }
  async function trackingReaderPoll(reader) {
    if (!reader || reader.stopped || reader.generation !== trackingGeneration || reader.token !== token || document.hidden) return;
    if (reader.inFlight) return; reader.inFlight = true;
    try { const response = await api('mobility/trips/' + reader.trip + '/tracking'); if (reader.generation === trackingGeneration && reader.token === token) { for (const target of reader.targets) { if (!target.isConnected) reader.targets.delete(target); else trackingRenderLocation(target, response?.data ?? response); } } }
    catch (error) { if (reader.generation === trackingGeneration && reader.token === token) { reader.targets.forEach((target) => { trackingMapUpdate(target, null); trackingText(target, trackingErrorTerminal(error) ? 'Tracking is unavailable for this trip.' : 'Tracking refresh unavailable; retrying.', true); }); if (trackingErrorTerminal(error)) { reader.stopped = true; trackingReaders.delete(reader.trip); } } }
    finally { reader.inFlight = false; if (!reader.stopped && reader.generation === trackingGeneration && !document.hidden) reader.timer = setTimeout(() => { reader.timer = null; trackingReaderPoll(reader); }, TRACKING_POLL_MS); }
  }
  function trackingBestEffortStopFor(trip, sessionId, stopKey, capturedToken) { if (!capturedToken || !sessionId) return; try { fetch('/api/mobility/trips/' + trip + '/tracking/stop', { method: 'POST', headers: { Accept: 'application/json', Authorization: 'Bearer ' + capturedToken, 'Content-Type': 'application/json', 'Idempotency-Key': stopKey }, body: JSON.stringify({ session_id: sessionId }), credentials: 'same-origin', cache: 'no-store', keepalive: true }); } catch {} }
  function trackingBestEffortStop() { for (const state of trackingSessions.values()) trackingBestEffortStopFor(state.trip, state.sessionId, state.stopKey, state.token); }
  function trackingStopAll() { trackingGeneration++; for (const state of trackingSessions.values()) { state.stopped = true; if (state.watchId !== null) navigator.geolocation?.clearWatch?.(state.watchId); clearTimeout(state.retryTimer); } trackingSessions.clear(); for (const reader of trackingReaders.values()) { reader.stopped = true; clearTimeout(reader.timer); } trackingReaders.clear(); }
  function trackingResumeVisible() { if (document.hidden) { for (const state of trackingSessions.values()) { if (state.watchId !== null) navigator.geolocation?.clearWatch?.(state.watchId); state.watchId = null; } return; } for (const state of trackingSessions.values()) trackingWatch(state, null, document.querySelector('[data-tracking-trip="' + state.trip + '"] .tracking-status')); for (const reader of trackingReaders.values()) trackingReaderPoll(reader); }

  /* Driver onboarding is intentionally a small client over the authenticated
     onboarding API. Tokens stay in this closure and documents stay private. */
  let driverSessionEpoch = 0;
  const driverPreviewClosers = new Set();
  let driverOnboardings = [];
  let driverQueueAfter = null;
  let driverQueueFilter = 'pending';
  const driverLabels = { driver_license: 'Driver licence', profile_photo: 'Profile photo', rc: 'Registration certificate', insurance: 'Insurance', permit: 'Permit', fitness: 'Fitness certificate', puc: 'PUC', identity_or_address: 'Identity or address', ownership_noc: 'Ownership NOC' };
  const driverStatuses = { pending: 'Pending operator review', needs_resubmission: 'Correction requested', approved: 'Approved manually', rejected: 'Rejected', suspended: 'Suspended by operator' };
  function driverSessionValid(epoch, sessionToken) { return epoch === driverSessionEpoch && sessionToken === token; }
  function driverDocumentApi(id, documentId) {
    if (!token) throw new Error('Please sign in first.');
    return fetch('/api/mobility/driver-onboarding/' + encodeURIComponent(String(id)) + '/documents/' + encodeURIComponent(String(documentId)), { headers: { Accept: '*/*', Authorization: 'Bearer ' + token }, cache: 'no-store', credentials: 'same-origin' }).then(async (response) => { if (!response.ok) { if (response.status === 401) signOut(); let body = {}; try { body = await response.json(); } catch {} throw new Error(errorLabel(body, response.status)); } return response.blob(); });
  }
  function driverStatusLabel(status) { return driverStatuses[String(status)] || String(status || 'Unknown'); }
  function driverChecklist(item, operator = false) {
    const listNode = el('ul', null, 'driver-checklist');
    for (const doc of (Array.isArray(item.checklist) ? item.checklist : [])) {
      const row = el('li', null, 'driver-document ' + String(doc.status || 'missing'));
      const title = el('span', driverLabels[doc.document_type] || doc.document_type);
      const detail = el('span', (doc.required ? 'Required · ' : 'Optional · ') + String(doc.status || 'missing').replaceAll('_', ' ') + (doc.expires_at ? ' · expires ' + doc.expires_at : ''));
      row.append(title, detail);
      if (doc.review_reason) row.append(el('small', 'Correction: ' + String(doc.review_reason)));
      if (operator && doc.document_id && ['submitted', 'accepted', 'needs_resubmission'].includes(String(doc.status))) {
        const button = el('button', 'View private document', 'secondary'); button.type = 'button';
        button.addEventListener('click', () => void task(async () => {
          const epoch = driverSessionEpoch, sessionToken = token;
          button.disabled = true;
          try {
            const blob = await driverDocumentApi(item.id, doc.document_id);
            if (!driverSessionValid(epoch, sessionToken)) return;
            const url = URL.createObjectURL(blob), dialog = document.createElement('dialog'); dialog.className = 'private-document-preview';
            const close = () => { dialog.close(); dialog.remove(); URL.revokeObjectURL(url); driverPreviewClosers.delete(close); };
            driverPreviewClosers.add(close);
            const exit = el('button','Close document','secondary'); exit.type='button'; exit.addEventListener('click',close);
            dialog.addEventListener('cancel',event=>{event.preventDefault();close();});
            dialog.append(el('h3',driverLabels[doc.document_type] || 'Private document'),el('p','Manual review only. This private preview is cleared when closed.'),exit);
            if (blob.type.startsWith('image/')) { const image = document.createElement('img'); image.src=url; image.alt='Private '+(driverLabels[doc.document_type] || 'document'); dialog.append(image); }
            else { const frame = document.createElement('iframe'); frame.src=url; frame.title='Private document preview'; frame.setAttribute('sandbox',''); dialog.append(frame); }
            document.body.append(dialog); dialog.showModal();
          } finally { button.disabled=false; }
        })); row.append(button);
      }
      listNode.append(row);
    }
    return listNode;
  }
  function driverProfileForm(item) {
    const form = document.createElement('form'); form.className = 'form-grid driver-profile-form';
    const name = document.createElement('input'); name.name = 'profile_display_name'; name.value = String(item.profile_display_name || ''); name.maxLength = 120; name.required = true;
    const phone = document.createElement('input'); phone.name = 'profile_phone'; phone.value = String(item.profile_phone || ''); phone.maxLength = 32; phone.autocomplete = 'tel';
    const labelName = document.createElement('label'); labelName.append('Display name', name); const labelPhone = document.createElement('label'); labelPhone.append('Phone (optional)', phone);
    const submit = el('button', 'Save profile', 'secondary'); submit.type = 'submit'; const status = el('p', '', 'status wide');
    const editable = ['pending', 'needs_resubmission', 'rejected'].includes(String(item.status));
    if (!editable) { name.disabled = true; phone.disabled = true; submit.disabled = true; submit.textContent = 'Profile locked after decision'; }
    form.append(labelName, labelPhone, submit, status); form.addEventListener('submit', (event) => { event.preventDefault(); void task(async () => { const values = Object.fromEntries(new FormData(form)); values.expected_version = Number(item.version); const result = await api('mobility/driver-onboarding/' + item.id + '/profile', 'PUT', values); if (result?.version) item.version = Number(result.version); status.textContent = 'Profile saved. Checklist version is now ' + item.version + '.'; status.classList.remove('error'); await loadDriverOnboarding(); }); });
    return form;
  }
  function driverUploadForm(item, doc) {
    const form = document.createElement('form'); form.className = 'form-grid driver-upload-form';
    const file = document.createElement('input'); file.type = 'file'; file.name = 'document'; file.accept = 'application/pdf,image/jpeg,image/png'; file.required = true;
    const expiry = document.createElement('input'); expiry.type = 'date'; expiry.name = 'expires_at'; if (!['driver_license', 'rc', 'insurance', 'permit', 'fitness', 'puc'].includes(doc.document_type)) expiry.hidden = true;
    const label = document.createElement('label'); label.className = 'wide'; label.append('Upload ' + (driverLabels[doc.document_type] || doc.document_type), file); const expiryLabel = document.createElement('label'); expiryLabel.append('Expiry date (required for expiring documents)', expiry);
    const button = el('button', 'Upload correction', 'secondary'); button.type = 'submit'; const status = el('p', '', 'status wide'); form.append(label, expiryLabel, button, status);
    form.addEventListener('submit', (event) => { event.preventDefault(); void task(async () => { if (!file.files?.[0]) throw new Error('Choose a PDF, JPG or PNG.'); const body = new FormData(); body.append('document_type', doc.document_type); body.append('document', file.files[0]); if (expiry.value) body.append('expires_at', expiry.value); body.append('expected_version', String(item.version)); button.disabled = true; try { const response = await fetch('/api/mobility/driver-onboarding/' + item.id + '/documents', { method: 'POST', headers: { Accept: 'application/json', Authorization: 'Bearer ' + token }, body, cache: 'no-store', credentials: 'same-origin' }); let json = {}; try { json = await response.json(); } catch {} if (!response.ok) { if (response.status === 401) signOut(); if (response.status === 409) { await loadDriverOnboarding(); throw new Error('This checklist changed. Reloaded the latest version; review it before uploading again.'); } throw new Error(errorLabel(json, response.status)); } status.textContent = 'Document uploaded as a new private revision.'; status.classList.remove('error'); await loadDriverOnboarding(); } finally { button.disabled = false; } }); });
    return form;
  }
  function driverCard(item) {
    const node = card('Application · ' + driverStatusLabel(item.status), 'Vehicle type: ' + String(item.vehicle_type || 'vehicle') + ' · checklist version ' + String(item.version));
    if (item.review_reason) node.append(el('p', 'Operator note: ' + String(item.review_reason), 'note'));
    node.append(driverProfileForm(item), driverChecklist(item));
    const editable = ['pending', 'needs_resubmission', 'rejected'].includes(String(item.status));
    if (editable) {
      const uploads = el('div', null, 'driver-upload-list');
      for (const doc of (Array.isArray(item.checklist) ? item.checklist : [])) if (doc.status !== 'accepted') uploads.append(driverUploadForm(item, doc));
      node.append(uploads);
      const submit = el('button', 'Submit current checklist for manual review', 'primary'); submit.type = 'button'; submit.addEventListener('click', () => void task(async () => { submit.disabled = true; try { await api('mobility/driver-onboarding/' + item.id + '/submit', 'POST', { expected_version: Number(item.version) }); notice('Checklist submitted for manual operator review.'); await loadDriverOnboarding(); } catch (error) { if (String(error.message).includes('VERSION_CONFLICT')) await loadDriverOnboarding(); throw error; } finally { submit.disabled = false; } })); node.append(submit);
    }
    return node;
  }
  async function loadDriverCars(sessionEpoch = driverSessionEpoch, sessionToken = token) {
    const target = $('driver-car'); if (!target || !driverSessionValid(sessionEpoch, sessionToken)) return;
    target.replaceChildren(el('option', 'Choose an owned complete vehicle')); target.options[0].value = '';
    const cars = list(await api('cars')); if (!driverSessionValid(sessionEpoch, sessionToken)) return;
    for (const car of cars) { const id = Number(car.id ?? car.car_id); if (!Number.isSafeInteger(id) || id < 1) continue; const option = el('option', String(car.brand || car.brand_other || 'Vehicle') + ' · ' + String(car.model || car.model_other || 'Owned vehicle')); option.value = String(id); target.append(option); }
    if (target.options.length === 1) target.append(el('option', 'Register a new vehicle below'));
  }
  async function loadDriverColors(sessionEpoch = driverSessionEpoch, sessionToken = token) { const target = $('driver-car-color'); if (!target || !driverSessionValid(sessionEpoch, sessionToken)) return; target.replaceChildren(el('option', 'Choose colour')); target.options[0].value = ''; try { const colors = list(await api('car-colors')); if (!driverSessionValid(sessionEpoch, sessionToken)) return; for (const color of colors) { const option = el('option', String(color.name || 'Colour')); option.value = String(color.id); target.append(option); } } catch (error) { if (driverSessionValid(sessionEpoch, sessionToken)) formNotice('driver-vehicle-status', 'Vehicle colour catalogue unavailable: ' + error.message, true); } }
  async function loadDriverOnboarding(sessionEpoch = driverSessionEpoch, sessionToken = token) {
    if (!token || !$('driver-onboarding-list') || !driverSessionValid(sessionEpoch, sessionToken)) return; const target = $('driver-onboarding-list'); target.replaceChildren(el('div', 'Loading your driver applications…', 'empty'));
    const rows = list(await api('mobility/driver-onboarding/me')); if (!driverSessionValid(sessionEpoch, sessionToken)) return; driverOnboardings = rows; target.replaceChildren(); if (!rows.length) target.append(el('div', 'No driver applications yet. Start with an owned complete vehicle below.', 'empty')); else for (const item of rows) target.append(driverCard(item));
    hidden($('driver-create-panel'), false);
  }
  function ensureDriverQueue() {
    const operator = $('operator'); const panel = $('driver-operator-panel'); if (!operator || !panel) return; if (panel.parentElement !== operator) operator.insertBefore(panel, operator.firstElementSibling?.nextElementSibling || operator.firstChild); hidden(panel, false); if (panel.dataset.bound === 'true') return; panel.dataset.bound = 'true'; $('refresh-driver-queue').addEventListener('click', () => void task(loadDriverQueue)); $('driver-queue-filter').addEventListener('change', (event) => { driverQueueFilter = event.currentTarget.value; void task(loadDriverQueue); }); $('driver-queue-more').addEventListener('click', () => void task(() => loadDriverQueue(driverSessionEpoch, token, true)));
  }
  function driverOperatorCard(item) {
    const vehicle = item.vehicle || {}; const vehicleLabel = [vehicle.brand, vehicle.model, vehicle.registration].filter(Boolean).join(' · ') || 'Owned vehicle'; const node = card('Driver application', String(item.profile_display_name || 'Applicant') + ' · ' + driverStatusLabel(item.status) + ' · version ' + String(item.version)); node.append(el('p', vehicleLabel + ' · ' + String(item.vehicle_type || 'vehicle') + (vehicle.complete === false ? ' · incomplete' : ''), 'subtle'), el('p', 'Phone: ' + String(item.profile_phone || 'Not provided'), 'subtle'), driverChecklist(item, true));
    const form = document.createElement('form'); form.className = 'form-grid driver-review-form'; const decision = document.createElement('select'); decision.name = 'status'; const currentStatus = String(item.status); const allowed = currentStatus === 'pending' || currentStatus === 'needs_resubmission' ? ['approved', 'needs_resubmission', 'rejected'] : currentStatus === 'approved' ? ['suspended'] : currentStatus === 'suspended' ? ['approved'] : []; for (const next of allowed) { const option = el('option', driverStatusLabel(next)); option.value = next; decision.append(option); } const documentType = document.createElement('select'); documentType.name = 'document_type'; const whole = el('option', 'Whole application'); whole.value = ''; documentType.append(whole); for (const doc of (Array.isArray(item.checklist) ? item.checklist : [])) if (doc.document_id) { const option = el('option', 'Only: ' + (driverLabels[doc.document_type] || doc.document_type)); option.value = doc.document_type; documentType.append(option); } const reason = document.createElement('textarea'); reason.name = 'reason'; reason.required = true; reason.maxLength = 500; reason.rows = 2; reason.placeholder = 'Required decision reason'; const submit = el('button', allowed.length ? 'Record manual decision' : 'No decision available', allowed.length ? 'primary' : 'secondary'); submit.type = 'submit'; submit.disabled = !allowed.length; const status = el('p', '', 'status wide'); form.append(labelWrap('Decision', decision), labelWrap('Document correction (optional)', documentType), labelWrap('Reason', reason), submit, status); form.addEventListener('submit', (event) => { event.preventDefault(); void task(async () => { submit.disabled = true; const values = Object.fromEntries(new FormData(form)); if (!values.document_type) delete values.document_type; else if (values.status === 'approved') values.status = 'needs_resubmission'; values.expected_version = Number(item.version); try { await api('mobility/operator/driver-onboarding/' + item.id + '/review', 'POST', values); status.textContent = 'Decision recorded. Queue reloaded.'; status.classList.remove('error'); await loadDriverQueue(); } catch (error) { if (String(error.message).includes('VERSION_CONFLICT')) await loadDriverQueue(); throw error; } finally { submit.disabled = false; } }); }); node.append(form); return node;
  }
  function labelWrap(text, control) { const label = document.createElement('label'); label.append(text, control); return label; }
  async function loadDriverQueue(sessionEpoch = driverSessionEpoch, sessionToken = token, append = false) { ensureDriverQueue(); if (!token || !identity?.is_admin || !driverSessionValid(sessionEpoch, sessionToken)) return; const target = $('driver-queue'); const more = $('driver-queue-more'); if (!append) { driverQueueAfter = null; target.replaceChildren(el('div', 'Loading driver review queue…', 'empty')); } const params = new URLSearchParams({ status: driverQueueFilter, limit: '50' }); if (append && driverQueueAfter !== null) params.set('after', String(driverQueueAfter)); const response = await api('mobility/operator/driver-onboarding?' + params.toString()); if (!driverSessionValid(sessionEpoch, sessionToken)) return; const rows = list(response); if (!append) target.replaceChildren(); if (!rows.length && !append) target.append(el('div', 'No driver applications match this filter.', 'empty')); else for (const item of rows) target.append(driverOperatorCard(item)); const next = Number(response?.next_after ?? response?.pagination?.next_after); driverQueueAfter = Number.isSafeInteger(next) && next >= 0 ? next : null; if (more) more.hidden = driverQueueAfter === null; }
  async function loadDriverSession() { const sessionEpoch = ++driverSessionEpoch; const sessionToken = token; try { await loadDriverCars(sessionEpoch, sessionToken); await loadDriverColors(sessionEpoch, sessionToken); await loadDriverOnboarding(sessionEpoch, sessionToken); if (identity?.is_admin) { ensureDriverQueue(); await loadDriverQueue(sessionEpoch, sessionToken); } } catch (error) { if (driverSessionValid(sessionEpoch, sessionToken)) notice('Driver onboarding is unavailable: ' + error.message, true); } }
  $('driver-create-form')?.addEventListener('submit', (event) => { event.preventDefault(); const form = event.currentTarget; void task(async () => { const values = Object.fromEntries(new FormData(form)); const result = await api('mobility/driver-onboarding', 'POST', values); formNotice('driver-create-status', 'Application started. Complete the private checklist below.'); form.reset(); await loadDriverOnboarding(); if (result?.onboarding_id) showView('driver'); }); });
  $('driver-vehicle-form')?.addEventListener('submit', (event) => { event.preventDefault(); const form = event.currentTarget; void task(async () => { const result = await api('cars', 'POST', Object.fromEntries(new FormData(form))); formNotice('driver-vehicle-status', 'Owned vehicle created. Select it in the application form.'); form.reset(); await loadDriverCars(); if (result?.id) $('driver-car').value = String(result.id); }); });
  $('refresh-driver')?.addEventListener('click', () => void task(async () => { await loadDriverCars(); await loadDriverOnboarding(); const response=await api('mobility/membership/me'); renderMembership(response); configureQuotedOffers(response); }));
  $('operator-tab')?.addEventListener('click', () => void task(loadDriverQueue));
  for (const button of document.querySelectorAll('.tab')) button.addEventListener('click', () => { if (button.dataset.view === 'driver') void task(loadDriverOnboarding); if (button.dataset.view === 'operator') void task(loadDriverQueue); });
  document.addEventListener('visibilitychange', () => { if (document.hidden) { stopDispatchPolling(); trackingResumeVisible(); } else { trackingResumeVisible(); if (dispatchIsActive()) void loadDispatch(); } });
  window.addEventListener('pagehide', () => { for (const close of driverPreviewClosers) close(); trackingBestEffortStop(); trackingStopAll(); stopDispatchPolling(); });
})();
