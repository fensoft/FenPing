<template>
  <section>
    <div v-if="error" class="alert alert-danger mb-3" role="alert">{{ error }}</div>
    <div class="table-wrap">
      <div class="table-toolbar">
        <div class="input-icon filter-search"><span class="input-icon-addon"><AppIcon name="search" /></span><input v-model="filters.search" class="form-control form-control-sm" type="search" placeholder="Search devices" /></div>
        <fieldset v-for="group in filterGroups" :key="group.key" class="filter-segment">
          <legend class="visually-hidden">{{ group.label }}</legend>
          <div class="btn-group" role="group" :aria-label="group.label">
            <template v-for="option in group.options" :key="option.value">
              <input :id="`inventory-filter-${group.key}-${option.value}`" v-model="filters[group.key]" class="btn-check" type="radio" :name="`inventory-filter-${group.key}`" :value="option.value" autocomplete="off" />
              <label class="btn btn-outline-secondary btn-sm" :for="`inventory-filter-${group.key}-${option.value}`">{{ option.label }}</label>
            </template>
          </div>
        </fieldset>
        <button v-if="hasActiveFilters" class="btn btn-outline-secondary btn-sm icon-btn" type="button" title="Clear filters" @click="resetFilters"><AppIcon name="filter-x" /></button>
        <div class="inventory-summary" aria-live="polite"><strong>{{ inventorySummary.devices }}</strong> devices <span aria-hidden="true">·</span> <span class="inventory-summary-online">{{ inventorySummary.online }} online</span> <span v-if="inventorySummary.newDevices > 0" aria-hidden="true">·</span> <span v-if="inventorySummary.newDevices > 0" class="inventory-summary-new">{{ inventorySummary.newDevices }} new</span></div>
      </div>
      <table class="table table-sm inventory-table">
        <colgroup><col class="col-status" /><col class="col-device" /><col class="col-ip" /><col class="col-vendor" /><col class="col-activity" /><col class="col-services" /><col v-if="isAuthenticated" class="col-actions" /></colgroup>
        <thead><tr>
          <th scope="col"><button class="btn btn-outline-secondary btn-sm icon-btn" type="button" :title="allCategoriesCollapsed ? 'Open all categories' : 'Close all categories'" :aria-label="allCategoriesCollapsed ? 'Open all categories' : 'Close all categories'" :disabled="categoryKeys.length === 0" @click="toggleAllCategories"><AppIcon :name="allCategoriesCollapsed ? 'plus' : 'minus'" /></button></th>
          <th scope="col">Device</th><th scope="col">IP</th><th scope="col" class="inventory-vendor-column">Vendor</th><th scope="col" class="inventory-activity-column">Activity</th><th scope="col" class="inventory-services-column">Services</th>
          <th v-if="isAuthenticated" scope="col" class="inventory-actions-heading"><div class="inventory-actions-header"><span>Actions</span><button class="btn btn-outline-primary btn-sm icon-btn" type="button" title="Add category" aria-label="Add category" @click="$emit('add-category')"><AppIcon name="folder-plus" /></button></div></th>
        </tr></thead>
        <tbody>
          <tr v-if="loading && tableRows.length === 0"><td class="text-secondary text-center py-4" :colspan="isAuthenticated ? 7 : 6">Loading</td></tr>
          <tr v-else-if="!loading && tableRows.length === 0"><td class="text-secondary text-center py-4" :colspan="isAuthenticated ? 7 : 6">No hosts</td></tr>
          <tr v-for="row in tableRows" :key="row.key" :class="[rowClass(row), { 'inventory-host-row': row.type === 'host', 'inventory-host-row-collapsed': row.type === 'host' && row.collapsed }]" :tabindex="row.type === 'category' || (row.type === 'host' && !row.collapsed && (row.host?.id || row.host?.ip)) ? 0 : undefined" :aria-label="rowAriaLabel(row)" :aria-expanded="row.type === 'category' ? !row.collapsed : undefined" :aria-hidden="row.type === 'host' && row.collapsed ? 'true' : undefined" @click="activateRow(row, $event)" @keydown.enter="activateRow(row, $event)" @keydown.space="activateCategoryRow(row, $event)">
            <template v-if="row.type === 'category'">
              <td><button class="btn category-toggle" type="button" :title="row.collapsed ? 'Open category' : 'Close category'" :aria-label="row.collapsed ? 'Open category' : 'Close category'" @click="toggleCategory(row.categoryKey)"><AppIcon :name="row.collapsed ? 'chevron-right' : 'chevron-down'" /></button></td>
              <td class="category-name" colspan="5"><span class="category-title">{{ row.name }}</span><span class="category-summary">{{ row.total }} {{ row.total === 1 ? 'device' : 'devices' }} <span aria-hidden="true">·</span> {{ row.online }} online</span></td>
              <td v-if="isAuthenticated" class="text-end category-actions">
                <div class="inventory-actions">
                  <button v-if="isAuthenticated && row.categoryIp" class="btn btn-outline-info btn-sm icon-btn" type="button" title="Rename category" aria-label="Rename category" @click="$emit('rename-category', row)"><AppIcon name="pencil" /></button>
                  <button v-if="isAuthenticated && row.categoryIp" class="btn btn-outline-danger btn-sm icon-btn" type="button" title="Delete category" aria-label="Delete category" @click="$emit('delete-category', row)"><AppIcon name="trash" /></button>
                </div>
              </td>
            </template>
            <template v-else>
              <td class="status-cell"><div class="status-icons">
                <span :class="statusClass(row.host.status)" :title="statusTitle(row.host.status)" :aria-label="statusTitle(row.host.status)" class="status-pill" role="img"><AppIcon :name="statusIcon(row.host.status)" /></span>
              </div></td>
              <td class="inventory-device-cell text-truncate-cell" :title="deviceTitle(row.host)">
                <span class="host-name-value">{{ row.host.name || row.host.ip || formatMac(row.host.mac) }}</span>
                <AppIcon v-if="toFlag(row.host.is_new)" name="alert-triangle" class="text-warning host-role-icon" title="New device — approve it in IPAM" />
                <AppIcon v-if="toFlag(row.host.repeater)" name="wifi" class="text-secondary host-role-icon" title="Router/repeater" />
                <AppIcon v-if="row.host.via" name="antenna-bars-5" class="text-secondary host-role-icon" :title="row.host.via" />
                <a v-if="row.host.web == 1 && row.host.ip" class="host-web-link" :href="`http://${row.host.ip}`" target="_blank" rel="noopener noreferrer" title="Open web interface" aria-label="Open web interface"><AppIcon name="external-link" /></a>
                <span class="inventory-mobile-meta">{{ mobileMeta(row.host) }}</span>
              </td>
              <td class="text-truncate-cell font-monospace inventory-ip-cell" :title="row.host.ip || ''">{{ row.host.ip }}</td>
              <td class="text-truncate-cell inventory-vendor-column" :title="row.host.vendor || 'Unknown vendor'">{{ row.host.vendor || 'Unknown' }}</td>
              <td class="text-truncate-cell inventory-activity-column" :title="activityTitle(row.host)">{{ activityLabel(row.host) }}</td>
              <td class="text-truncate-cell inventory-services-column" :title="serviceTitle(row.host)">{{ serviceLabel(row.host) }}</td>
              <td v-if="isAuthenticated" class="text-end action-cell">
                <div class="inventory-actions">
                  <button v-if="row.host.ip" class="btn btn-sm inventory-action-btn inventory-scan-btn" :class="scanActionClass(row.host)" type="button" :title="scanButtonTitle(row.host)" :aria-label="scanButtonTitle(row.host)" :disabled="isScanRunning(row.host)" @click="$emit('scan-host', row.host)"><AppIcon :name="isScanRunning(row.host) ? 'loader-2' : 'search'" /><span class="inventory-action-label">{{ scanButtonLabel(row.host) }}</span></button>
                  <button v-if="isAuthenticated && row.host.id" class="btn btn-outline-warning btn-sm icon-btn" type="button" title="Edit host" aria-label="Edit host" @click="$emit('open-edit', row.host)"><AppIcon name="edit" /></button>
                  <button v-else-if="isAuthenticated && row.host.mac" class="btn btn-outline-primary btn-sm icon-btn" type="button" title="Create host" aria-label="Create host" @click="$emit('open-create', row.host)"><AppIcon name="plus" /></button>
                </div>
              </td>
            </template>
          </tr>
        </tbody>
      </table>
    </div>
  </section>
</template>

<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { apiJson, isAbortError } from '../lib/api.js';
import { inventoryFilterDefaults, inventoryFiltersActive, inventoryHostMatches, normalizeInventoryFilters } from '../lib/inventoryFilters.js';
import { useAbortableTask } from '../composables/useAbortableTask.js';
import { useNow } from '../composables/useNow.js';
import { usePageController } from '../composables/usePageController.js';
import { formatDuration, formatMac, formatServerDate, parseServerDate, scanIsActiveState, statusClass, statusIcon, statusTitle, toFlag } from '../lib/formatters.js';

defineOptions({ inheritAttrs: false });
const props = defineProps({
  isAuthenticated: Boolean,
  scanning: Boolean,
  refreshQueued: Boolean,
  scanningHosts: { type: Object, required: true }
});
const emit = defineEmits(['add-category', 'delete-category', 'host-detail', 'network', 'open-create', 'open-edit', 'open-history', 'ping-refresh', 'rename-category', 'scan-host']);
const hosts = ref([]);
const loading = ref(false);
const error = ref('');
const request = useAbortableTask();
const now = useNow(30000);
const filterGroups = Object.freeze([
  Object.freeze({ key: 'status', label: 'Status filter', options: Object.freeze([{ value: 'down', label: 'Down' }, { value: 'all', label: 'All' }, { value: 'up', label: 'Up' }]) }),
  Object.freeze({ key: 'importance', label: 'Importance filter', options: Object.freeze([{ value: 'normal', label: 'Normal' }, { value: 'all', label: 'All' }, { value: 'important', label: 'Important' }]) }),
  Object.freeze({ key: 'newness', label: 'New-device filter', options: Object.freeze([{ value: 'known', label: 'Known' }, { value: 'all', label: 'All' }, { value: 'new', label: 'New' }]) })
]);
const filters = ref(normalizeInventoryFilters(readStorage('fenping_filters', inventoryFilterDefaults)));
writeStorage('fenping_filters', filters.value);
const collapsed = ref(new Set(readStorage('fenping_collapsed_categories', [])));
const hasActiveFilters = computed(() => inventoryFiltersActive(filters.value));
const categorizedHosts = computed(() => {
  const rows = []; let category = null;
  for (const host of hosts.value) {
    if (host.category) category = { key: categoryKey(host), name: host.category, ip: host.category_ip };
    rows.push({ ...host, categoryContext: category });
  }
  return rows;
});
const visibleHosts = computed(() => categorizedHosts.value.filter((host) => inventoryHostMatches(host, filters.value)));
const inventorySummary = computed(() => ({
  devices: hasActiveFilters.value ? `${visibleHosts.value.length}/${hosts.value.length}` : hosts.value.length,
  online: visibleHosts.value.filter(isOnline).length,
  newDevices: visibleHosts.value.filter((host) => toFlag(host.is_new)).length
}));
const categoryKeys = computed(() => Array.from(new Set(categorizedHosts.value.map((host) => host.categoryContext?.key).filter(Boolean))));
const categorySummaries = computed(() => {
  const summaries = new Map();
  for (const host of visibleHosts.value) {
    const key = host.categoryContext?.key;
    if (!key) continue;
    const summary = summaries.get(key) || { total: 0, online: 0 };
    summary.total++;
    if (isOnline(host)) summary.online++;
    summaries.set(key, summary);
  }
  return summaries;
});
const allCategoriesCollapsed = computed(() => categoryKeys.value.length > 0 && categoryKeys.value.every((key) => collapsed.value.has(key)));
const tableRows = computed(() => {
  const rows = []; let current = '';
  for (const host of visibleHosts.value) {
    const category = host.categoryContext;
    if (category && category.key !== current) {
      current = category.key;
      rows.push({ type: 'category', key: current, categoryKey: current, name: category.name, categoryIp: category.ip, collapsed: collapsed.value.has(current), ...(categorySummaries.value.get(current) || { total: 0, online: 0 }) });
    }
    rows.push({ type: 'host', key: `host-${host.id || host.ip || host.mac}`, host, collapsed: current !== '' && collapsed.value.has(current) });
  }
  return rows;
});

usePageController({
  loading,
  label: computed(() => loading.value ? 'Loading' : !props.isAuthenticated ? 'Read only' : props.scanning && props.refreshQueued ? 'Queued' : props.scanning ? 'Scanning' : 'Ready'),
  title: computed(() => props.isAuthenticated ? 'Refresh' : 'Login to refresh'),
  disabled: computed(() => !props.isAuthenticated),
  refresh: () => emit('ping-refresh'),
  reload: load
});
watch(filters, (value) => writeStorage('fenping_filters', value), { deep: true });
watch(collapsed, (value) => writeStorage('fenping_collapsed_categories', Array.from(value)));
onMounted(load);

async function load() {
  const signal = request.nextSignal(); loading.value = true; error.value = '';
  try {
    const data = await apiJson('/api/inventory', { signal });
    if (!request.isCurrent(signal)) return;
    emit('network', data.network || ''); hosts.value = data.hosts || [];
  } catch (loadError) {
    if (!isAbortError(loadError) && request.isCurrent(signal)) error.value = loadError.message;
  } finally { if (request.isCurrent(signal)) loading.value = false; }
}

function readStorage(name, fallback) { try { const value = localStorage.getItem(name); return value ? JSON.parse(value) : fallback; } catch { return fallback; } }
function writeStorage(name, value) { try { localStorage.setItem(name, JSON.stringify(value)); } catch {} }
function resetFilters() { filters.value = { ...inventoryFilterDefaults }; }
function categoryKey(host) { return `category-${host.category_ip || host.category}`; }
function toggleCategory(key) { const next = new Set(collapsed.value); next.has(key) ? next.delete(key) : next.add(key); collapsed.value = next; }
function toggleAllCategories() { collapsed.value = allCategoriesCollapsed.value ? new Set() : new Set(categoryKeys.value); }
function hostKey(host) { return String(host?.ip || host?.id || host?.mac || ''); }
function isScanningHost(host) { const key = hostKey(host); return key !== '' && props.scanningHosts.has(key); }
function isScanRunning(host) { return isScanningHost(host) || scanIsActiveState(host?.scan?.state); }
function scanActionClass(host) { return { 'btn-outline-primary': !['failed', 'timeout'].includes(host?.scan?.state), 'btn-outline-danger': host?.scan?.state === 'failed', 'btn-outline-warning': host?.scan?.state === 'timeout', 'is-spinning': isScanRunning(host) }; }
function scanButtonLabel(host) {
  if (host?.scan?.state === 'queued') return 'Queued';
  if (isScanRunning(host)) return 'Scanning';
  if (['failed', 'timeout'].includes(host?.scan?.state)) return 'Retry';
  return 'Scan';
}
function scanButtonTitle(host) {
  if (host?.scan?.state === 'queued') return 'Scan queued';
  if (isScanRunning(host)) return 'Scanning';
  if (host?.scan?.state === 'failed') return `Scan failed${host.scan.error ? `: ${host.scan.error}` : ''}`;
  if (host?.scan?.state === 'timeout') return `Scan timed out${host.scan.error ? `: ${host.scan.error}` : ''}`;
  return host?.scan?.date_end ? `Scan host, last ${formatServerDate(host.scan.date_end)}` : 'Scan host';
}
function isOnline(host) { return ['Up', 'arp'].includes(host?.status); }
function activityAge(host) {
  const timestamp = parseServerDate(host?.date);
  return Number.isNaN(timestamp) ? null : Math.max(0, Math.floor((now.value - timestamp) / 1000));
}
function activityLabel(host) {
  if (!isOnline(host) && host?.stability?.current_seconds !== undefined)
    return `Down ${formatDuration(host.stability.current_seconds)}`;
  const age = activityAge(host);
  if (age === null) return host?.status || '-';
  return `${isOnline(host) ? 'Seen' : 'Checked'} ${formatDuration(age)} ago`;
}
function activityTitle(host) {
  const parts = [activityLabel(host)];
  if (host?.date) parts.push(formatServerDate(host.date));
  if (host?.stability) parts.push(`Uptime ${Math.round(Number(host.stability.uptime_percent || 0))}%`, `${Number(host.stability.transitions || 0)} changes`);
  return parts.join(' | ');
}
function serviceLabel(host) {
  if (isScanRunning(host)) return host?.scan?.state === 'queued' ? 'Queued' : 'Scanning';
  if (host?.scan?.state === 'failed') return 'Scan failed';
  if (host?.scan?.state === 'timeout') return 'Timed out';
  if (!host?.scan?.result_available && !host?.scan?.snapshot_id) return '-';
  const count = Number(host?.scan?.ports_count || 0);
  return `${count} ${count === 1 ? 'service' : 'services'}`;
}
function serviceTitle(host) {
  if (!host?.scan) return 'No scan available';
  const last = host.scan.date_end || host.scan.date_begin;
  return `${serviceLabel(host)}${last ? ` | Last scan ${formatServerDate(last)}` : ''}`;
}
function deviceTitle(host) {
  return [host?.name, formatMac(host?.mac), host?.vendor].filter(Boolean).join(' | ');
}
function mobileMeta(host) {
  return [host?.vendor || 'Unknown vendor', formatMac(host?.mac), activityLabel(host), serviceLabel(host)].filter(Boolean).join(' · ');
}
function rowClass(row) { return row.type === 'category' ? 'category-row' : row.host.important == 1 && row.host.status !== 'Up' ? 'important-down' : ''; }
function rowAriaLabel(row) {
  if (row.type === 'category') return `${row.collapsed ? 'Open' : 'Close'} category ${row.name}`;
  return !row.collapsed && (row.host?.id || row.host?.ip) ? `Details for ${row.host.name || row.host.ip || row.host.mac}` : undefined;
}
function activateRow(row, event) {
  if (event.target !== event.currentTarget && event.target.closest('a, button, input, select, textarea, label')) return;
  if (row.type === 'category') { toggleCategory(row.categoryKey); return; }
  if (!row.host.id && !row.host.ip) return;
  emit('host-detail', row.host);
}
function activateCategoryRow(row, event) {
  if (row.type !== 'category' || event.target !== event.currentTarget) return;
  event.preventDefault();
  activateRow(row, event);
}
</script>
