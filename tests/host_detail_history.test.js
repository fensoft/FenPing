import assert from 'node:assert/strict';
import test from 'node:test';
import { filterHistoryRows } from '../frontend/lib/historyFilters.js';

const now = 1_800_000_000;

function row(id, begin, end, status = 'Up') {
  return { id, begin, end, duration: end - begin, status };
}

test('defaults can filter history to the last 24 hours', () => {
  const rows = [
    row(1, now - 30 * 60 * 60, now - 25 * 60 * 60),
    row(2, now - 20 * 60 * 60, now - 10 * 60 * 60, 'Down')
  ];

  assert.deepEqual(filterHistoryRows(rows, 24, now), [rows[1]]);
});

test('seven-day history includes rows outside the 24-hour view', () => {
  const rows = [
    row(1, now - 6 * 24 * 60 * 60, now - 5 * 24 * 60 * 60),
    row(2, now - 2 * 60 * 60, now)
  ];

  assert.deepEqual(filterHistoryRows(rows, 7 * 24, now), rows);
});

test('clips a status period crossing the selected cutoff', () => {
  const source = row(1, now - 30 * 60 * 60, now - 20 * 60 * 60, 'Down');
  const [filtered] = filterHistoryRows([source], 24, now);

  assert.equal(filtered.begin, now - 24 * 60 * 60);
  assert.equal(filtered.end, source.end);
  assert.equal(filtered.duration, 4 * 60 * 60);
  assert.equal(filtered.status, 'Down');
  assert.match(filtered.date_begin, /Z$/);
});

test('returns an empty array for empty or out-of-range history', () => {
  assert.deepEqual(filterHistoryRows([], 24, now), []);
  assert.deepEqual(filterHistoryRows([row(1, now - 48 * 60 * 60, now - 47 * 60 * 60)], 24, now), []);
});
