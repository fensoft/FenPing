<template>
  <section>
    <div v-if="error" class="alert alert-danger mb-3" role="alert">{{ error }}</div>
    <div class="table-wrap">
      <div class="table-toolbar">
        <div class="input-icon filter-search"><span class="input-icon-addon"><i class="ti ti-search"></i></span><input v-model="filters.search" class="form-control form-control-sm" type="search" placeholder="Search" /></div>
        <label class="form-check form-switch toolbar-switch"><input v-model="filters.onlyDown" class="form-check-input" type="checkbox" /><span class="form-check-label">Down</span></label>
        <label class="form-check form-switch toolbar-switch"><input v-model="filters.onlyImportant" class="form-check-input" type="checkbox" /><span class="form-check-label">Important</span></label>
        <label class="form-check form-switch toolbar-switch"><input v-model="filters.hideUnknown" class="form-check-input" type="checkbox" /><span class="form-check-label">Hide new</span></label>
        <button v-if="hasActiveFilters" class="btn btn-outline-secondary btn-sm icon-btn" type="button" title="Clear filters" @click="resetFilters"><i class="ti ti-filter-x"></i></button>
        <div class="text-secondary small filter-count">{{ visibleHosts.length }}/{{ hosts.length }}</div>
      </div>
      <table class="table table-sm inventory-table">
        <colgroup><col class="col-status" /><col class="col-name" /><col class="col-mac" /><col class="col-vendor" /><col class="col-ip" /><col class="col-actions" /></colgroup>
        <thead><tr>
          <th scope="col"><button class="btn btn-outline-secondary btn-sm icon-btn" type="button" title="Close all categories" @click="closeAllCategories"><i class="ti ti-minus"></i></button></th>
          <th scope="col">Name</th><th scope="col">MAC</th><th scope="col">Vendor</th><th scope="col">IP</th>
          <th scope="col" class="text-end"><button v-if="isAuthenticated" class="btn btn-outline-primary btn-sm icon-btn" type="button" title="Add category" @click="$emit('add-category')"><i class="ti ti-folder-plus"></i></button></th>
        </tr></thead>
        <tbody>
          <tr v-if="loading && tableRows.length === 0"><td class="text-secondary text-center py-4" colspan="6">Loading</td></tr>
          <tr v-else-if="!loading && tableRows.length === 0"><td class="text-secondary text-center py-4" colspan="6">No hosts</td></tr>
          <tr v-for="row in tableRows" :key="row.key" :class="rowClass(row)">
            <template v-if="row.type === 'category'">
              <td><button class="btn btn-outline-secondary btn-sm icon-btn" type="button" :title="row.collapsed ? 'Open category' : 'Close category'" @click="toggleCategory(row.categoryKey)"><i :class="row.collapsed ? 'ti ti-plus' : 'ti ti-minus'"></i></button></td>
              <td class="category-name" colspan="4">{{ row.name }}</td>
              <td class="text-end category-actions">
                <button v-if="isAuthenticated && row.categoryIp" class="btn btn-outline-secondary btn-sm icon-btn" type="button" title="Rename category" @click="$emit('rename-category', row)"><i class="ti ti-pencil"></i></button>
                <button v-if="isAuthenticated && row.categoryIp" class="btn btn-outline-danger btn-sm icon-btn" type="button" title="Delete category" @click="$emit('delete-category', row)"><i class="ti ti-trash"></i></button>
              </td>
            </template>
            <template v-else>
              <td class="status-cell"><div class="status-icons">
                <span :class="statusClass(row.host.status)" :title="statusTitle(row.host.status)" class="status-pill"><i :class="statusIcon(row.host.status)"></i></span>
                <i v-if="toFlag(row.host.is_new)" class="ti ti-alert-triangle text-warning host-role-icon" title="New device — approve it in IPAM"></i>
                <button v-if="showStability(row.host)" :class="stabilityClass(row.host.stability)" type="button" :title="stabilityTitle(row.host.stability)" @click="$emit('open-history', row.host.ip)">{{ stabilityLabel(row.host.stability) }}</button>
                <i v-if="toFlag(row.host.repeater)" class="ti ti-wifi text-secondary host-role-icon" title="Router/repeater"></i>
              </div></td>
              <td class="text-truncate-cell" :title="row.host.name || ''"><a v-if="row.host.web == 1 && row.host.ip" class="host-name-value" :href="`http://${row.host.ip}`" target="_blank" rel="noopener noreferrer">{{ row.host.name }}</a><span v-else class="host-name-value">{{ row.host.name }}</span></td>
              <td class="text-truncate-cell font-monospace" :title="formatMac(row.host.mac)"><span class="mac-value">{{ formatMac(row.host.mac) }}</span><i v-if="row.host.via" class="ti ti-antenna-bars-5 ms-1 text-secondary" :title="row.host.via"></i></td>
              <td class="text-truncate-cell" :title="row.host.vendor || ''">{{ row.host.vendor }}</td><td class="text-truncate-cell font-monospace" :title="row.host.ip || ''">{{ row.host.ip }}</td>
              <td class="text-end action-cell">
                <button v-if="row.host.id" class="btn btn-outline-secondary btn-sm icon-btn" type="button" title="Host detail" @click="$emit('host-detail', row.host.id)"><i class="ti ti-info-circle"></i></button>
                <button v-if="isAuthenticated && row.host.ip" class="btn btn-sm icon-btn" :class="scanActionClass(row.host)" type="button" :title="scanButtonTitle(row.host)" :disabled="isScanRunning(row.host)" @click="$emit('scan-host', row.host)"><i :class="isScanRunning(row.host) ? 'ti ti-loader-2' : 'ti ti-search'"></i></button>
                <button v-if="row.host.xml" class="btn btn-outline-secondary btn-sm icon-btn" type="button" title="View scan" @click="$emit('open-scan', row.host.ip)"><i class="ti ti-file-search"></i></button>
                <button v-if="isAuthenticated && row.host.id" class="btn btn-outline-secondary btn-sm icon-btn" type="button" title="Edit host" @click="$emit('open-edit', row.host)"><i class="ti ti-edit"></i></button>
                <button v-else-if="isAuthenticated && row.host.mac" class="btn btn-outline-primary btn-sm icon-btn" type="button" title="Create host" @click="$emit('open-create', row.host)"><i class="ti ti-plus"></i></button>
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
import { useAbortableTask } from '../composables/useAbortableTask.js';
import { usePageController } from '../composables/usePageController.js';
import { formatDuration, formatMac, formatPercent, formatServerDate, scanIsActiveState, statusClass, statusIcon, statusTitle, toFlag } from '../lib/formatters.js';

defineOptions({ inheritAttrs: false });
const props = defineProps({
  isAuthenticated: Boolean,
  scanning: Boolean,
  refreshQueued: Boolean,
  scanningHosts: { type: Object, required: true }
});
const emit = defineEmits(['add-category', 'delete-category', 'host-detail', 'network', 'open-create', 'open-edit', 'open-history', 'open-scan', 'ping-refresh', 'rename-category', 'scan-host']);
const hosts = ref([]);
const loading = ref(false);
const error = ref('');
const request = useAbortableTask();
const defaults = { search: '', onlyDown: false, onlyImportant: false, hideUnknown: false };
const filters = ref({ ...defaults, ...readStorage('fenping_filters', {}) });
const collapsed = ref(new Set(readStorage('fenping_collapsed_categories', [])));
const hasActiveFilters = computed(() => filters.value.search.trim() !== '' || filters.value.onlyDown || filters.value.onlyImportant || filters.value.hideUnknown);
const categorizedHosts = computed(() => {
  const rows = []; let category = null;
  for (const host of hosts.value) {
    if (host.category) category = { key: categoryKey(host), name: host.category, ip: host.category_ip };
    rows.push({ ...host, categoryContext: category });
  }
  return rows;
});
const visibleHosts = computed(() => categorizedHosts.value.filter(matchesFilters));
const tableRows = computed(() => {
  const rows = []; let current = '';
  for (const host of visibleHosts.value) {
    const category = host.categoryContext;
    if (category && category.key !== current) {
      current = category.key;
      rows.push({ type: 'category', key: current, categoryKey: current, name: category.name, categoryIp: category.ip, collapsed: collapsed.value.has(current) });
    }
    if (!current || !collapsed.value.has(current)) rows.push({ type: 'host', key: `host-${host.id || host.ip || host.mac}`, host });
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
function resetFilters() { filters.value = { ...defaults }; }
function categoryKey(host) { return `category-${host.category_ip || host.category}`; }
function toggleCategory(key) { const next = new Set(collapsed.value); next.has(key) ? next.delete(key) : next.add(key); collapsed.value = next; }
function closeAllCategories() { collapsed.value = new Set(hosts.value.filter((host) => host.category).map(categoryKey)); }
function matchesFilters(host) {
  if (filters.value.onlyDown && host.status === 'Up') return false;
  if (filters.value.onlyImportant && !toFlag(host.important)) return false;
  if (filters.value.hideUnknown && toFlag(host.is_new)) return false;
  const query = filters.value.search.trim().toLowerCase();
  return query === '' || [host.name, host.ip, host.mac, host.vendor, host.status, host.scan?.status, host.scan?.state, host.scan?.mode].some((value) => String(value || '').toLowerCase().includes(query));
}
function hostKey(host) { return String(host?.ip || host?.id || host?.mac || ''); }
function isScanningHost(host) { const key = hostKey(host); return key !== '' && props.scanningHosts.has(key); }
function isScanRunning(host) { return isScanningHost(host) || scanIsActiveState(host?.scan?.state); }
function scanActionClass(host) { return { 'btn-outline-primary': isScanRunning(host), 'btn-outline-danger': host?.scan?.state === 'failed', 'btn-outline-warning': host?.scan?.state === 'timeout', 'btn-outline-secondary': !isScanRunning(host) && !['failed', 'timeout'].includes(host?.scan?.state), 'is-spinning': isScanRunning(host) }; }
function scanButtonTitle(host) {
  if (host?.scan?.state === 'queued') return 'Scan queued';
  if (isScanRunning(host)) return 'Scanning';
  if (host?.scan?.state === 'failed') return `Scan failed${host.scan.error ? `: ${host.scan.error}` : ''}`;
  if (host?.scan?.state === 'timeout') return `Scan timed out${host.scan.error ? `: ${host.scan.error}` : ''}`;
  return host?.scan?.date_end ? `Scan host, last ${formatServerDate(host.scan.date_end)}` : 'Scan host';
}
function showStability(host) { return Boolean(host?.stability && !host.stability.stable); }
function stabilityLabel(stability) { return stability?.label || formatPercent(stability?.uptime_percent); }
function stabilityClass(stability) { return `stability-badge stability-${stability?.level || 'warn'}`; }
function stabilityTitle(stability) { return [`Uptime ${formatPercent(stability?.uptime_percent)}`, `${Number(stability?.transitions || 0)} changes`, `Longest down ${formatDuration(stability?.longest_down_seconds)}`, `Current ${stability?.current_status || '-'} ${formatDuration(stability?.current_seconds)}`].join(' | '); }
function rowClass(row) { return row.type === 'category' ? 'category-row' : row.host.important == 1 && row.host.status !== 'Up' ? 'important-down' : ''; }
</script>
