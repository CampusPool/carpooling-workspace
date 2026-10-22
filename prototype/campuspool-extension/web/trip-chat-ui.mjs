const MAX_MESSAGES = 200;
const PAGE_SIZE = 50;
const MAX_KEYS = 32;

export function createTripChatState(tripId) {
  return { tripId: String(tripId), open: false, visible: false, messages: [], participants: [], nextAfter: 0, hasOlder: false, canSend: false, blocked: false, unreadCount: 0, generation: 0, historyMode: false };
}

export function mergeTripMessages(existing, incoming, limit = MAX_MESSAGES, keepOldest = false) {
  const byId = new Map();
  for (const message of [...(existing || []), ...(incoming || [])]) {
    const id = String(message?.id ?? '');
    if (id) byId.set(id, message);
  }
  const sorted = [...byId.values()].sort((a, b) => Number(a.id) - Number(b.id));
  return keepOldest ? sorted.slice(0, limit) : sorted.slice(-limit);
}

export function messageCursor(data) {
  const value = data?.next_after ?? data?.next_cursor ?? data?.nextAfter ?? null;
  return value === null || value === '' || value === undefined ? null : Number(value);
}

const node = (tag, value, className) => {
  const item = document.createElement(tag);
  if (value !== undefined) item.textContent = String(value);
  if (className) item.className = className;
  return item;
};

const keyValue = () => globalThis.crypto?.randomUUID ? `trip-chat-${globalThis.crypto.randomUUID()}` : `trip-chat-${Date.now()}-${Math.random().toString(36).slice(2)}`;

export function mountTripChat(target, { tripId, api, getActorId = () => null, getSessionGeneration = () => 0, onNotificationChange = () => {} } = {}) {
  if (!target || typeof api !== 'function' || tripId === undefined || tripId === null) throw new TypeError('mountTripChat requires target, tripId and api.');
  const state = createTripChatState(tripId);
  const keys = new Map();
  const listeners = [];
  let disposed = false;
  let pollTimer = null;
  let pollSession = null;
  let loading = false;
  let readInFlight = false;
  let sendInFlight = false;
  let blockGeneration = 0;
  const root = node('section', undefined, 'trip-chat');
  const toggle = node('button', 'Show trip chat', 'trip-chat-toggle'); toggle.type = 'button';
  const panel = node('div', undefined, 'trip-chat-panel'); panel.hidden = true;
  const status = node('p', '', 'trip-chat-status'); status.setAttribute('role', 'status'); status.setAttribute('aria-live', 'polite');
  const messages = node('ol', undefined, 'trip-chat-messages');
  const participants = node('ul', undefined, 'trip-chat-participants');
  const older = node('button', 'Load earlier messages', 'trip-chat-older'); older.type = 'button'; older.hidden = true;
  const refresh = node('button', 'Refresh', 'trip-chat-refresh'); refresh.type = 'button';
  const composer = node('form', undefined, 'trip-chat-composer');
  const input = node('textarea'); input.maxLength = 800; input.required = true; input.rows = 2; input.placeholder = 'Write a message'; input.setAttribute('aria-label', 'Trip message');
  const send = node('button', 'Send', 'trip-chat-send'); send.type = 'submit';
  composer.append(input, send);
  panel.append(status, participants, older, messages, refresh, composer);
  root.append(toggle, panel); target.replaceChildren(root);
  const on = (item, event, handler) => { item.addEventListener(event, handler); listeners.push(() => item.removeEventListener(event, handler)); };
  const setStatus = (text, error = false) => { status.textContent = text; status.dataset.error = error ? 'true' : 'false'; };
  const current = token => !disposed && token === `${getSessionGeneration()}:${state.generation}`;
  const chatPath = suffix => `/trips/${encodeURIComponent(String(tripId))}/messages${suffix || ''}`;
  const keyFor = fingerprint => {
    if (keys.has(fingerprint)) return keys.get(fingerprint);
    const value = keyValue(); keys.set(fingerprint, value);
    while (keys.size > MAX_KEYS) keys.delete(keys.keys().next().value);
    return value;
  };
  const request = (path, method = 'GET', body, headers = {}) => api(path, method, body, headers);
  const responseData = response => response?.data ?? response ?? {};
  const errorText = error => {
    const statusCode = Number(error?.status ?? error?.response?.status);
    const code = error?.code ?? error?.response?.data?.error?.code ?? error?.data?.error?.code;
    if (statusCode === 403 || code === 'CHAT_FORBIDDEN' || code === 'MEMBERSHIP_REQUIRED') return 'Trip chat is available to accepted participants only.';
    if (statusCode === 409 && ['TRIP_TERMINAL', 'TRIP_CHAT_CLOSED'].includes(code)) return 'This trip has ended. You can read its history, but cannot send new messages.';
    return error?.message || 'Trip chat is temporarily unavailable.';
  };
  const render = () => {
    if (disposed) return;
    messages.replaceChildren();
    for (const message of state.messages) {
      const item = node('li'); item.dataset.messageId = String(message.id);
      item.append(node('strong', message.user_id === getActorId() ? 'You' : `Participant ${message.user_id}`), node('p', message.text), node('time', message.created_at || ''));
      messages.append(item);
    }
    participants.replaceChildren();
    for (const participant of state.participants) {
      const item = node('li'); item.append(node('span', participant.name || `Participant ${participant.id}`));
      if (Number(participant.id) !== Number(getActorId())) {
        const blocked = Boolean(participant.blocked_by_you);
        const button = node('button', blocked ? 'Unblock' : 'Block'); button.type = 'button';
        button.addEventListener('click', () => void changeBlock(participant.id, !blocked)); item.append(button);
      }
      participants.append(item);
    }
    older.hidden = !state.hasOlder;
    composer.hidden = !state.canSend || state.blocked;
    send.disabled = sendInFlight || !state.canSend || state.blocked;
    toggle.textContent = state.open ? 'Close trip chat' : 'Show trip chat';
    refresh.disabled = loading;
  };
  async function markRead(token) {
    if (!state.open || !state.visible || !state.messages.length || readInFlight || !current(token)) return;
    const throughId = Math.max(...state.messages.map(item => Number(item.id)).filter(Number.isFinite));
    if (!throughId) return;
    readInFlight = true;
    try { await request(chatPath('/read'), 'POST', { through_id: throughId }, { 'Idempotency-Key': keyFor(`read:${throughId}`) }); if (current(token)) onNotificationChange(); }
    catch (error) { if (current(token)) setStatus(errorText(error), true); }
    finally { readInFlight = false; }
  }
  async function load({ earlier = false } = {}) {
    if (!state.open || loading) return;
    const token = `${getSessionGeneration()}:${state.generation}`; loading = true; render();
    if (state.historyMode && !earlier) { state.messages = []; state.historyMode = false; }
    const firstLoad = !state.messages.length;
    const cursorQuery = earlier ? '?before=' + state.messages[0].id : firstLoad ? '' : '?after=' + state.messages.at(-1).id;
    try {
      const response = await request(`${chatPath()}${cursorQuery}`);
      if (!current(token)) return;
      const data = responseData(response);
      state.messages = mergeTripMessages(state.messages, data.messages || [], MAX_MESSAGES, earlier);
      if (earlier) state.historyMode = true;
      state.participants = Array.isArray(data.participants) ? data.participants : state.participants;
      state.canSend = Boolean(data.can_send); state.blocked = Boolean(data.blocked); state.unreadCount = Number(data.unread_count || 0);
      const cursor = messageCursor(data); state.nextAfter = cursor ?? (state.messages.at(-1)?.id || 0); if (firstLoad || earlier) state.hasOlder = Number(data.older_before || 0) > 0;
      setStatus(state.blocked ? 'A participant block pauses messages for this group. History and operator reporting remain available.' : state.historyMode ? 'Earlier messages. Refresh to return to the latest messages.' : state.canSend ? 'Trip chat loaded.' : 'Trip chat is read-only.');
      render(); await markRead(token);
    } catch (error) { if (current(token)) { setStatus(errorText(error), true); render(); } }
    finally { loading = false; render(); }
  }
  function startPolling() {
    if (pollTimer || !state.open || !state.visible) return;
    pollSession = getSessionGeneration();
    pollTimer = setInterval(() => {
      if (getSessionGeneration() !== pollSession) { stopPolling(); return; }
      if (!loading && !state.historyMode) void load();
    }, 5000);
  }
  function stopPolling() { if (pollTimer) { clearInterval(pollTimer); pollTimer = null; } pollSession = null; }
  async function changeBlock(targetUserId, blocked) {
    const change = ++blockGeneration; const token = `${getSessionGeneration()}:${state.generation}`;
    try {
      const response = await request(`/trips/${encodeURIComponent(String(tripId))}/blocks`, 'POST', { target_user_id: Number(targetUserId), blocked: Boolean(blocked) }, { 'Idempotency-Key': keyFor(`block:${targetUserId}:${blocked}`) });
      if (!current(token) || change !== blockGeneration) return;
      keys.delete(`block:${targetUserId}:${blocked}`);
      state.canSend = false;
      const participant = state.participants.find(p => Number(p.id) === Number(targetUserId)); if (participant) participant.blocked_by_you = Boolean(blocked);
      if (blocked) state.blocked = true;
      setStatus(state.blocked ? 'Group messages paused. Accepted ride obligations remain; contact the operator for recovery.' : 'Messaging unblocked.'); render();
      void load();
    } catch (error) { if (current(token) && change === blockGeneration) setStatus(errorText(error), true); }
  }
  async function sendMessage() {
    const text = input.value.trim(); if (!text || text.length > 800 || sendInFlight || !state.canSend || state.blocked) return;
    const token = `${getSessionGeneration()}:${state.generation}`; const key = keyFor(`message:${text}`); sendInFlight = true; render();
    try {
      const response = await request(chatPath(), 'POST', { text }, { 'Idempotency-Key': key });
      if (!current(token)) return;
      keys.delete(`message:${text}`);
      const data = responseData(response); const message = data.message || data;
      state.messages = mergeTripMessages(state.messages, [message]); input.value = ''; if (state.historyMode) { await load(); if (!current(token)) return; } setStatus('Message sent.'); render(); await markRead(token);
    } catch (error) { if (current(token)) setStatus(errorText(error), true); }
    finally { sendInFlight = false; render(); }
  }
  on(toggle, 'click', () => { state.open = !state.open; state.visible = state.open && !document.hidden; panel.hidden = !state.open; state.generation += 1; if (state.open) { setStatus('Loading trip chat…'); void load(); startPolling(); } else stopPolling(); render(); });
  on(refresh, 'click', () => void load());
  on(older, 'click', () => void load({ earlier: true }));
  on(composer, 'submit', event => { event.preventDefault(); void sendMessage(); });
  on(document, 'visibilitychange', () => { state.visible = state.open && !document.hidden; if (state.visible) { void load(); startPolling(); } else stopPolling(); });
  render();
  return { state, refresh: () => load(), dispose: () => { disposed = true; state.generation += 1; stopPolling(); state.messages = []; state.participants = []; input.value = ''; keys.clear(); listeners.splice(0).forEach(remove => remove()); target.replaceChildren(); } };
}
