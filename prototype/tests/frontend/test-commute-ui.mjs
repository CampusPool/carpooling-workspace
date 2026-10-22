import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';

const html = await readFile(new URL('../../campuspool-extension/web/commute.html', import.meta.url), 'utf8');
const css = await readFile(new URL('../../campuspool-extension/web/commute.css', import.meta.url), 'utf8');
const js = await readFile(new URL('../../campuspool-extension/web/commute.mjs', import.meta.url), 'utf8');

test('commute page is self contained and contract shaped', () => {
  assert.match(html, /id="auth-form"/); assert.match(html, /id="otp-form"/);
  for (const id of ['view-home','view-rides','view-wallet','view-settings','bottom-nav']) assert.match(html, new RegExp(`id="${id}"|class="[^"]*${id}[^"]*"`));
  assert.match(html, /autocomplete="username"/); assert.match(html, /maxlength="6"/); assert.match(css, /@media/);
  for (const endpoint of ['commute/demo-session','commute/profile','commute/rides','commute/bookings','commute/wallet']) assert.match(js, new RegExp(endpoint));
  assert.match(js, /Idempotency-Key/); assert.match(js, /crypto\.randomUUID\(\)/); assert.doesNotMatch(js, /localStorage|sessionStorage|document\.cookie/);
});

test('role navigation and rider/driver contract copy are visible', () => {
  for (const nav of ['home','chats','settings']) assert.match(html, new RegExp(`data-nav="${nav}"`));
  assert.match(html, /id="wallet-button"/); assert.match(html, /id="activity-button"/);
  assert.match(js, /state\.role === 'driver'/); assert.match(js, /data-role-switch/); assert.match(js, /driver_approved/);
  assert.match(js, /pickup_label/); assert.match(js, /dropoff_label/); assert.match(js, /base_fare_minor/); assert.match(js, /total_fare_minor/); assert.match(js, /pending/);
  assert.match(js, /Demo credits · no real money/); assert.match(js, /Local preview only/); assert.match(css, /\.flow-dialog/); assert.match(css, /\.hero-car/);
});

console.log('PASS: commute UI checks');

// A deliberately small DOM harness keeps these checks mounted without adding a browser dependency.
class FakeElement {
  constructor(tag = 'div', id = '') { this.tagName = tag.toUpperCase(); this.id = id; this.children = []; this.parentElement = null; this.listeners = {}; this.dataset = {}; this.style = {}; this.classList = { add(){}, remove(){}, toggle(){} }; this.value = ''; this.checked = false; this.disabled = false; this.textContent = ''; this.attributes = {}; }
  set innerHTML(value) { this._html = String(value); this.children = []; const stack=[this]; for (const match of this._html.matchAll(/<\/?([a-z]+)\b([^>]*)>/gi)) { const [, tag, attrs=''] = match; if (match[0].startsWith('</')) { if (stack.length>1) stack.pop(); continue; } const id=attrs.match(/\bid="([^"]+)"/i)?.[1]||''; const child=new FakeElement(tag,id); for (const attr of attrs.matchAll(/([\w-]+)(?:="([^"]*)")?/g)) { const [,key,val='']=attr; child.attributes[key]=val; if(key==='name')child.name=val; if(key==='value')child.value=val; if(key==='type')child.type=val; if(key==='checked')child.checked=true; if(key==='disabled')child.disabled=true; if(key.startsWith('data-'))child.dataset[key.slice(5).replace(/-([a-z])/g,(_,c)=>c.toUpperCase())]=val; } child.parentElement=stack.at(-1); stack.at(-1).children.push(child); if(!['input','img','br','hr','meta','link'].includes(tag.toLowerCase()))stack.push(child); } }
  get innerHTML() { return this._html || ''; }
  append(...nodes) { for (const node of nodes) { node.parentElement = this; this.children.push(node); } }
  replaceChildren(...nodes) { this.children = []; this.append(...nodes); }
  addEventListener(type, fn) { (this.listeners[type] ||= []).push(fn); }
  dispatch(type, event = {}) { const payload={ target: this, currentTarget: this, preventDefault(){}, ...event }; for (const fn of this.listeners[type] || []) fn(payload); if (typeof this[`on${type}`] === 'function') this[`on${type}`](payload); }
  click() { this.dispatch('click'); }
  matches(selector) {
    if (selector.includes(',')) return selector.split(',').some(part=>this.matches(part.trim()));
    if (selector.endsWith(':checked')) return this.checked && this.matches(selector.slice(0,-8));
    const tag=selector.match(/^[a-z]+/i)?.[0]; if(tag && this.tagName!==tag.toUpperCase())return false;
    if (selector.startsWith('#')) return this.id===selector.slice(1);
    if (selector.startsWith('.')) return (this.attributes.class||'').split(/\s+/).includes(selector.slice(1));
    const attrs=[...selector.matchAll(/\[([\w-]+)(?:="([^"]*)")?\]/g)];
    return attrs.length ? attrs.every(([,key,value])=>key in this.attributes && (value===undefined || this.attributes[key]===value)) : Boolean(tag);
  }
  get elements() { return new Proxy({}, {get:(_,name)=>this.querySelector(`[name="${String(name)}"]`)}); }
  reportValidity() { return true; }
  setCustomValidity(message) { this.validationMessage = message; }
  removeAttribute(name) { delete this.attributes[name]; }
  querySelector(selector) { return this.querySelectorAll(selector)[0] || null; }
  querySelectorAll(selector) { const found = []; const visit = node => { for (const child of node.children) { if (child.matches(selector)) found.push(child); visit(child); } }; visit(this); return found; }
  closest(selector) { let node=this; while(node){ if(node.matches(selector))return node; node=node.parentElement; } return null; }
  remove() { if (this.parentElement) this.parentElement.children = this.parentElement.children.filter(child => child !== this); }
  after(node) { if (this.parentElement) { const at = this.parentElement.children.indexOf(this); node.parentElement = this.parentElement; this.parentElement.children.splice(at + 1, 0, node); } }
  setAttribute(name, value) { this.attributes[name] = value; }
}

function makeDocument() {
  const ids = ['sheet-root','mode-label','wallet-button','activity-button','activity-count','view-chats','app-toast','auth-screen','product-screen','demo-banner','auth-form','otp-form','college-email','otp-code','otp-recipient','change-email','resend-code','demo-actions','auth-status','page-title','avatar-text','profile-button','view-home','view-rides','view-wallet','view-settings'];
  const elements = new Map(ids.map(id => [id, new FakeElement(id.endsWith('form') ? 'form' : 'div', id)]));
  elements.get('auth-form').innerHTML = '<input name="email" value="rider@stu.pes.edu">';
  const doc = { hidden: false, listeners: {}, createElement: tag => new FakeElement(tag), getElementById: id => elements.get(id) || [...elements.values()].flatMap(root => root.querySelectorAll(`#${id}`))[0] || null, addEventListener(type, fn) { (this.listeners[type] ||= []).push(fn); }, querySelector(selector) { return this.querySelectorAll(selector)[0] || null; }, querySelectorAll(selector) { const roots = [...elements.values()]; return roots.flatMap(root => root.matches(selector) ? [root, ...root.querySelectorAll(selector)] : root.querySelectorAll(selector)); } };
  globalThis.FormData = class {
    constructor(form) { this.form=form; }
    get(name) { const fields=this.form.querySelectorAll(`[name="${name}"]`); return (fields.find(field=>field.checked)||fields[0])?.value ?? null; }
    has(name) { return Boolean(this.form.querySelector(`[name="${name}"]`)); }
  };
  return { doc, elements };
}

test('mounted rider search updates direction and sends requested time', async () => {
  const { doc, elements } = makeDocument(); globalThis.document = doc; globalThis.window = { addEventListener(){} }; globalThis.crypto.randomUUID = () => 'key-rider';
  const calls = []; globalThis.fetch = async (url, options = {}) => { calls.push({url, options}); if (url.endsWith('commute/profile')) return {ok:true, json:async()=>({profile:{...stateFixture.profile, role:'rider', onboarding_complete:true}})}; if (url.includes('commute/rides')) return {ok:true, json:async()=>({data:[]})}; if (url.endsWith('commute/bookings')) return {ok:true, json:async()=>({data:[]})}; if (url.endsWith('college-auth/config')) return {ok:true,json:async()=>({})}; return {ok:true,json:async()=>({})}; };

  const module = await import(`../../campuspool-extension/web/commute.mjs?mounted=${Date.now()}`); Object.assign(module.state, {token:'rider-token', profile:stateFixture.profile, user:{name:'Rider'}, view:'home', role:'rider'}); await module.render();
  module.openRide(); const form = elements.get('sheet-root').querySelector('#search-form'); assert.ok(form, 'mounted search form exists'); const radios = form.querySelectorAll('input').filter(input => input.name === 'direction'); assert.equal(radios.length, 2, 'mounted direction controls exist'); assert.equal(form.querySelectorAll('input[name="direction"]').length,2,'selector finds mounted direction controls'); const returning = radios[1]; returning.checked = true; returning.dispatch('change'); assert.equal(module.state.direction,'return','direction control updates mounted state');
  const updated = elements.get('sheet-root').querySelector('#search-form'); updated.querySelector('input[name="time"]').value='18:10'; updated.elements.pickup_choice.value='ON_ROUTE_MEETING_POINT'; updated.elements.max_walk_meters.value='500'; updated.querySelector('input[name="date"]').value='2026-10-23'; updated.querySelectorAll('input[name="vehicle_type"]').forEach(input=>input.checked=input.value==='car'); updated.dispatch('submit'); await new Promise(resolve => setImmediate(resolve));
  const search = calls.find(call => call.url.includes('commute/rides')); assert.match(search.url, /direction=return/); assert.equal(new URL(search.url,'http://test').searchParams.get('from_time'), null, 'GET rides derives schedule from the preceding PUT profile contract'); const profilePut=calls.find(call=>call.url.endsWith('commute/profile')); assert.match(profilePut.options.body,/18:10/); assert.equal(module.state.direction, 'return'); assert.equal(new URL(search.url,'http://test').searchParams.get('pickup_choice'),'ON_ROUTE_MEETING_POINT'); assert.equal(JSON.parse(profilePut.options.body).max_walk_meters,500);
});

test('mounted ride detail requires exact fare consent and retries with the same key', async () => {
  const { doc, elements } = makeDocument(); globalThis.document=doc; globalThis.window={addEventListener(){}}; globalThis.fetch=async (url, options={})=>{ if(url.includes('/request')||url.includes('/pay')){ requestKeys.push(options.headers['Idempotency-Key']); if(requestKeys.length===1) throw Error('temporary network loss'); return {ok:true,json:async()=>({data:{booking_id:'b1',request_state:1,wallet_state:'held'}})}; } if(url.endsWith('commute/bookings'))return {ok:true,json:async()=>({data:[]})}; if(url.endsWith('college-auth/config'))return {ok:true,json:async()=>({})}; return {ok:true,json:async()=>({profile:stateFixture.profile})}; }; const requestKeys=[];
  const module=await import(`../../campuspool-extension/web/commute.mjs?detail=${Date.now()}`); Object.assign(module.state,{token:'rider-token',profile:stateFixture.profile,user:{name:'Rider'},role:'rider',view:'rides',rides:[{trip_id:'trip-1',driver_name:'Synthetic Driver',pickup_label:'Home',dropoff_label:'Campus',departure_at:'2026-10-23T08:30:00+05:30',access_meters:100,egress_meters:80,ride_meters:2200,base_fare_minor:1000,surcharge_minor:200,total_fare_minor:1200,quote_token:'quote-1',demo_fixture:true}]}); await module.render(); const rideButton=elements.get('view-rides').querySelector('.request-ride'); assert.ok(rideButton,'mounted ride card exists'); rideButton.click(); const detail=elements.get('sheet-root').querySelector('#ride-detail'); assert.ok(detail,'mounted detail card opens from See details'); const confirm=detail.querySelector('#request-confirm'); const consent=detail.querySelector('#fare-consent'); assert.equal(confirm.disabled,true,'request is disabled before exact fare consent'); let requestCount=0; consent.checked=true; consent.dispatch('change'); assert.equal(confirm.disabled,false); confirm.click(); await new Promise(resolve=>setImmediate(resolve)); requestCount++; assert.equal(requestKeys.length,1,'first request was attempted once'); confirm.click(); await new Promise(resolve=>setImmediate(resolve)); requestCount++; assert.equal(requestKeys.length,2); assert.equal(requestKeys[0],requestKeys[1],'uncertain delivery retries with stable request key');
});

test('meeting consent covers both walking legs and maximum-limit guidance stays actionable', async () => {
  const {doc,elements}=makeDocument(); globalThis.document=doc; globalThis.window={addEventListener(){}};
  globalThis.fetch=async()=>({ok:true,json:async()=>({})});
  const module=await import(`../../campuspool-extension/web/commute.mjs?meeting-consent=${Date.now()}`);
  for (const [access,egress] of [[0,800],[300,400],[773,0]]) {
    const ride={trip_id:'meeting',driver_name:'Driver',choice:'ON_ROUTE_MEETING_POINT',access_meters:access,egress_meters:egress,ride_meters:5000,meeting_details:{access_seconds:access,egress_seconds:egress},departure_at:'2026-10-26T12:00:00Z',base_fare_minor:4000,surcharge_minor:0,total_fare_minor:4000};
    Object.assign(module.state,{token:'rider-token',profile:stateFixture.profile,role:'rider',rides:[ride]});
    module.showDetail({dataset:{trip:'meeting'}});
    const detail=elements.get('sheet-root').innerHTML;
    assert.ok(detail.includes(`${access} m to pickup + ${egress} m after drop-off (${access+egress} m total)`),'consent states both walking legs and their sum');
    assert.equal(elements.get('sheet-root').querySelector('#request-confirm').disabled,true,'new walking plan needs fresh consent');
    if(!access){assert.match(detail,/Meet the driver at pin P at your starting point/);assert.doesNotMatch(detail,/Walk 0 m · about/);}
    if(egress) assert.ok(detail.includes(`From drop-off E, walk ${egress} m`),'destination walking is an explicit journey step');
    module.closeSheet();
  }
  Object.assign(module.state,{view:'rides',ridesMode:'search',rides:[],profile:{...stateFixture.profile,max_walk_meters:999},searchContext:{direction:'return',date:'2026-10-26',time:'17:30',vehicle:'car',pickupChoice:'ON_ROUTE_MEETING_POINT'}});
  module.render();
  assert.match(elements.get('view-rides').innerHTML,/walking limit must stay under 1 km/);
  assert.doesNotMatch(elements.get('view-rides').innerHTML,/Try a larger limit/);
  module.state.profile.max_walk_meters=500; module.render();
  assert.match(elements.get('view-rides').innerHTML,/Try a larger limit/);
});

test('mounted driver controls and wallet are participant scoped', async () => {
  const { doc, elements }=makeDocument(); globalThis.document=doc; globalThis.window={addEventListener(){}}; const calls=[]; globalThis.fetch=async (url,options={})=>{calls.push({url,options});if(url.endsWith('commute/wallet'))return {ok:true,json:async()=>({available_minor:1000,held_minor:0,pending_release_minor:0,entries:[]})};if(url.includes('wallet/top-up'))return {ok:true,json:async()=>({available_minor:51000,held_minor:0,pending_release_minor:0,entries:[]})};if(url.endsWith('commute/bookings'))return {ok:true,json:async()=>({data:[]})};if(url.includes('commute/profile'))return {ok:true,json:async()=>({profile:{...stateFixture.profile,role:'driver'}})};if(url.endsWith('commute/me'))return {ok:true,json:async()=>({profile:{...stateFixture.profile,role:'driver'},driver_approved:true})};if(url.includes('commute/bookings/b1/accept'))return {ok:true,json:async()=>({data:{booking_id:'b1',request_state:1,owner:true,state:'planned',fare_minor:1200,wallet_state:'unpaid',rider_name:'Rider',driver_name:'Driver'}})};return {ok:true,json:async()=>({})}};
  const module=await import(`../../campuspool-extension/web/commute.mjs?driver=${Date.now()}`);Object.assign(module.state,{token:'driver-token',profile:{...stateFixture.profile,role:'driver'},role:'driver',driverApproved:true,view:'home',bookings:[{booking_id:'b1',trip_id:'trip-1',request_state:0,owner:true,state:'planned',fare_minor:1200,wallet_state:'unpaid',rider_name:'Rider',driver_name:'Driver'}]});await module.render();assert.match(elements.get('view-home').innerHTML,/Your requests/);await module.bookingAction('accept','b1');assert.ok(calls.some(call=>call.url.includes('commute/bookings/b1/accept')),'driver accept action reached API');await new Promise(resolve=>setImmediate(resolve));for(const [tripState,participantState,label] of [['planned',null,'start trip'],['in_progress','accepted','board'],['in_progress','boarded','alight'],['in_progress','alighted','complete']]){module.state.bookings=[{booking_id:'b1',trip_id:'trip-1',request_state:1,owner:true,state:tripState,participant_state:participantState,fare_minor:1200,wallet_state:'held',rider_name:'Rider',driver_name:'Driver'}];await module.render();const surface=elements.get('view-home').querySelector('#booking-surface');assert.match(surface.innerHTML,new RegExp(label,'i'),`driver ${label} control is mounted`)}module.state.view='wallet';await module.render();assert.match(elements.get('view-wallet').innerHTML,/Demo credits · no real money/);const topup=elements.get('view-wallet').querySelector('#topup-form');assert.ok(topup);topup.dispatch('submit');await new Promise(resolve=>setImmediate(resolve));assert.ok(calls.some(call=>call.url.includes('wallet/top-up')),'wallet top-up mounted action reached API');assert.ok(calls.find(call=>call.url.includes('wallet/top-up')).options.headers['Idempotency-Key']);globalThis.fetch=async()=>({ok:false,status:401,json:async()=>({message:'Unauthorized.'})});await assert.rejects(()=>module.api('commute/bookings'));assert.equal(module.state.token,null,'unauthorized session returns to demo entry instead of showing stale booking controls');assert.match(elements.get('auth-status').textContent,/bookings are retained/);
});

test('driver publication submits the dated saved route and explicit detour limits', async () => {
  const {doc, elements}=makeDocument(); globalThis.document=doc; globalThis.window={addEventListener(){}};
  const calls=[]; const profile={...stateFixture.profile,role:'driver'};
  globalThis.fetch=async(url,options={})=>{calls.push({url,options});return {ok:true,json:async()=>url.endsWith('commute/profile')?{profile,driver_approved:true}:{data:[]}};};
  const module=await import(`../../campuspool-extension/web/commute.mjs?publication=${Date.now()}`);
  Object.assign(module.state,{token:'driver-token',profile,user:{name:'Driver'},role:'driver',driverApproved:true,view:'home'});
  await module.render(); module.openRide();
  const form=elements.get('sheet-root').querySelector('#search-form');
  assert.match(elements.get('sheet-root').innerHTML,/Publish ride/);
  form.elements.date.value='2026-10-23'; form.elements.time.value='08:30';
  form.elements.choice.value='DIRECT_RIDER_PICKUP'; form.elements.max_detour_km.value='5'; form.elements.max_detour_min.value='10';
  form.dispatch('submit'); await new Promise(resolve=>setImmediate(resolve));
  const publish=calls.find(call=>call.url.endsWith('commute/offers')&&call.options.method==='POST');
  assert.ok(publish,'driver publication reached the backend');
  const body=JSON.parse(publish.options.body);
  assert.equal(body.date,'2026-10-23'); assert.equal(body.direction,'outbound'); assert.equal(body.vehicle_type,'car');
  assert.equal(body.max_detour_meters,5000); assert.equal(body.max_detour_seconds,600);
  assert.equal(body.commute.home.lat,profile.home.lat); assert.equal(body.commute.campus.lng,profile.campus.lng);
  assert.ok(publish.options.headers['Idempotency-Key']);
  assert.ok(!calls.some(call=>call.url.includes('commute/rides?')),'publishing does not fabricate rider search supply');
});

const stateFixture = { profile:{name:'Rider',role:'rider',onboarding_complete:true,vehicle_preference:'car',schedule_mode:'fixed',home:{lat:12.9,lng:77.6,label:'Home'},campus:{lat:12.93,lng:77.6,label:'Campus'},outbound_time:'08:30',return_time:'17:30',weekdays:[1,2,3,4,5]} };
