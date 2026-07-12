export const inventoryFilterDefaults = Object.freeze({
  search: '',
  status: 'all',
  importance: 'all',
  newness: 'all'
});

const allowedValues = Object.freeze({
  status: new Set(['down', 'all', 'up']),
  importance: new Set(['normal', 'all', 'important']),
  newness: new Set(['known', 'all', 'new'])
});

export function normalizeInventoryFilters(value) {
  const stored = value && typeof value === 'object' && !Array.isArray(value) ? value : {};
  return {
    search: typeof stored.search === 'string' ? stored.search : '',
    status: normalizeChoice(stored.status, allowedValues.status, toFlag(stored.onlyDown) ? 'down' : 'all'),
    importance: normalizeChoice(stored.importance, allowedValues.importance, toFlag(stored.onlyImportant) ? 'important' : 'all'),
    newness: normalizeChoice(stored.newness, allowedValues.newness, toFlag(stored.hideUnknown) ? 'known' : 'all')
  };
}

export function inventoryFiltersActive(filters) {
  return filters.search.trim() !== ''
    || filters.status !== 'all'
    || filters.importance !== 'all'
    || filters.newness !== 'all';
}

export function inventoryHostMatches(host, filters) {
  if (filters.status === 'up' && host.status !== 'Up') return false;
  if (filters.status === 'down' && host.status === 'Up') return false;

  const important = toFlag(host.important);
  if (filters.importance === 'important' && !important) return false;
  if (filters.importance === 'normal' && important) return false;

  const isNew = toFlag(host.is_new);
  if (filters.newness === 'new' && !isNew) return false;
  if (filters.newness === 'known' && isNew) return false;

  const query = filters.search.trim().toLowerCase();
  return query === '' || [
    host.name,
    host.ip,
    host.mac,
    host.vendor,
    host.status,
    host.scan?.status,
    host.scan?.state,
    host.scan?.mode
  ].some((value) => String(value || '').toLowerCase().includes(query));
}

function normalizeChoice(value, allowed, fallback) {
  return typeof value === 'string' && allowed.has(value) ? value : fallback;
}

function toFlag(value) {
  return value === true || value === 1 || value === '1';
}
