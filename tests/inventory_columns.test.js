import assert from 'node:assert/strict';
import test from 'node:test';
import { defaultInventoryLayout, moveInventoryColumn, normalizeInventoryLayout, reorderInventoryColumns, updateInventoryColumn } from '../frontend/lib/inventoryColumns.js';

test('normalizes saved inventory layouts and preserves a visible column', () => {
  const layout = normalizeInventoryLayout({
    columns: [
      { key: 'vendor', visible: false, width: 100 },
      { key: 'vendor', visible: true, width: 20 },
      { key: 'unknown', visible: true, width: 10 },
      { key: 'device', visible: false, width: 2 },
      { key: 'ip', visible: false, width: 18 },
      { key: 'activity', visible: false, width: 17 },
      { key: 'services', visible: false, width: 17 }
    ],
    downRecentDays: 12,
    downOlderDays: 5
  });

  assert.deepEqual(layout.columns.map((column) => column.key), ['vendor', 'device', 'ip', 'activity', 'services']);
  assert.equal(layout.columns[0].width, 60);
  assert.equal(layout.columns.find((column) => column.key === 'device').width, 5);
  assert.equal(layout.columns.find((column) => column.key === 'device').visible, true);
  assert.equal(layout.downRecentDays, 12);
  assert.equal(layout.downOlderDays, 13);
});

test('updates, moves, and reorders inventory columns immutably', () => {
  const defaults = defaultInventoryLayout();
  const resized = updateInventoryColumn(defaults, 'ip', { width: 33, visible: false });
  const moved = moveInventoryColumn(resized, 'services', -2);
  const reordered = reorderInventoryColumns(moved, 'vendor', 'device');

  assert.equal(defaults.columns.find((column) => column.key === 'ip').width, 6);
  assert.deepEqual(reordered.columns.map((column) => column.key), ['vendor', 'device', 'ip', 'services', 'activity']);
  assert.deepEqual(reordered.columns.find((column) => column.key === 'ip'), { key: 'ip', width: 33, visible: false });
});
