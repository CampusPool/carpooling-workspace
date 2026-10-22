#!/usr/bin/env python3
"""Black-box localhost-only S4 segment-booking journey using synthetic pilot accounts."""

import argparse
from concurrent.futures import ThreadPoolExecutor
from datetime import datetime, timedelta
import json
from pathlib import Path
import re
import threading
import urllib.error
import urllib.parse
import urllib.request
import uuid
from zoneinfo import ZoneInfo


BASE = 'http://127.0.0.1:18080/api/'
EMAIL_RE = re.compile(r'^pilot-(host|rider)-[a-f0-9]+@example\.test$')


def parse_accounts(path: Path) -> dict[str, str]:
    values = dict(re.findall(r'^([A-Z_]+)=(.+)$', path.read_text(), re.M))
    for field in ('HOST_EMAIL', 'RIDER_EMAIL', 'PASSWORD'):
        if field not in values:
            raise ValueError(f'{path}: missing {field}')
    if not EMAIL_RE.fullmatch(values['HOST_EMAIL']) or not values['HOST_EMAIL'].startswith('pilot-host-'):
        raise ValueError(f'{path}: HOST_EMAIL is not a generated synthetic host')
    if not EMAIL_RE.fullmatch(values['RIDER_EMAIL']) or not values['RIDER_EMAIL'].startswith('pilot-rider-'):
        raise ValueError(f'{path}: RIDER_EMAIL is not a generated synthetic rider')
    return values


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--accounts-a', type=Path, required=True)
    parser.add_argument('--accounts-b', type=Path, required=True)
    parser.add_argument('--accounts-c', type=Path, required=True)
    parser.add_argument('--output', type=Path, required=True)
    args = parser.parse_args()

    bundles = [parse_accounts(path) for path in (args.accounts_a, args.accounts_b, args.accounts_c)]
    report = {
        'schema_version': 1,
        'started_at': datetime.now(ZoneInfo('UTC')).isoformat(),
        'boundary': 'Local HTTP/JWT/MySQL at 127.0.0.1:18080, synthetic catalog and invented routing only. No browser, device, road, provider money or production evidence.',
        'checks': [],
    }

    def api(method: str, path: str, body=None, token=None, key=None):
        headers = {'Accept': 'application/json', 'Content-Type': 'application/json'}
        if token:
            headers['Authorization'] = 'Bearer ' + token
        if key:
            headers['Idempotency-Key'] = key
        request = urllib.request.Request(
            BASE + path,
            data=None if body is None else json.dumps(body).encode(),
            method=method,
            headers=headers,
        )
        try:
            response = urllib.request.urlopen(request, timeout=30)
        except urllib.error.HTTPError as error:
            response = error
        except urllib.error.URLError as error:
            raise RuntimeError(f'localhost HTTP unavailable: {error.reason}') from error
        with response:
            raw = response.read()
            try:
                payload = json.loads(raw.decode()) if raw else {}
            except (UnicodeDecodeError, json.JSONDecodeError):
                payload = {}
            return response.status, payload

    def check(name: str, condition: bool, **observed) -> None:
        clean = {key: value for key, value in observed.items() if key not in {'token', 'password', 'authorization'}}
        report['checks'].append({'name': name, 'passed': bool(condition), 'observed': clean})
        if not condition:
            raise RuntimeError(name + ' failed: ' + json.dumps(clean, sort_keys=True))

    def concurrent(operation):
        barrier = threading.Barrier(2)

        def call():
            barrier.wait(timeout=10)
            return operation()

        with ThreadPoolExecutor(max_workers=2) as pool:
            futures = [pool.submit(call) for _ in range(2)]
            return [future.result() for future in futures]

    def login(bundle):
        status, payload = api('POST', 'login', {'email': bundle['EMAIL'], 'password': bundle['PASSWORD']})
        check(bundle['role'].lower() + ' synthetic login', status == 200 and isinstance(payload.get('token'), str), status=status)
        return payload['token']

    def user_id(token):
        status, payload = api('GET', 'users/me', token=token)
        value = payload.get('id', payload.get('data', {}).get('id'))
        check('authenticated user identity resolved', status == 200 and isinstance(value, int), status=status)
        return value

    def membership(token):
        status, payload = api('GET', 'mobility/membership/me', token=token)
        data = payload.get('data', payload)
        applications = data.get('host_applications', [])
        approved = [item for item in applications if item.get('status') == 'approved' and item.get('vehicle_type') == 'car']
        check('synthetic host application is approved', status == 200 and len(approved) == 1, status=status)
        return approved[0]['car_id']

    def catalog(token):
        status, payload = api('GET', 'mobility/pickup-points?vehicle_type=car&page_size=50', token=token)
        points = {item.get('name'): item for item in payload.get('data', [])}
        names = ['Synthetic Routing Stop A', 'Synthetic Routing Stop B', 'Synthetic Routing Stop C']
        check('explicit synthetic A/B/C catalog', status == 200 and all(name in points and points[name].get('synthetic_only') is True for name in names), status=status)
        return [points[name] for name in names]

    def quote(token, points):
        status, payload = api('POST', 'mobility/routes/quote', {'stop_ids': [point['id'] for point in points], 'vehicle_type': 'car'}, token)
        data = payload.get('data', {})
        check('three-stop quote has trusted synthetic route terms', status == 201 and data.get('route_schema') == 'ordered-legs-v1' and data.get('synthetic_only') is True and len(data.get('points', [])) == 3 and len(data.get('legs', [])) == 2 and data.get('distance_meters') == 5000 and data.get('duration_seconds') == 1200 and data.get('seat_price_minor') == 1000 and [leg.get('seat_price_minor') for leg in data.get('legs', [])] == [500, 500], status=status)
        return data

    def publish(token, car, points, ordinal):
        terms = quote(token, points)
        departure = datetime.now(ZoneInfo('Asia/Kolkata')) + timedelta(days=2 + ordinal, minutes=uuid.uuid4().int % 720)
        departure = departure.replace(second=0, microsecond=0)
        body = {'quote_id': terms['id'], 'car_id': car, 'trip_date': departure.strftime('%Y-%m-%dT%H:%M'), 'total_seats': 1, 'description': 'SYNTHETIC S4 segment HTTP journey'}
        status, payload = api('POST', 'mobility/pool-offers', body, token, 's4-publish-' + uuid.uuid4().hex)
        data = payload.get('data', {})
        check('one-seat future three-stop publication', status == 201 and isinstance(data.get('id'), int) and len(data.get('points', [])) == 3 and len(data.get('legs', [])) == 2, status=status)
        return data['id'], departure.date().isoformat()

    def search(token, service_date, pickup, dropoff, expected_trip):
        query = urllib.parse.urlencode({'service_date': service_date, 'pickup_point_id': pickup['id'], 'dropoff_point_id': dropoff['id'], 'vehicle_type': 'car', 'page_size': 20})
        status, payload = api('POST', 'mobility/pool-matches', {'service_date': service_date, 'pickup_point_id': pickup['id'], 'dropoff_point_id': dropoff['id'], 'vehicle_type': 'car', 'page_size': 20}, token)
        matches = payload.get('data', [])
        selected = next((item for item in matches if item.get('trip_id') == expected_trip), None)
        return status, matches, selected, query

    def issue(token, match, label):
        status, payload = api('POST', f"mobility/pool-matches/{match['match_id']}/requests", {}, token, 's4-request-' + label + '-' + uuid.uuid4().hex)
        booking = payload.get('data', {})
        check(label + ' request committed', status == 201 and isinstance(booking.get('booking_id'), int), status=status)
        return booking['booking_id']

    def accept(token, trip, rider, label=''):
        status, payload = api('POST', f'trips/{trip}/requests/{rider}/accept', {}, token)
        return status, payload

    try:
        actor_bundles = []
        for index, bundle in enumerate(bundles):
            actor_bundles.append({'role': ['HOST', 'RIDER_A', 'RIDER_B'][index], 'EMAIL': bundle['HOST_EMAIL'] if index == 0 else bundle['RIDER_EMAIL'], 'PASSWORD': bundle['PASSWORD']})
        host_token = login(actor_bundles[0])
        rider_tokens = [login(actor) for actor in actor_bundles[1:]]
        rider_a_token = login({'role': 'RIDER_A', 'EMAIL': bundles[0]['RIDER_EMAIL'], 'PASSWORD': bundles[0]['PASSWORD']})
        rider_b_token = rider_tokens[0]
        rider_c_token = rider_tokens[1]
        rider_a_id, rider_b_id, rider_c_id = user_id(rider_a_token), user_id(rider_b_token), user_id(rider_c_token)
        car = membership(host_token)
        points = catalog(host_token)
        a, b, c = points
        trip_one, date_one = publish(host_token, car, points, 1)
        trip_two, date_two = publish(host_token, car, points, 2)
        report.update({'trip_one_id': trip_one, 'trip_two_id': trip_two, 'rider_ids': [rider_a_id, rider_b_id, rider_c_id]})

        _, _, ab_ref, _ = search(rider_a_token, date_one, a, b, trip_one)
        _, _, ac_ref, _ = search(rider_c_token, date_one, a, c, trip_one)
        _, _, bc_ref, _ = search(rider_b_token, date_one, b, c, trip_one)
        check('initial AB/AC/BC references are all issued', all(ref is not None for ref in (ab_ref, ac_ref, bc_ref)))
        check('trusted interval fare sums exact covered legs', ab_ref['seat_price_minor'] == 500 and bc_ref['seat_price_minor'] == 500 and ac_ref['seat_price_minor'] == 1000)
        ab_booking = issue(rider_a_token, ab_ref, 'ab')
        status, _ = accept(host_token, trip_one, rider_a_id, 'AB')
        check('host accepts AB interval', status == 200, status=status)
        bc_booking = issue(rider_b_token, bc_ref, 'bc')
        status, _ = accept(host_token, trip_one, rider_b_id, 'BC')
        check('host accepts BC interval', status == 200, status=status)
        ac_booking = issue(rider_c_token, ac_ref, 'ac')
        status, _ = accept(host_token, trip_one, rider_c_id, 'AC')
        check('overlapping AC acceptance denied at one-seat capacity', status == 422, status=status, booking_id=ac_booking)

        status, matches, _, _ = search(rider_a_token, date_one, a, b, trip_one)
        check('AB search is exhausted after AB and BC acceptance', status == 200 and not any(item.get('trip_id') == trip_one for item in matches))
        status, matches, _, _ = search(rider_c_token, date_one, b, c, trip_one)
        check('BC search is exhausted after AB and BC acceptance', status == 200 and not any(item.get('trip_id') == trip_one for item in matches))
        status, _ = api('POST', f'trips/{trip_one}/requests/{rider_a_id}/cancel', {}, rider_a_token)
        check('AB cancellation succeeds', status == 200, status=status)
        status, matches, _, _ = search(rider_a_token, date_one, a, b, trip_one)
        check('AB search recovers after AB cancellation', status == 200 and any(item.get('trip_id') == trip_one for item in matches))
        status, matches, _, _ = search(rider_c_token, date_one, b, c, trip_one)
        check('BC remains unavailable after AB cancellation', status == 200 and not any(item.get('trip_id') == trip_one for item in matches))

        status, payload = api('POST', f'mobility/trips/{trip_one}/transitions', {'action': 'cancel'}, host_token, 's4-trip-cancel-' + uuid.uuid4().hex)
        check('canonical whole-trip cancellation remains available', status == 200 and payload.get('state') == 'cancelled', status=status)

        _, _, ac_one, _ = search(rider_a_token, date_two, a, c, trip_two)
        _, _, ac_two, _ = search(rider_b_token, date_two, a, c, trip_two)
        check('second trip has overlapping AC references', ac_one is not None and ac_two is not None)
        _, _, replay_ref, _ = search(rider_c_token, date_two, a, c, trip_two)
        check('fresh reference for concurrent request retry', replay_ref is not None)
        replay_key = 's4-replay-'+uuid.uuid4().hex
        retried = concurrent(lambda: api('POST', f"mobility/pool-matches/{replay_ref['match_id']}/requests", {}, rider_c_token, replay_key))
        retry_ids = [item[1].get('data', {}).get('booking_id') for item in retried]
        check('simultaneous same-key requests converge', sorted(item[0] for item in retried) == [200,201] and retry_ids[0] == retry_ids[1] and isinstance(retry_ids[0], int), statuses=[item[0] for item in retried], booking_id=retry_ids[0])
        report['replayed_match_id'] = replay_ref['match_id']
        booking_a = issue(rider_a_token, ac_one, 'race-a')
        booking_b = issue(rider_b_token, ac_two, 'race-b')
        race_lock = threading.Lock()
        race_riders = iter([rider_a_id, rider_b_id])

        def race_accept():
            with race_lock:
                rider_id = next(race_riders)
            return accept(host_token, trip_two, rider_id, 'overlap race')

        raced = concurrent(race_accept)
        statuses = sorted(item[0] for item in raced)
        check('overlapping host acceptance race has one winner', statuses == [200, 422], statuses=statuses)
        status, lifecycle = api('GET', f'mobility/trips/{trip_two}/lifecycle', token=host_token)
        participants = lifecycle.get('participants', [])
        accepted = [item for item in participants if item.get('booking_state') == 1]
        check('race leaves exactly one accepted booking', status == 200 and len(accepted) == 1, status=status, accepted=len(accepted), booking_ids=[booking_a, booking_b])
        winner = accepted[0].get('passenger_id')
        loser = rider_b_id if winner == rider_a_id else rider_a_id
        winner_token = rider_a_token if winner == rider_a_id else rider_b_token
        loser_token = rider_b_token if loser == rider_b_id else rider_a_token
        status, _ = api('POST', f'trips/{trip_two}/requests/{winner}/cancel', {}, winner_token)
        check('winning overlap booking cancels', status == 200, status=status)
        status, _ = accept(host_token, trip_two, loser)
        check('losing overlap booking can accept after winner cancellation', status == 200, status=status)
        report['booking_ids'] = {'trip_one': [ab_booking, bc_booking, ac_booking], 'trip_two': [booking_a, booking_b, retry_ids[0]]}
        report['race_winner_id'] = winner; report['race_loser_id'] = loser
        report['all_checks_passed'] = True
    finally:
        report['finished_at'] = datetime.now(ZoneInfo('UTC')).isoformat()
        args.output.parent.mkdir(parents=True, exist_ok=True)
        args.output.write_text(json.dumps(report, indent=2) + '\n')
    print(json.dumps({'output': str(args.output), 'checks': len(report['checks']), 'all_checks_passed': report.get('all_checks_passed', False), 'trip_ids': [report.get('trip_one_id'), report.get('trip_two_id')]}))


if __name__ == '__main__':
    main()
