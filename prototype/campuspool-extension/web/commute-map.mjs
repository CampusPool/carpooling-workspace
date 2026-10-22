const esc = value => String(value ?? '').replace(/[&<>"']/g, char => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[char]));
const valid = point => Number.isFinite(point?.lat) && Number.isFinite(point?.lng) && point.lat >= 12.65 && point.lat <= 13.35 && point.lng >= 77.2 && point.lng <= 77.95;
const pairPoint = pair => ({lat:Number(pair?.[1]),lng:Number(pair?.[0])});
const distance = (a,b) => Math.hypot((a.lat-b.lat)*111000,(a.lng-b.lng)*108000);
const colors = {driver:'#5278dc',ride:'#087b63',walk:'#b55a0a',gap:'#745d42'};
let sdkPromise;

// Reuse the configured local evaluation key. Never forward the app's JWT to Google.
export function loadMaps() {
  if (!sdkPromise) sdkPromise = (async () => {
    const response = await fetch('/mobility-pilot/maps-config',{credentials:'omit',cache:'no-store',signal:AbortSignal.timeout(7000)});
    const config = await response.json();
    if (!response.ok || !config.browser_key) throw Error('Map access is unavailable. Please retry.');
    if (!window.google?.maps?.importLibrary) await new Promise((resolve,reject) => {
      const script=document.createElement('script');
      const timer=setTimeout(()=>{script.remove();reject(Error('The map took too long to load. Please retry.'));},12000);
      window.__commuteMapReady=()=>{clearTimeout(timer);resolve();};
      script.src=`https://maps.googleapis.com/maps/api/js?key=${encodeURIComponent(config.browser_key)}&v=weekly&loading=async&callback=__commuteMapReady`;
      script.async=true; script.nonce=document.querySelector('script[nonce]')?.nonce || '';
      script.onerror=()=>{clearTimeout(timer);script.remove();reject(Error('The map could not load. Check your connection and retry.'));};
      document.head.appendChild(script);
    });
    const [maps,markers,core]=await Promise.all(['maps','marker','core'].map(name=>window.google.maps.importLibrary(name)));
    return {...maps,...markers,...core,mapId:config.map_id||'DEMO_MAP_ID'};
  })().catch(error=>{sdkPromise=null;throw error;});
  return sdkPromise;
}

export function mapPanel(id,title='Your route on the map') {
  return `<section class="commute-map-panel" id="${id}" aria-label="${esc(title)}"><div class="map-heading"><div><span class="map-eyebrow">YOUR JOURNEY</span><h2 data-map-title>${esc(title)}</h2></div><button class="map-fit" type="button" data-map-fit aria-label="Fit whole route">↗ <span>Whole route</span></button></div><div class="commute-map-canvas" data-map-canvas role="region" aria-label="Interactive journey map"></div><p class="map-status" role="status" data-map-status>Loading map…</p><button class="button outline map-retry" type="button" data-map-retry hidden>Retry map</button><div class="map-legend" data-map-legend><span class="driver-key">Driver route</span><span class="ride-key">Shared ride</span><span class="walk-key">Walk / access</span></div><div class="map-walking-controls"><button type="button" class="map-fit" data-map-access hidden>Walk to pickup</button><button type="button" class="map-fit" data-map-egress hidden>Walk from drop-off</button></div><div class="map-stops" data-map-stops></div><p class="map-footnote" data-map-note>Scheduled route · not a live location</p></section>`;
}

export function commutePreview(profile,direction='outbound') {
  const back=direction==='return';
  return {driver_geometry:[],points:{origin:profile?.[back?'campus':'home'],destination:profile?.[back?'home':'campus']},preview:true};
}

// Saved preferences can change; a previously published/requested route cannot.
export function matchesSavedCommute(data,profile,driver=false) {
  const p=data?.points;
  if(!p)return true;
  const start=driver?p.driver_start:p.origin,end=driver?p.driver_end:p.destination;
  const same=(a,b)=>valid(a)&&valid(b)&&distance(a,b)<10;
  return (same(start,profile?.home)&&same(end,profile?.campus)) || (same(start,profile?.campus)&&same(end,profile?.home));
}

// Slice only the displayed rider overlay; never generate or change booking routes.
// Ordered waypoint boundaries and cumulative leg lengths disambiguate repeated roads.
export function rideGeometry(data) {
  const geometry=data?.driver_geometry||[],waypoints=data?.driver_waypoints||[];
  if (waypoints.length!==4 || geometry.length<2) return [];
  const points=geometry.map(pairPoint),cumulative=[0];
  for(let i=1;i<points.length;i++) cumulative.push(cumulative[i-1]+distance(points[i-1],points[i]));
  const declared=(data.driver_legs||[]).reduce((sum,leg)=>sum+Number(leg.distance_meters||0),0);
  let cursor=0,expected=0; const cuts=[];
  for(let w=0;w<waypoints.length;w++) {
    if(w>0) expected+=Number(data.driver_legs?.[w-1]?.distance_meters||0);
    const target=pairPoint(waypoints[w]),position=declared?expected/declared*cumulative.at(-1):0;
    let index=cursor,best=Infinity;
    for(let i=cursor;i<points.length;i++) {
      const score=distance(points[i],target)+Math.abs(cumulative[i]-position)*0.0001;
      if(score<best){index=i;best=score;}
    }
    cuts.push(index);cursor=index;
  }
  return cuts[2]>cuts[1]?geometry.slice(cuts[1],cuts[2]+1):[];
}

export function mountMap(root,initial,{isCurrent=()=>true,loader=loadMaps}={}) {
  if(!root) return {update(){},dispose(){}};
  let disposed=false,map,api,overlays=[],bounds,data=initial,fingerprint='',generation=0;
  const canvas=root.querySelector('[data-map-canvas]'),status=root.querySelector('[data-map-status]'),stops=root.querySelector('[data-map-stops]');
  const note=root.querySelector('[data-map-note]'),legend=root.querySelector('[data-map-legend]'),retry=root.querySelector('[data-map-retry]');
  const current=()=>!disposed && isCurrent();
  const clear=()=>{for(const overlay of overlays){api?.event?.clearInstanceListeners(overlay);if(overlay.setMap)overlay.setMap(null);else overlay.map=null;}overlays=[];};
  const fit=()=>{if(map&&bounds&&!bounds.isEmpty()){const selected=Object.values(data?.points||{}).filter(valid);if(data?.preview&&selected.length===1){map.panTo({lat:selected[0].lat,lng:selected[0].lng});map.setZoom(16);}else map.fitBounds(bounds,{top:26,right:26,bottom:26,left:26});}};
  const line=(pairs,color,dotted=false,weight=5)=>{
    const path=(pairs||[]).map(pairPoint).filter(valid);if(path.length<2)return;
    overlays.push(new api.Polyline({map,path,strokeColor:color,strokeWeight:weight,strokeOpacity:dotted?0:0.95,zIndex:color===colors.ride?3:1,...(dotted?{icons:[{icon:{path:'M 0,-1 0,1',strokeColor:color,strokeOpacity:1,strokeWeight:3,scale:2},offset:'0',repeat:'11px'}]}:{})}));
    path.forEach(point=>bounds.extend(point));
  };
  const walkingView=access=>{
    if(!map||!api)return;
    const p=data?.points||{},pairs=access?data?.access_geometry:data?.egress_geometry;
    const points=[access?p.origin:p.dropoff,...(pairs||[]).map(pairPoint),access?p.pickup:p.destination].filter(valid);
    if(!points.length)return;const walkBounds=new api.LatLngBounds();points.forEach(point=>walkBounds.extend(point));
    const diameter=Math.max(...points.map(point=>distance(point,points[0])));
    if(diameter<100){map.panTo({lat:points[0].lat,lng:points[0].lng});map.setZoom(17);}else map.fitBounds(walkBounds,{top:28,right:28,bottom:28,left:28});
    canvas.scrollIntoView?.({block:'center',behavior:'smooth'});status.textContent=`${access?'Walk to pickup':'Walk to destination'} · ${access?data.access_meters:data.egress_meters} m including access gaps`;
  };
  const draw=()=>{
    if(!api||!current())return;clear();bounds=new api.LatLngBounds();
    if(data?.heading)root.querySelector('[data-map-title]').textContent=data.heading;
    line(data?.driver_geometry,colors.driver,false,6);line(rideGeometry(data),colors.ride,false,7);
    line(data?.access_geometry,colors.walk,true);line(data?.egress_geometry,colors.walk,true);
    const p=data?.points||{};
    const accessControl=root.querySelector('[data-map-access]'),egressControl=root.querySelector('[data-map-egress]');
    if(accessControl){accessControl.hidden=!(data?.access_meters>0);accessControl.onclick=()=>walkingView(true);}
    if(egressControl){egressControl.hidden=!(data?.egress_meters>0);egressControl.onclick=()=>walkingView(false);}
    for(const [from,geometry,to] of [[p.origin,data?.access_geometry,p.pickup],[p.dropoff,data?.egress_geometry,p.destination]]) {
      if(valid(from)&&geometry?.length)line([[from.lng,from.lat],geometry[0]],colors.gap,true,3);
      if(valid(to)&&geometry?.length)line([geometry.at(-1),[to.lng,to.lat]],colors.gap,true,3);
    }
    const rows=[];
    const add=(key,point,badge,label,tone)=>{
      if(!valid(point))return;
      point={...point,label:point.label||(['pickup','origin'].includes(key)?p.origin?.label:['dropoff','destination'].includes(key)?p.destination?.label:null)};
      const content=document.createElement('div');content.className=`commute-map-pin ${tone}`;content.textContent=badge;
      const marker=new api.AdvancedMarkerElement({map,position:{lat:point.lat,lng:point.lng},content,title:`${label}${point.label?' · '+point.label:''}`,zIndex:['pickup','dropoff'].includes(key)?10:5});
      overlays.push(marker);bounds.extend(point);rows.push({key,point,badge,label,tone});
    };
    if(data?.preview) {
      add('origin',p.origin,'A','Start point','driver');add('destination',p.destination,'B','Destination','ride');
    } else {
      if(valid(p.driver_start)&&(!valid(p.pickup)||distance(p.driver_start,p.pickup)>8))add('driver_start',p.driver_start,'D','Driver starts','driver');
      if(valid(p.origin)&&valid(p.pickup)&&distance(p.origin,p.pickup)>8)add('origin',p.origin,'H','Rider starts','walk');
      add('pickup',p.pickup,'P',data?.choice==='ON_ROUTE_MEETING_POINT'?'Meeting point':'Pickup','ride');add('dropoff',p.dropoff,'E','Drop-off','ride');
      if(valid(p.destination)&&valid(p.dropoff)&&distance(p.destination,p.dropoff)>8)add('destination',p.destination,'B','Rider destination','walk');
      if(valid(p.driver_end)&&(!valid(p.dropoff)||distance(p.driver_end,p.dropoff)>8))add('driver_end',p.driver_end,'F','Driver finishes','driver');
    }
    stops.innerHTML=rows.map(row=>`<button class="map-stop" type="button" data-map-stop="${row.key}"><span class="stop-badge ${row.tone}">${row.badge}</span><span><strong>${row.label}</strong><small>${esc(row.point.label||(['pickup','origin'].includes(row.key)?p.origin?.label:p.destination?.label)||'Roadside point')}</small></span><span class="stop-locate" aria-hidden="true">⌖</span></button>`).join('');
    stops.querySelectorAll('[data-map-stop]').forEach(button=>button.onclick=()=>{const row=rows.find(row=>row.key===button.dataset.mapStop);if(row){map.panTo({lat:row.point.lat,lng:row.point.lng});map.setZoom(17);canvas.scrollIntoView?.({block:'center',behavior:'smooth'});status.textContent=`${row.label} · ${row.point.label||'Roadside meeting point'}`;}});
    legend.hidden=Boolean(data?.preview);note.textContent=data?.preview?'Selected places · road route appears after finding or publishing a ride':`Scheduled route · not live GPS${data?.unmapped_gap_meters?` · ${data.unmapped_gap_meters} m of walking includes unmapped connections`:''}`;
    status.textContent=rows.length?data?.preview?'Your selected start and destination.':p.pickup?`Pickup P → drop-off E${data?.walking_meters?` · ${data.walking_meters} m total walking`:''}`:'Published driving route. Tap a stop to zoom in.':'Choose locations to see them on the map.';
    fit();
  };
  const boot=async()=>{
    const version=++generation;retry.hidden=true;status.textContent='Loading map…';
    try {
      const loaded=await loader();if(!current()||version!==generation)return;api=loaded;
      map=new api.Map(canvas,{center:{lat:12.98,lng:77.55},zoom:12,mapId:api.mapId,streetViewControl:false,mapTypeControl:false,fullscreenControl:false,clickableIcons:false,gestureHandling:'cooperative',zoomControl:true});
      draw();
    }catch(error){if(current()&&version===generation){status.textContent=error.message||'Map unavailable. Please retry.';retry.hidden=false;}}
  };
  root.querySelector('[data-map-fit]').onclick=()=>{fit();canvas.scrollIntoView?.({block:'center',behavior:'smooth'});};retry.onclick=()=>void boot();
  void boot();
  return {update(next){if(!current())return;const hash=JSON.stringify(next);if(hash===fingerprint)return;fingerprint=hash;data=next;if(map)draw();},dispose(){disposed=true;generation++;clear();if(map)api?.event?.clearInstanceListeners(map);map=null;}};
}
