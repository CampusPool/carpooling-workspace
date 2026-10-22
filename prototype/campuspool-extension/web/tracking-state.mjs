export const MAX_QUEUE = 20;

export function validatePosition(position) {
  const coords = position?.coords || {};
  const lat = Number(coords.latitude), lng = Number(coords.longitude), accuracy = Number(coords.accuracy);
  const timestamp = Number(position?.timestamp);
  if (![lat, lng, accuracy, timestamp].every(Number.isFinite) || lat < -90 || lat > 90 || lng < -180 || lng > 180 || accuracy <= 0 || accuracy > 500 || timestamp <= 0) return null;
  const date = new Date(timestamp);
  if (!Number.isFinite(date.getTime())) return null;
  return { lat, lng, accuracy_meters: accuracy, recorded_at: date.toISOString() };
}

export function enqueueSample(queue, sample, sequence) {
  const next = queue.length >= MAX_QUEUE ? queue.slice(1) : queue.slice();
  next.push({ ...sample, sequence });
  return next;
}

export function shouldDisplayLocation(payload, now = Date.now()) {
  const location = payload?.location;
  if (!location || payload?.stale) return false;
  const timestamp = Date.parse(String(location.recorded_at));
  return Number.isFinite(timestamp) && timestamp <= now && now - timestamp <= 30000;
}

export function isChronological(lastTimestamp, nextTimestamp) {
  return Number.isFinite(nextTimestamp) && nextTimestamp > (Number.isFinite(lastTimestamp) ? lastTimestamp : 0);
}

export function retryKey(trip, session, sequence) {
  return `web-tracking-location-${trip}-${session}-${sequence}`;
}
