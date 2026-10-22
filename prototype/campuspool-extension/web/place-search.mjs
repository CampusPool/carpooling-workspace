const AREA = { low: { latitude: 12.65, longitude: 77.2 }, high: { latitude: 13.35, longitude: 77.95 } };
const esc = value => String(value ?? '').replace(/[&<>"']/g, char => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[char]));
export const inArea = point => Number.isFinite(point?.lat) && Number.isFinite(point?.lng) && point.lat >= 12.65 && point.lat <= 13.35 && point.lng >= 77.2 && point.lng <= 77.95;
let configPromise;
async function browserKey() {
  if (!configPromise) configPromise = fetch('/mobility-pilot/maps-config', { cache: 'no-store', signal: AbortSignal.timeout(7000) }).then(async response => {
    const config = await response.json();
    if (!response.ok || !config.browser_key) throw new Error('Place search is unavailable.');
    return config.browser_key;
  }).catch(error => { configPromise = null; throw error; });
  return configPromise;
}
async function places(path, { body, fields, signal } = {}) {
  const key = await browserKey();
  const response = await fetch(`https://places.googleapis.com/v1/${path}`, {
    method: body ? 'POST' : 'GET', credentials: 'omit', cache: 'no-store', signal,
    headers: { 'X-Goog-Api-Key': key, 'X-Goog-FieldMask': fields, ...(body ? { 'Content-Type': 'application/json' } : {}) },
    ...(body ? { body: JSON.stringify(body) } : {}),
  });
  const data = await response.json();
  if (!response.ok) throw new Error(data.error?.status === 'RESOURCE_EXHAUSTED' ? 'Place search has reached the demo limit. Please try again later.' : 'Place search is unavailable. Please try again.');
  return data;
}
export async function suggestions(input, sessionToken, signal) {
  const data = await places('places:autocomplete', {
    body: { input, sessionToken, includedRegionCodes: ['in'], locationRestriction: { rectangle: AREA }, languageCode: 'en', regionCode: 'IN' },
    fields: 'suggestions.placePrediction.placeId,suggestions.placePrediction.text,suggestions.placePrediction.structuredFormat', signal,
  });
  return (data.suggestions || []).map(row => row.placePrediction).filter(row => row?.placeId && row.text?.text);
}
export async function resolvePlace(prediction, sessionToken, signal) {
  const data = await places(`places/${encodeURIComponent(prediction.placeId)}?sessionToken=${encodeURIComponent(sessionToken)}`, { fields: 'location', signal });
  const point = { lat: data.location?.latitude, lng: data.location?.longitude, label: prediction.text.text.slice(0, 160) };
  if (!inArea(point)) throw new Error('Choose a location within Bengaluru.');
  return point;
}
export function locationFields(points, names, labels) {
  return names.map((name, index) => `<div class="place-field" data-place-field="${name}"><label class="floating-field"><span>${labels[index]}</span><input name="${name}_label" value="${esc(points[name].label)}" required maxlength="160" autocomplete="off" role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="${name}-suggestions" aria-describedby="${name}-place-status" placeholder="Search an area, landmark or address"></label><div class="place-suggestions hidden" id="${name}-suggestions" role="listbox" aria-label="${labels[index]} suggestions"></div><p class="place-status" id="${name}-place-status" role="status"></p><input name="${name}_lat" type="hidden" value="${points[name].lat}"><input name="${name}_lng" type="hidden" value="${points[name].lng}"></div>`).join('');
}
export function mountLocationFields(root, { names, isCurrent = () => true, onSelect = () => {}, provider = { suggestions, resolvePlace }, debounce = 300 }) {
  const disposers = [];
  for (const name of names) {
    const input = root.querySelector(`[name="${name}_label"]`), lat = root.querySelector(`[name="${name}_lat"]`), lng = root.querySelector(`[name="${name}_lng"]`);
    const list = root.querySelector(`#${name}-suggestions`), note = root.querySelector(`#${name}-place-status`);
    const lifetime = new AbortController();
    const listen = (element, event, handler) => element.addEventListener(event, handler, { signal: lifetime.signal });
    const initial = { lat: Number(lat.value), lng: Number(lng.value) };
    input.setCustomValidity(lat.value !== '' && lng.value !== '' && inArea(initial) ? '' : 'Choose a place from the suggestions to confirm its location.');
    let version = 0, timer, controller, rows = [], active = -1, token, disposed = false;
    const current = value => !disposed && isCurrent() && value === version;
    const hide = () => { list.replaceChildren(); list.classList.add('hidden'); input.setAttribute('aria-expanded', 'false'); input.removeAttribute('aria-activedescendant'); rows = []; active = -1; };
    const cancel = () => { version++; clearTimeout(timer); controller?.abort(); hide(); };
    const invalidate = () => { lat.value = ''; lng.value = ''; input.setCustomValidity('Choose a place from the suggestions to confirm its location.'); };
    const choose = async index => {
      const row = rows[index]; if (!row) return;
      cancel(); const request = version; controller = new AbortController();
      note.textContent = 'Selecting place…';
      try {
        const point = await provider.resolvePlace(row, token, AbortSignal.any([controller.signal, AbortSignal.timeout(8000)]));
        if (!current(request)) return;
        input.value = point.label; lat.value = point.lat; lng.value = point.lng; input.setCustomValidity('');
        note.textContent = 'Location selected'; token = null; onSelect(name, point);
      } catch (error) { if (current(request)) note.textContent = error.name === 'TimeoutError' ? 'Place lookup timed out. Please select again.' : error.message; }
    };
    const markActive = () => {
      [...list.querySelectorAll('[role="option"]')].forEach((option, index) => { option.classList.toggle('highlighted', index === active); option.setAttribute('aria-selected', String(index === active)); });
      if (active >= 0) input.setAttribute('aria-activedescendant', `${name}-suggestion-${active}`);
    };
    const onInput = () => {
      cancel(); invalidate(); const query = input.value.trim(); note.textContent = '';
      if (query.length < 2) { note.textContent = 'Type at least two letters to find a place.'; return; }
      const request = version; token ||= crypto.randomUUID(); note.textContent = 'Searching places…';
      timer = setTimeout(async () => {
        controller = new AbortController();
        try {
          const found = await provider.suggestions(query, token, AbortSignal.any([controller.signal, AbortSignal.timeout(8000)]));
          if (!current(request)) return;
          rows = found; active = -1;
          list.innerHTML = rows.map((row, index) => `<button type="button" role="option" id="${name}-suggestion-${index}" data-place-index="${index}" aria-selected="false" tabindex="-1"><span class="suggestion-pin" aria-hidden="true">⌖</span><span><strong>${esc(row.structuredFormat?.mainText?.text || row.text.text)}</strong><small>${esc(row.structuredFormat?.secondaryText?.text || '')}</small></span></button>`).join('') + (rows.length ? '<span class="places-attribution">Google Maps</span>' : '');
          list.classList.toggle('hidden', !rows.length); input.setAttribute('aria-expanded', String(Boolean(rows.length)));
          note.textContent = rows.length ? `${rows.length} places found. Select one.` : 'No places found. Try a nearby landmark or a more specific address.';
          list.querySelectorAll('[data-place-index]').forEach(button => button.addEventListener('click', () => void choose(Number(button.dataset.placeIndex))));
        } catch (error) { if (current(request)) note.textContent = error.name === 'TimeoutError' ? 'Place search timed out. Try again.' : error.message; }
      }, debounce);
    };
    listen(input, 'input', onInput);
    listen(input, 'focus', () => { if (input.validationMessage && input.value.trim().length >= 2) onInput(); });
    listen(input, 'keydown', event => {
      if (event.key === 'Escape' && rows.length) { event.preventDefault(); event.stopPropagation(); cancel(); note.textContent = ''; }
      else if (['ArrowDown', 'ArrowUp'].includes(event.key) && rows.length) { event.preventDefault(); active = event.key === 'ArrowDown' ? (active + 1) % rows.length : (active + rows.length - 1) % rows.length; markActive(); }
      else if (event.key === 'Enter' && rows.length) { event.preventDefault(); if (active >= 0) void choose(active); }
    });
    listen(list, 'pointerdown', event => event.preventDefault());
    listen(input, 'blur', () => { cancel(); note.textContent = ''; });
    root.querySelectorAll('[data-coordinate]').forEach(field => {
      if (!field.dataset.coordinate.startsWith(`${name}_`)) return;
      listen(field, 'input', () => {
        cancel();
        const point = { lat: Number(lat.value), lng: Number(lng.value), label: input.value.trim() };
        const valid = lat.value !== '' && lng.value !== '' && inArea(point);
        input.setCustomValidity(valid ? '' : 'Enter valid Bengaluru coordinates for this location.');
        note.textContent = valid ? 'Coordinates entered manually' : 'Enter both latitude and longitude.';
      });
    });
    disposers.push(() => { disposed = true; lifetime.abort(); cancel(); note.textContent = ''; });
  }
  return { dispose() { disposers.forEach(dispose => dispose()); } };
}
