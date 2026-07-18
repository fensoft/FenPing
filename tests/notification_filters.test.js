import assert from 'node:assert/strict';
import test from 'node:test';
import {
  filterNotificationCollections,
  normalizeNotificationFilters,
  notificationFilterDefaults,
  paginateItems
} from '../frontend/lib/notificationFilters.js';

const collections = {
  conflicts: [{ id: 'c1', important: 1 }, { id: 'c2', important: 0 }],
  status: [{ id: 's1', important: '1' }, { id: 's2', important: null }],
  services: [{ id: 'p1', important: true }, { id: 'p2', important: false }]
};

test('normalizes notification importance and type filters', () => {
  assert.deepEqual(normalizeNotificationFilters(null), notificationFilterDefaults);
  assert.deepEqual(normalizeNotificationFilters({ importance: 'important', type: 'services' }), { importance: 'important', type: 'services' });
  assert.deepEqual(normalizeNotificationFilters({ importance: 'invalid', type: 'invalid' }), notificationFilterDefaults);
});

test('filters every notification collection by importance', () => {
  const important = filterNotificationCollections(collections, { importance: 'important', type: 'all' });
  assert.deepEqual(important.conflicts.map(({ id }) => id), ['c1']);
  assert.deepEqual(important.status.map(({ id }) => id), ['s1']);
  assert.deepEqual(important.services.map(({ id }) => id), ['p1']);

  const normal = filterNotificationCollections(collections, { importance: 'normal', type: 'all' });
  assert.deepEqual(normal.conflicts.map(({ id }) => id), ['c2']);
  assert.deepEqual(normal.status.map(({ id }) => id), ['s2']);
  assert.deepEqual(normal.services.map(({ id }) => id), ['p2']);
});

test('notification type selection keeps only the selected collection', () => {
  assert.deepEqual(filterNotificationCollections(collections, { importance: 'all', type: 'status' }), {
    conflicts: [], status: collections.status, services: []
  });
});

test('paginates and clamps pages and supported page sizes', () => {
  const rows = Array.from({ length: 23 }, (_, index) => index + 1);
  assert.deepEqual(paginateItems(rows, 2, 10), {
    items: rows.slice(10, 20), page: 2, pageSize: 10, pages: 3, total: 23, first: 11, last: 20
  });
  assert.deepEqual(paginateItems(rows, 99, 10).items, rows.slice(20));
  assert.equal(paginateItems([], 4, 25).page, 1);
  assert.equal(paginateItems(rows, 1, 12).pageSize, 10);
});
