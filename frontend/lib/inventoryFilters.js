export const inventoryFilterDefaults = Object.freeze({
  search: '',
  status: 'all',
  importance: 'all',
  newness: 'all',
  tags: Object.freeze([])
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
    newness: normalizeChoice(stored.newness, allowedValues.newness, toFlag(stored.hideUnknown) ? 'known' : 'all'),
    tags: normalizeTags(stored.tags)
  };
}

export function inventoryFiltersActive(filters) {
  return filters.search.trim() !== ''
    || filters.status !== 'all'
    || filters.importance !== 'all'
    || filters.newness !== 'all'
    || filters.tags.length > 0;
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

  const hostTags = new Set(normalizeTags(host.tags).map((tag) => tag.toLowerCase()));
  if (filters.tags.some((tag) => !hostTags.has(tag.toLowerCase())))
    return false;

  const query = filters.search.trim().toLowerCase();
  return query === '' || [
    host.display_name,
    host.name,
    host.ip,
    host.mac,
    host.vendor,
    host.status,
    host.scan?.status,
    host.scan?.state,
    host.scan?.mode,
    host.notes,
    host.location,
    host.owner,
    host.model,
    host.device_identity?.network,
    host.device_identity?.container,
    normalizeTags(host.tags).join(' ')
  ].some((value) => String(value || '').toLowerCase().includes(query));
}

export function normalizeTags(value) {
  if (!Array.isArray(value)) return [];
  const tags = [];
  const seen = new Set();
  for (const item of value) {
    if (typeof item !== 'string') continue;
    const tag = item.trim();
    const key = tag.toLowerCase();
    if (!tag || seen.has(key)) continue;
    seen.add(key);
    tags.push(tag);
  }
  return tags.sort((left, right) => left.localeCompare(right, undefined, { sensitivity: 'base' }));
}

export function editableHostTags(host) {
  const source = Array.isArray(host?.stored_tags)
    ? host.stored_tags
    : host?.tags;
  return normalizeTags(source);
}

export function tagsEqual(left, right) {
  const normalizedLeft = normalizeTags(left).map((tag) => tag.toLowerCase());
  const normalizedRight = normalizeTags(right).map((tag) => tag.toLowerCase());
  return normalizedLeft.length === normalizedRight.length
    && normalizedLeft.every((tag, index) => tag === normalizedRight[index]);
}

export function matchingSavedInventoryFilter(tags, savedFilters) {
  return (Array.isArray(savedFilters) ? savedFilters : []).find((filter) => tagsEqual(tags, filter?.tags)) || null;
}

function normalizeChoice(value, allowed, fallback) {
  return typeof value === 'string' && allowed.has(value) ? value : fallback;
}

function toFlag(value) {
  return value === true || value === 1 || value === '1';
}
