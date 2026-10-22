import urllib.request,urllib.parse,json,uuid,datetime,zoneinfo
base='http://127.0.0.1:18180'
def api(path, method='GET', data=None, token=None):
 headers={'Accept':'application/json'}
 if token:headers['Authorization']='Bearer '+token
 if method=='POST':headers['Idempotency-Key']=str(uuid.uuid4())
 if data is not None:headers['Content-Type']='application/json'
 raw=json.dumps(data).encode() if data is not None else None
 try:
  with urllib.request.urlopen(urllib.request.Request(base+path,data=raw,method=method,headers=headers),timeout=20) as f:
   return json.load(f)
 except urllib.error.HTTPError as e:
  print('HTTP ERROR',method,path,e.code,e.read().decode()[:1300]);raise
driver=api('/api/mobility/commute/demo-session','POST',{'role':'driver','scenario':'campus-route'})
rider=api('/api/mobility/commute/demo-session','POST',{'role':'rider','scenario':'campus-route'})
print('SYNTHETIC_SESSIONS_STARTED')
campus={'lat':12.9352,'lng':77.62,'label':'Campus synthetic'}
dp={'lat':13.01,'lng':77.53,'label':'Yeshwanthpur simulated'}
rp={'lat':13.0093,'lng':77.529,'label':'Laggere synthetic'}
def pref(role,home):return {'role':role,'vehicle_preference':'car','schedule_mode':'fixed','home':home,'campus':campus,'outbound_time':'08:30','return_time':'17:30','weekdays':[1,2,3,4,5],'max_walk_meters':999,'name':role.title()+' Demo'}
api('/api/mobility/commute/profile','PUT',pref('driver',dp),driver['token'])
api('/api/mobility/commute/profile','PUT',pref('rider',rp),rider['token'])
date=(datetime.datetime.now(zoneinfo.ZoneInfo('Asia/Kolkata'))+datetime.timedelta(days=2)).strftime('%Y-%m-%d')
print('DEMO_DATE',date)
offer=api('/api/mobility/commute/offers','POST',{'direction':'outbound','date':date,'vehicle_type':'car','choice':'ON_ROUTE_MEETING_POINT','max_detour_meters':3000,'max_detour_seconds':600},driver['token'])
print('OFFER_PUBLISHED',offer['data']['trip_id'])
q=urllib.parse.urlencode({'direction':'outbound','date':date,'vehicle_type':'car','pickup_choice':'both'})
rides=api('/api/mobility/commute/rides?'+q,token=rider['token'])
print('RIDE_COUNT',len(rides.get('data',[])))
if not rides['data']:raise RuntimeError('no matching rides')
ride=rides['data'][0]
print('QUOTE_RECEIVED',ride.get('trip_id'))
booking=api('/api/mobility/commute/rides/'+str(ride['trip_id'])+'/request','POST',{'quote_token':ride['quote_token']},rider['token'])
id=booking['data']['booking_id']
print('REQUEST',id)
accepted=api('/api/mobility/commute/bookings/'+str(id)+'/accept','POST',{},driver['token'])
print('ACCEPT',accepted['data']['request_state'])
cancelled=api('/api/mobility/commute/bookings/'+str(id)+'/cancel','POST',{},rider['token'])
print('CANCEL',cancelled['data']['request_state'])
assert cancelled['data']['request_state']==3
print('PORTABLE_INTERACTIVE_BOOKING_PASS')
