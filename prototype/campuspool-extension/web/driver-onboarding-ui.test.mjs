import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';

const html = await readFile(new URL('./index.html', import.meta.url), 'utf8');
const js = await readFile(new URL('./app.js', import.meta.url), 'utf8');

test('driver onboarding screen exposes the applicant and operator surfaces', () => {
  for (const id of ['driver-tab', 'driver', 'driver-create-form', 'driver-vehicle-form', 'driver-onboarding-list']) {
    assert.match(html, new RegExp(`(?:id|data-view)=["']${id}["']`));
  }
  for (const id of ['driver-queue', 'driver-queue-filter', 'driver-queue-more']) assert.match(html, new RegExp(`id=["']${id}["']`));
  assert.match(html, /manual operator review/i);
  assert.match(html, /external verification is not performed/i);
});

test('client uses current onboarding contracts and protects stale versions', () => {
  for (const route of ['mobility/driver-onboarding', 'mobility/driver-onboarding/me', 'mobility/operator/driver-onboarding', 'mobility/operator/driver-onboarding/']) assert.match(js, new RegExp(route.replaceAll('/', '\\/')));
  assert.match(js, /expected_version/);
  assert.match(js, /VERSION_CONFLICT/);
  assert.match(js, /FormData\(\)/);
  assert.match(js, /URL\.createObjectURL/);
  assert.match(js, /URL\.revokeObjectURL/);
});

test('credentials remain memory-only and document data is never logged', () => {
  assert.match(js, /let token = null/);
  assert.doesNotMatch(js, /localStorage|sessionStorage|console\.(log|debug|info|warn|error)/);
  assert.match(js, /Authorization: 'Bearer ' \+ token/);
});
