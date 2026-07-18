export const notificationFilterDefaults = Object.freeze({
  importance: 'all',
  type: 'all'
});

const importanceValues = new Set(['normal', 'all', 'important']);
const typeValues = new Set(['all', 'conflicts', 'status', 'services']);

export function normalizeNotificationFilters(value) {
  const source = value && typeof value === 'object' && !Array.isArray(value) ? value : {};
  return {
    importance: importanceValues.has(source.importance) ? source.importance : 'all',
    type: typeValues.has(source.type) ? source.type : 'all'
  };
}

export function filterNotificationCollections(collections, filters) {
  const normalized = normalizeNotificationFilters(filters);
  const include = (row) => {
    const important = row?.important === true || row?.important === 1 || row?.important === '1';
    if (normalized.importance === 'important') return important;
    if (normalized.importance === 'normal') return !important;
    return true;
  };
  return {
    conflicts: normalized.type === 'all' || normalized.type === 'conflicts'
      ? array(collections?.conflicts).filter(include) : [],
    status: normalized.type === 'all' || normalized.type === 'status'
      ? array(collections?.status).filter(include) : [],
    services: normalized.type === 'all' || normalized.type === 'services'
      ? array(collections?.services).filter(include) : []
  };
}

export function paginateItems(items, requestedPage = 1, requestedPageSize = 10) {
  const rows = array(items);
  const pageSize = [10, 25, 50].includes(Number(requestedPageSize)) ? Number(requestedPageSize) : 10;
  const pages = Math.max(1, Math.ceil(rows.length / pageSize));
  const page = Math.max(1, Math.min(pages, Math.trunc(Number(requestedPage)) || 1));
  const first = rows.length === 0 ? 0 : (page - 1) * pageSize + 1;
  const last = Math.min(page * pageSize, rows.length);
  return {
    items: rows.slice(first === 0 ? 0 : first - 1, last),
    page,
    pageSize,
    pages,
    total: rows.length,
    first,
    last
  };
}

function array(value) {
  return Array.isArray(value) ? value : [];
}
