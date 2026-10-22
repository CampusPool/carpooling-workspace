import test from 'node:test';
import assert from 'node:assert/strict';
import { mountLocationFields, suggestions, resolvePlace } from '../../campuspool-extension/web/place-search.mjs';

const row = { placeId: 'public-place', text: { text: 'Public landmark, Bengaluru' }, structuredFormat: { mainText: { text: 'Public landmark' }, secondaryText: { text: 'Bengaluru' } } };
const point = { label: row.text.text, lat: 12.95, lng: 77.6 };
class Element {
  constructor(value = '') { this.value = value; this.attrs = {}; this.listeners = {}; this.dataset = {}; this.options = []; this.textContent = ''; this.classList = { add(){}, toggle(){} }; }
  addEventListener(name, callback, options = {}) { (this.listeners[name] ||= []).push({ callback, signal: options.signal }); }
  dispatch(name, event = {}) { for (const entry of this.listeners[name] || []) if (!entry.signal?.aborted) entry.callback({ preventDefault(){}, stopPropagation(){}, ...event }); }
  setAttribute(name, value) { this.attrs[name] = value; }
  removeAttribute(name) { delete this.attrs[name]; }
  setCustomValidity(message) { this.validationMessage = message; }
  replaceChildren() { this.options = []; }
  set innerHTML(html) { this.options = [...html.matchAll(/data-place-index="(\d+)"/g)].map(([, index]) => { const el = new Element(); el.dataset.placeIndex = index; return el; }); }
  querySelectorAll() { return this.options; }
}
function mount(provider) {
  const input = new Element('Old selected place'), lat = new Element('12.9'), lng = new Element('77.5'), list = new Element(), note = new Element();
  const root = { querySelector: selector => ({ '[name="home_label"]': input, '[name="home_lat"]': lat, '[name="home_lng"]': lng, '#home-suggestions': list, '#home-place-status': note })[selector], querySelectorAll: () => [] };
  const ui = mountLocationFields(root, { names: ['home'], provider, debounce: 0 });
  return { input, lat, lng, list, note, ui };
}
const tick = () => new Promise(resolve => setTimeout(resolve, 5));
const deferred = () => { let resolve; const promise = new Promise(done => { resolve = done; }); return { promise, resolve }; };

test('typing invalidates old coordinates; selection resolves and fills both coordinates', async () => {
  const el = mount({ suggestions: async () => [row], resolvePlace: async () => point });
  el.input.value = 'public'; el.input.dispatch('input');
  assert.equal(el.lat.value, ''); assert.equal(el.lng.value, ''); assert.match(el.input.validationMessage, /Choose a place/);
  await tick(); assert.equal(el.list.options.length, 1);
  el.input.dispatch('keydown', { key: 'ArrowDown' }); el.input.dispatch('keydown', { key: 'Enter' }); await tick();
  assert.equal(el.input.value, point.label); assert.equal(el.lat.value, point.lat); assert.equal(el.lng.value, point.lng); assert.equal(el.input.validationMessage, ''); el.ui.dispose();
});

test('older suggestions cannot replace results for newer text', async () => {
  const first = deferred(), second = deferred();
  const el = mount({ suggestions: query => query === 'old' ? first.promise : second.promise });
  el.input.value = 'old'; el.input.dispatch('input'); await tick();
  el.input.value = 'new'; el.input.dispatch('input'); await tick();
  second.resolve([row]); await tick(); first.resolve([]); await tick();
  assert.equal(el.list.options.length, 1); assert.match(el.note.textContent, /1 places/); el.ui.dispose();
});

test('editing during place resolution prevents stale coordinates from being restored', async () => {
  const selected = deferred(); const el = mount({ suggestions: async () => [row], resolvePlace: () => selected.promise });
  el.input.value = 'public'; el.input.dispatch('input'); await tick(); el.list.options[0].dispatch('click');
  el.input.value = 'different'; el.input.dispatch('input'); selected.resolve(point); await tick();
  assert.equal(el.lat.value, ''); assert.equal(el.lng.value, ''); assert.equal(el.input.value, 'different'); el.ui.dispose();
});

test('disposed field cannot apply a late response and no duplicate listeners remain', async () => {
  const response = deferred(); const el = mount({ suggestions: () => response.promise });
  el.input.value = 'public'; el.input.dispatch('input'); await tick(); el.ui.dispose(); response.resolve([row]); await tick();
  assert.equal(el.list.options.length, 0); assert.equal(el.note.textContent, '');
});

test('provider errors remain visible and cannot retain old selected coordinates', async () => {
  const el = mount({ suggestions: async () => { throw Error('Demo quota reached'); } });
  el.input.value = 'public'; el.input.dispatch('input'); await tick();
  assert.equal(el.note.textContent, 'Demo quota reached'); assert.equal(el.lat.value, ''); assert.notEqual(el.input.validationMessage, ''); el.ui.dispose();
});

test('Places requests restrict Bengaluru and omit app credentials; selection shares session token', async () => {
  const calls = [];
  globalThis.fetch = async (url, options = {}) => {
    calls.push({ url, options });
    return { ok: true, json: async () => url.includes('maps-config') ? { browser_key: 'test-only-key' } : url.includes('autocomplete') ? { suggestions: [{ placePrediction: row }] } : { location: { latitude: point.lat, longitude: point.lng } } };
  };
  assert.equal((await suggestions('public', 'same-session'))[0].placeId, row.placeId);
  assert.deepEqual(await resolvePlace(row, 'same-session'), point);
  const external = calls.filter(call => call.url.startsWith('https:'));
  for (const { options } of external) { assert.equal(options.credentials, 'omit'); assert.equal(options.headers.Authorization, undefined); }
  const body = JSON.parse(external[0].options.body); assert.equal(body.sessionToken, 'same-session'); assert.deepEqual(body.includedRegionCodes, ['in']); assert.equal(body.locationRestriction.rectangle.low.latitude, 12.65);
  assert.match(external[1].url, /sessionToken=same-session/);
});
