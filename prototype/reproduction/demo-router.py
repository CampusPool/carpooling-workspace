#!/usr/bin/env python3
"""Deterministic local synthetic route fixture. NOT road navigation."""
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from urllib.parse import urlsplit, unquote
import json, math, os, re
PORT = 5000
class Handler(BaseHTTPRequestHandler):
    def do_GET(self):
        path = unquote(urlsplit(self.path).path)
        match = re.fullmatch(r"/route/v1/(car|bike|foot)/(.+)", path)
        if not match:
            self.reply(404, {"code":"InvalidUrl"}); return
        mode, coordinates = match.groups()
        try:
            points = [[float(a),float(b)] for a,b in (part.split(",") for part in coordinates.split(";"))]
            if not 2 <= len(points) <= 8 or any(not (77.2 <= x <= 77.95 and 12.65 <= y <= 13.35) for x,y in points):
                raise ValueError("outside Bengaluru demo envelope")
        except (ValueError, TypeError):
            self.reply(400, {"code":"InvalidQuery"}); return
        legs=[]; total_dist=total_time=0
        speed={"car":8.0,"bike":5.5,"foot":1.3}[mode]
        for (x,y),(xx,yy) in zip(points,points[1:]):
            distance=round(math.hypot((xx-x)*108000,(yy-y)*111000),2)
            duration=round(distance/speed,2)
            legs.append({"distance":distance,"duration":duration,"summary":"synthetic straight line"})
            total_dist+=distance;total_time+=duration
        identity=mode+"|crop_sha256="+("a"*64)+"|profile_sha256="+("b"*64)
        self.reply(200,{"code":"Ok","data_version":identity,"routes":[{"distance":round(total_dist,2),"duration":round(total_time,2),"geometry":{"type":"LineString","coordinates":points},"legs":legs}],"waypoints":[{"distance":0,"location":point,"name":"Synthetic coordinate"} for point in points]})
    def reply(self,status,data):
        body=json.dumps(data,separators=(",",":")).encode()
        self.send_response(status);self.send_header("Content-Type","application/json")
        self.send_header("Content-Length",str(len(body)));self.end_headers();self.wfile.write(body)
    def log_message(self,*args): pass
ThreadingHTTPServer(("0.0.0.0",PORT),Handler).serve_forever()
