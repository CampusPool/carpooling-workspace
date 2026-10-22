import assert from 'node:assert/strict';
import test from 'node:test';
import { enqueueSample, isChronological, MAX_QUEUE, retryKey, shouldDisplayLocation, validatePosition } from './tracking-state.mjs';

test('rejects inaccurate or out-of-range browser samples and preserves UTC milliseconds', () => {
  assert.equal(validatePosition({ timestamp: Date.parse('2026-10-21T10:00:00.123Z'), coords: { latitude: 13, longitude: 77, accuracy: 501 } }), null);
  const sample = validatePosition({ timestamp: Date.parse('2026-10-21T10:00:00.123Z'), coords: { latitude: 13, longitude: 77, accuracy: 8 } });
  assert.equal(sample.recorded_at, '2026-10-21T10:00:00.123Z');
});

test('keeps a bounded queue and drops only the oldest sample', () => {
  let queue = [];
  for (let sequence = 1; sequence <= MAX_QUEUE + 2; sequence++) queue = enqueueSample(queue, { sequence }, sequence);
  assert.equal(queue.length, MAX_QUEUE);
  assert.equal(queue[0].sequence, 3);
  assert.equal(queue.at(-1).sequence, MAX_QUEUE + 2);
});

test('suppresses stale/future reader coordinates and keeps retry identity stable', () => {
  const now = Date.parse('2026-10-21T10:00:30.001Z');
  assert.equal(shouldDisplayLocation({ location: { recorded_at: '2026-10-21T10:00:00.000Z' } }, now), false);
  assert.equal(shouldDisplayLocation({ location: { recorded_at: '2026-10-21T10:00:20.000Z' } }, now), true);
  assert.equal(shouldDisplayLocation({ location: { recorded_at: '2026-10-21T10:01:00.000Z' } }, now), false);
  assert.equal(retryKey(4, 'session', 2), retryKey(4, 'session', 2));
});

test('rejects a GPS timestamp that would block the ordered server stream', () => {
  assert.equal(isChronological(1000, 999), false);
  assert.equal(isChronological(1000, 1000), false);
  assert.equal(isChronological(1000, 1001), true);
});
