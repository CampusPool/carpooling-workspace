<?php
use Illuminate\Support\Facades\Route;
use STS\Mobility\Commute\CommuteController as C;
Route::prefix('api/mobility/commute')->middleware(['api','throttle:30,1'])->group(function(){Route::post('demo-session',[C::class,'demo']);});
Route::prefix('api/mobility/commute')->middleware(['api','logged'])->group(function(){
 Route::get('offers',[C::class,'offers']);Route::post('offers',[C::class,'publish']);
 Route::get('me',[C::class,'me']);Route::put('profile',[C::class,'profile']);Route::get('rides',[C::class,'rides']);Route::post('rides/{trip}/request',[C::class,'request'])->whereNumber('trip');Route::get('bookings',[C::class,'bookings']);Route::post('bookings/{booking}/{action}',[C::class,'action'])->whereNumber('booking')->whereIn('action',['accept','reject','cancel']);
 Route::get('wallet',[C::class,'wallet']);Route::post('wallet/top-up',[C::class,'topup']);Route::post('wallet/release-due',[C::class,'release']);Route::post('bookings/{booking}/pay',[C::class,'pay'])->whereNumber('booking');Route::post('bookings/{booking}/confirm-arrival',[C::class,'arrive'])->whereNumber('booking');Route::post('trips/{trip}/transition',[C::class,'transition'])->whereNumber('trip');
});
Route::middleware('web')->get('mobility/{asset?}',function(?string$asset=null){
 if(!filter_var(getenv('MOBILITY_PILOT_UI_ENABLED'),FILTER_VALIDATE_BOOLEAN))abort(404);
 $files=[''=>'commute.html','commute.css'=>'commute.css','commute.mjs'=>'commute.mjs','trip-chat-ui.mjs'=>'trip-chat-ui.mjs','place-search.mjs'=>'place-search.mjs','commute-map.mjs'=>'commute-map.mjs'];if(!isset($files[$asset??'']))abort(404);$file=$files[$asset??''];$root=rtrim(getenv('MOBILITY_EXTENSION_ROOT')?:dirname(__DIR__),'/');
 $type=str_ends_with($file,'.html')?'text/html':(str_ends_with($file,'.css')?'text/css':'text/javascript');
 // Google Maps propagates the response nonce to its inserted scripts/styles.
 // https://developers.google.com/maps/documentation/javascript/content-security-policy
 $nonce=base64_encode(random_bytes(18));
 $headers=['Content-Type'=>$type.'; charset=utf-8','Cache-Control'=>'no-store','X-Content-Type-Options'=>'nosniff','Referrer-Policy'=>'no-referrer','Content-Security-Policy'=>"default-src 'self'; script-src 'self' 'nonce-$nonce' https://maps.googleapis.com https://maps.gstatic.com; style-src 'self' 'nonce-$nonce' https://fonts.googleapis.com; img-src 'self' data: blob: https://*.googleapis.com https://*.gstatic.com https://*.google.com https://*.googleusercontent.com; connect-src 'self' https://*.googleapis.com https://*.gstatic.com https://*.google.com; font-src https://fonts.gstatic.com; worker-src blob:; frame-src 'self' https://*.google.com; object-src 'none'; base-uri 'none'; frame-ancestors 'none'"];
 if($file==='commute.html'){$html=file_get_contents($root.'/web/'.$file);$html=str_replace(['<script type="module"','</head>'],['<script nonce="'.$nonce.'" type="module"','<style nonce="'.$nonce.'"></style></head>'],$html);return response($html,200,$headers);}
 return response()->file($root.'/web/'.$file,$headers);
});
