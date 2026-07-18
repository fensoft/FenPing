import assert from 'node:assert/strict';
import test from 'node:test';
import { formatActivityDuration } from '../frontend/lib/formatters.js';

const day = 24 * 60 * 60;

test('keeps compact activity durations through 365 days', () => {
  assert.equal(formatActivityDuration(365 * day), '365d');
  assert.equal(formatActivityDuration(7 * day + 3 * 60 * 60), '7d 3h');
});

test('shows years and remaining days for longer activity durations', () => {
  assert.equal(formatActivityDuration(366 * day), '1y 1d');
  assert.equal(formatActivityDuration((2 * 365 + 42) * day + 12 * 60 * 60), '2y 42d');
  assert.equal(formatActivityDuration(3 * 365 * day), '3y 0d');
});
