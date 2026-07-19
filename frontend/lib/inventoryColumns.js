export const inventoryColumnDefinitions = Object.freeze([
  Object.freeze({ key: 'device', label: 'Device', width: 17 }),
  Object.freeze({ key: 'ip', label: 'IP', width: 6 }),
  Object.freeze({ key: 'vendor', label: 'Vendor', width: 20 }),
  Object.freeze({ key: 'activity', label: 'Activity', width: 7 }),
  Object.freeze({ key: 'services', label: 'Services', width: 6 })
]);

export const inventoryLayoutStorageKey = 'fenping_inventory_layout_v1';

const definitionMap = new Map(inventoryColumnDefinitions.map((column) => [column.key, column]));

function clampNumber(value, minimum, maximum, fallback) {
  const number = Number(value);
  return Number.isFinite(number) ? Math.min(maximum, Math.max(minimum, number)) : fallback;
}

export function defaultInventoryLayout() {
  return {
    columns: inventoryColumnDefinitions.map((column) => ({ key: column.key, visible: true, width: column.width })),
    downRecentDays: 7,
    downOlderDays: 30
  };
}

export function normalizeInventoryLayout(value) {
  const defaults = defaultInventoryLayout();
  const input = value && typeof value === 'object' ? value : {};
  const sourceColumns = Array.isArray(input.columns) ? input.columns : [];
  const columns = [];
  const used = new Set();

  for (const item of sourceColumns) {
    const definition = definitionMap.get(item?.key);
    if (!definition || used.has(definition.key)) continue;
    used.add(definition.key);
    columns.push({
      key: definition.key,
      visible: item.visible !== false,
      width: clampNumber(item.width, 5, 60, definition.width)
    });
  }

  for (const definition of inventoryColumnDefinitions) {
    if (!used.has(definition.key))
      columns.push({ key: definition.key, visible: true, width: definition.width });
  }

  if (!columns.some((column) => column.visible))
    columns.find((column) => column.key === 'device').visible = true;

  const downRecentDays = Math.round(clampNumber(input.downRecentDays, 1, 364, defaults.downRecentDays));
  const downOlderDays = Math.round(clampNumber(input.downOlderDays, downRecentDays + 1, 3650, Math.max(defaults.downOlderDays, downRecentDays + 1)));
  return { columns, downRecentDays, downOlderDays };
}

export function updateInventoryColumn(layout, key, changes) {
  const normalized = normalizeInventoryLayout(layout);
  return normalizeInventoryLayout({
    ...normalized,
    columns: normalized.columns.map((column) => column.key === key ? { ...column, ...changes } : column)
  });
}

export function reorderInventoryColumns(layout, sourceKey, targetKey) {
  const normalized = normalizeInventoryLayout(layout);
  const sourceIndex = normalized.columns.findIndex((column) => column.key === sourceKey);
  const targetIndex = normalized.columns.findIndex((column) => column.key === targetKey);
  if (sourceIndex < 0 || targetIndex < 0 || sourceIndex === targetIndex) return normalized;
  const columns = normalized.columns.slice();
  const [source] = columns.splice(sourceIndex, 1);
  columns.splice(targetIndex, 0, source);
  return { ...normalized, columns };
}

export function moveInventoryColumn(layout, key, offset) {
  const normalized = normalizeInventoryLayout(layout);
  const sourceIndex = normalized.columns.findIndex((column) => column.key === key);
  const targetIndex = Math.min(normalized.columns.length - 1, Math.max(0, sourceIndex + Number(offset || 0)));
  if (sourceIndex < 0 || sourceIndex === targetIndex) return normalized;
  const columns = normalized.columns.slice();
  const [source] = columns.splice(sourceIndex, 1);
  columns.splice(targetIndex, 0, source);
  return { ...normalized, columns };
}

export function inventoryColumnDefinition(key) {
  return definitionMap.get(key) || null;
}
