import assert from 'node:assert/strict';
import test from 'node:test';
import {
  editableHostTags,
  inventoryFilterDefaults,
  inventoryFiltersActive,
  inventoryHostMatches,
  matchingSavedInventoryFilter,
  normalizeTags,
  tagsEqual,
  normalizeInventoryFilters
} from '../frontend/lib/inventoryFilters.js';

const hosts = [
  { name: 'server', ip: '192.0.2.10', mac: '00:11:22:33:44:10', vendor: 'Example', status: 'Up', important: 1, is_new: 0, tags: ['Server', 'Core'], owner: 'Platform', location: 'Rack 4', model: 'PowerEdge', notes: 'Primary database', scan: { mode: 'deep', state: 'complete', status: 'up' } },
  { name: 'printer', ip: '192.0.2.11', mac: '00:11:22:33:44:11', vendor: 'Print Corp', status: 'Down', important: 0, is_new: 0, tags: ['Printer', 'Office'] },
  { name: 'access-point', ip: '192.0.2.12', mac: '00:11:22:33:44:12', vendor: 'Wireless Inc', status: 'arp', important: '1', is_new: '1' },
  { name: 'camera', ip: '192.0.2.13', mac: '00:11:22:33:44:13', vendor: 'Vision', status: 'arp-down', important: null, is_new: true, tags: ['Camera', 'Outdoor'] },
  { name: 'unknown', ip: '192.0.2.14', mac: '', vendor: '', status: '', important: false, is_new: false }
];

function filter(overrides = {}) {
  return { ...inventoryFilterDefaults, ...overrides };
}

function names(filters) {
  return hosts.filter((host) => inventoryHostMatches(host, filters)).map((host) => host.name);
}

test('normalizes empty, current, and legacy stored filters', () => {
  assert.deepEqual(normalizeInventoryFilters(null), inventoryFilterDefaults);
  assert.deepEqual(
    normalizeInventoryFilters({ search: 'cam', status: 'up', importance: 'normal', newness: 'new', tags: [' Camera ', 'camera', 'Outdoor'] }),
    { search: 'cam', status: 'up', importance: 'normal', newness: 'new', tags: ['Camera', 'Outdoor'] }
  );
  assert.deepEqual(
    normalizeInventoryFilters({ search: 'old', onlyDown: true, onlyImportant: 1, hideUnknown: '1' }),
    { search: 'old', status: 'down', importance: 'important', newness: 'known', tags: [] }
  );
  assert.deepEqual(
    normalizeInventoryFilters({ search: 42, status: 'invalid', importance: 'invalid', newness: 'invalid' }),
    inventoryFilterDefaults
  );
});

test('normalized filters survive persistence round trips', () => {
  const filters = normalizeInventoryFilters({ search: 'server', status: 'down', importance: 'normal', newness: 'known', tags: ['Core'] });
  assert.deepEqual(normalizeInventoryFilters(JSON.parse(JSON.stringify(filters))), filters);
});

test('detects active filters and ignores whitespace-only search', () => {
  assert.equal(inventoryFiltersActive(filter()), false);
  assert.equal(inventoryFiltersActive(filter({ search: '   ' })), false);
  assert.equal(inventoryFiltersActive(filter({ status: 'down' })), true);
  assert.equal(inventoryFiltersActive(filter({ importance: 'important' })), true);
  assert.equal(inventoryFiltersActive(filter({ newness: 'new' })), true);
  assert.equal(inventoryFiltersActive(filter({ tags: ['server'] })), true);
});

test('status tri-state treats only exact Up as up', () => {
  assert.deepEqual(names(filter({ status: 'up' })), ['server']);
  assert.deepEqual(names(filter({ status: 'down' })), ['printer', 'access-point', 'camera', 'unknown']);
  assert.deepEqual(names(filter({ status: 'all' })), hosts.map((host) => host.name));
});

test('importance and newness tri-states include both sides', () => {
  assert.deepEqual(names(filter({ importance: 'important' })), ['server', 'access-point']);
  assert.deepEqual(names(filter({ importance: 'normal' })), ['printer', 'camera', 'unknown']);
  assert.deepEqual(names(filter({ newness: 'new' })), ['access-point', 'camera']);
  assert.deepEqual(names(filter({ newness: 'known' })), ['server', 'printer', 'unknown']);
});

test('combines tri-state filters and search with AND semantics', () => {
  assert.deepEqual(names(filter({ status: 'down', importance: 'important', newness: 'new' })), ['access-point']);
  assert.deepEqual(names(filter({ status: 'down', importance: 'normal', newness: 'known', search: 'print corp' })), ['printer']);
  assert.deepEqual(names(filter({ search: 'DEEP' })), ['server']);
  assert.deepEqual(names(filter({ status: 'up', search: 'camera' })), []);
});
test('search includes metadata and tags', () => {
  assert.deepEqual(names(filter({ search: 'platform' })), ['server']);
  assert.deepEqual(names(filter({ search: 'rack 4' })), ['server']);
  assert.deepEqual(names(filter({ search: 'poweredge' })), ['server']);
  assert.deepEqual(names(filter({ search: 'primary database' })), ['server']);
  assert.deepEqual(names(filter({ search: 'outdoor' })), ['camera']);
});

test('tag filters use case-insensitive all-tag matching', () => {
  assert.deepEqual(names(filter({ tags: ['server'] })), ['server']);
  assert.deepEqual(names(filter({ tags: ['CORE', 'server'] })), ['server']);
  assert.deepEqual(names(filter({ tags: ['office', 'printer'] })), ['printer']);
  assert.deepEqual(names(filter({ tags: ['camera', 'server'] })), []);
});

test('normalizes tags and matches saved views by exact tag set', () => {
  assert.deepEqual(normalizeTags([' Server ', 'server', '', 42, 'Core']), ['Core', 'Server']);
  assert.equal(tagsEqual(['server', 'Core'], ['CORE', 'SERVER']), true);
  const views = [{ id: 1, tags: ['Printer'] }, { id: 2, tags: ['Core', 'Server'] }];
  assert.equal(matchingSavedInventoryFilter(['server', 'core'], views)?.id, 2);
  assert.equal(matchingSavedInventoryFilter(['server'], views), null);
});

test('keeps automatic Docker tags out of editable metadata', () => {
  assert.deepEqual(
    editableHostTags({ tags: ['bridge-camera', 'Outdoor'], stored_tags: ['Outdoor'] }),
    ['Outdoor']
  );
  assert.deepEqual(editableHostTags({ tags: ['Legacy'] }), ['Legacy']);
  assert.deepEqual(editableHostTags({ tags: ['gateway'], stored_tags: [] }), []);
});
