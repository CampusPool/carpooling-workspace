import test from 'node:test';
import assert from 'node:assert/strict';
import {mountMap,rideGeometry,commutePreview,matchesSavedCommute} from '../../campuspool-extension/web/commute-map.mjs';

const geometry=[[77.54,13.02],[77.53,13.01],[77.535,12.96],[77.534,12.935]];
const data={driver_geometry:geometry,driver_waypoints:geometry,driver_legs:[{distance_meters:1500},{distance_meters:5600},{distance_meters:2700}],access_geometry:[[77.5299,13.0101],[77.53,13.01]],egress_geometry:[[77.535,12.96],[77.5351,12.9601]],points:{driver_start:{lng:77.54,lat:13.02,label:'Driver home'},origin:{lng:77.5297,lat:13.0102,label:'Rider home'},pickup:{lng:77.53,lat:13.01},dropoff:{lng:77.535,lat:12.96},destination:{lng:77.5352,lat:12.9602,label:'Campus'},driver_end:{lng:77.534,lat:12.935,label:'Driver destination'}},walking_meters:54,access_meters:32,egress_meters:22,unmapped_gap_meters:12};
class Node {
  constructor(){this.hidden=false;this.textContent='';this.buttons=[];}
  set innerHTML(value){this.buttons=[...value.matchAll(/data-map-stop="([^"]+)"/g)].map(match=>({dataset:{mapStop:match[1]}}));this.html=value;}
  get innerHTML(){return this.html||'';}
  querySelectorAll(){return this.buttons;}
}
function harness(){
  const nodes=new Map(['canvas','status','stops','note','legend','retry','fit','title','access','egress'].map(key=>[key,new Node()]));
  const root={querySelector(selector){return nodes.get(selector.slice(10,-1));}};
  const maps=[],lines=[],markers=[];
  class FakeMap {constructor(canvas,options){this.options=options;maps.push(this);}fitBounds(bounds){this.fitted=bounds;}panTo(point){this.focus=point;}setZoom(zoom){this.zoom=zoom;}}
  class Bounds {constructor(){this.points=[];}extend(point){this.points.push(point);}isEmpty(){return !this.points.length;}}
  class Polyline {constructor(options){Object.assign(this,options);lines.push(this);}setMap(value){this.map=value;}}
  class Marker {constructor(options){Object.assign(this,options);markers.push(this);}}
  const api={Map:FakeMap,LatLngBounds:Bounds,Polyline,AdvancedMarkerElement:Marker,event:{clearInstanceListeners(){}}};
  globalThis.document={createElement:()=>new Node()};
  return {root,nodes,maps,lines,markers,api};
}
const settle=()=>new Promise(resolve=>setImmediate(resolve));
test('changed saved places cannot select an old booking or publication as the current commute',()=>{
  const rider={home:data.points.origin,campus:data.points.destination};
  const driver={home:data.points.driver_start,campus:data.points.driver_end};
  assert.equal(matchesSavedCommute(data,rider),true);assert.equal(matchesSavedCommute(data,driver,true),true);
  assert.equal(matchesSavedCommute(data,{...rider,home:{lat:12.9798246,lng:77.5115542}}),false);
  assert.equal(matchesSavedCommute(data,{...driver,home:{lat:13.0099447,lng:77.5272928}},true),false);
  assert.equal(matchesSavedCommute(data,{home:rider.campus,campus:rider.home}),true,'return snapshots compare against reversed saved endpoints');
  assert.equal(matchesSavedCommute({...data,points:{...data.points,origin:{...rider.home,label:'Renamed same place'}}},rider),true,'display names do not change a location');
});
test('rider overlay follows the ordered shared leg of the server geometry',()=>{
  assert.deepEqual(rideGeometry(data),geometry.slice(1,3));
  assert.deepEqual(rideGeometry({...data,driver_waypoints:geometry.slice(0,2)}),[]);
  const preview=commutePreview({home:data.points.origin,campus:data.points.destination},'return');
  assert.deepEqual(preview.points.origin,data.points.destination);assert.deepEqual(preview.driver_geometry,[],'selected place pins do not invent a road route');
});
test('map renders the stored paths, labelled stops, walking gaps, and focus controls',async()=>{
  const h=harness();const scrolls=[];h.nodes.get('canvas').scrollIntoView=options=>scrolls.push(options);const map=mountMap(h.root,{...data,heading:'Tomorrow · 08:37 IST'},{loader:async()=>h.api});await settle();
  assert.equal(h.maps.length,1);assert.equal(h.nodes.get('title').textContent,'Tomorrow · 08:37 IST');
  assert.deepEqual(h.lines[0].path,geometry.map(pair=>({lng:pair[0],lat:pair[1]})));
  assert.match(h.nodes.get('stops').innerHTML,/Pickup/);assert.match(h.nodes.get('stops').innerHTML,/Drop-off/);assert.match(h.nodes.get('note').textContent,/not live GPS/);
  assert.ok(h.lines.some(line=>line.icons),'walking and unmapped connections use dotted overlays');
  const pickup=h.nodes.get('stops').buttons.find(button=>button.dataset.mapStop==='pickup');pickup.onclick();
  assert.deepEqual(h.maps[0].focus,data.points.pickup);assert.equal(h.maps[0].zoom,17);
  h.nodes.get('fit').onclick();assert.ok(h.maps[0].fitted.points.length>2);assert.equal(scrolls.length,2,'zoom and fit bring the map back into view'); h.nodes.get('access').onclick(); assert.match(h.nodes.get('status').textContent,/Walk to pickup/); assert.equal(scrolls.length,3,'walking-only view is brought into view');
  map.update({preview:true,points:{origin:null,destination:data.points.destination}});
  assert.equal(h.maps[0].zoom,16,'editing an address keeps a useful neighbourhood zoom for the remaining pin');
  assert.equal(h.markers.filter(marker=>marker.map!==null).length,1,'invalidated address cannot retain its old pin');
  map.dispose();assert.ok(h.lines.every(line=>line.map===null));assert.ok(h.markers.every(marker=>marker.map===null));
});
test('late SDK load cannot mount a map after navigation or sign out',async()=>{
  const h=harness();let resolve;const map=mountMap(h.root,data,{loader:()=>new Promise(done=>resolve=done)});
  map.dispose();resolve(h.api);await settle();assert.equal(h.maps.length,0);assert.equal(h.markers.length,0);
});
test('map failures expose retry and can recover without changing the booking data',async()=>{
  const h=harness();let attempts=0;mountMap(h.root,data,{loader:async()=>{if(++attempts===1)throw Error('Connection lost');return h.api;}});await settle();
  assert.equal(h.nodes.get('retry').hidden,false);assert.equal(h.nodes.get('status').textContent,'Connection lost');
  h.nodes.get('retry').onclick();await settle();assert.equal(h.maps.length,1);assert.equal(h.nodes.get('retry').hidden,true);assert.equal(data.walking_meters,54);
});
