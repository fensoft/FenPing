<template>
  <section>
    <div v-if="error" class="alert alert-danger mb-3" role="alert">{{ error }}</div>
    <div class="table-wrap">
      <div class="table-toolbar">
        <label class="inventory-network-selector">
          <span class="visually-hidden">{{ t('Network') }}</span>
          <select v-model="selectedCidr" class="form-select form-select-sm" :aria-label="t('Network')" @change="selectNetwork">
            <option v-for="item in networks" :key="item.cidr" :value="item.cidr">
              {{ inventoryNetworkLabel(item, t) }}
            </option>
          </select>
        </label>
        <div class="input-icon filter-search"><span class="input-icon-addon"><AppIcon name="search" /></span><input v-model="filters.search" class="form-control form-control-sm" type="search" :placeholder="t('Search devices')" /></div>
        <fieldset v-for="group in filterGroups" :key="group.key" class="filter-segment">
          <legend class="visually-hidden">{{ group.label }}</legend>
          <div class="btn-group" role="group" :aria-label="group.label">
            <template v-for="option in group.options" :key="option.value">
              <input :id="`inventory-filter-${group.key}-${option.value}`" v-model="filters[group.key]" class="btn-check" type="radio" :name="`inventory-filter-${group.key}`" :value="option.value" autocomplete="off" />
              <label class="btn btn-outline-secondary btn-sm" :for="`inventory-filter-${group.key}-${option.value}`">{{ option.label }}</label>
            </template>
          </div>
        </fieldset>
        <details v-if="tagOptions.length" class="inventory-tag-filter">
          <summary class="btn btn-outline-secondary btn-sm">{{ t('Tags') }}<span v-if="filters.tags.length" class="badge bg-blue-lt text-blue ms-1">{{ filters.tags.length }}</span></summary>
          <div class="inventory-tag-menu" @click.stop>
            <label v-for="tag in tagOptions" :key="tag.toLowerCase()" class="form-check">
              <input class="form-check-input" type="checkbox" :checked="tagSelected(tag)" @change="toggleTag(tag)" />
              <span class="form-check-label">{{ tag }}</span>
            </label>
          </div>
        </details>
        <label class="inventory-saved-filter">
          <span class="visually-hidden">{{ t('Saved views') }}</span>
          <select class="form-select form-select-sm" :value="selectedSavedFilterValue" :aria-label="t('Saved views')" @change="applySavedFilter($event.target.value)">
            <option value="">{{ t(filters.tags.length ? 'Custom tags' : 'Saved views') }}</option>
            <option v-for="filter in savedFilters" :key="filter.id" :value="String(filter.id)">{{ filter.name }}</option>
          </select>
        </label>
        <button v-if="isAuthenticated && filters.tags.length" class="btn btn-outline-primary btn-sm" type="button" @click="beginSavedFilterCreate">{{ t('Save view') }}</button>
        <button v-if="isAuthenticated && matchingSavedFilter" class="btn btn-outline-secondary btn-sm icon-btn" type="button" :title="t('Rename saved view')" :aria-label="t('Rename saved view')" @click="beginSavedFilterEdit"><AppIcon name="pencil" /></button>
        <button v-if="isAuthenticated && matchingSavedFilter" class="btn btn-outline-danger btn-sm icon-btn" type="button" :title="t('Delete saved view')" :aria-label="t('Delete saved view')" @click="deleteSavedFilter"><AppIcon name="trash" /></button>
        <button v-if="hasActiveFilters" class="btn btn-outline-secondary btn-sm icon-btn" type="button" :title="t('Clear filters')" @click="resetFilters"><AppIcon name="filter-x" /></button>
        <button v-if="isAuthenticated" class="btn btn-outline-primary btn-sm" type="button" @click="exportOpen = true"><AppIcon name="download" class="me-1" />{{ t('Export') }}</button>
        <div class="inventory-summary" aria-live="polite"><strong>{{ inventorySummary.devices }}</strong> {{ t('devices') }} <span aria-hidden="true">·</span> <span class="inventory-summary-online">{{ inventorySummary.online }} {{ t('online') }}</span> <span v-if="inventorySummary.newDevices > 0" aria-hidden="true">·</span> <span v-if="inventorySummary.newDevices > 0" class="inventory-summary-new">{{ inventorySummary.newDevices }} {{ t('new') }}</span></div>
      </div>
      <form v-if="savedFilterEditor" class="saved-filter-editor" @submit.prevent="submitSavedFilter">
        <label class="form-label mb-0">{{ t('View name') }}<input v-model.trim="savedFilterEditor.name" class="form-control form-control-sm" type="text" required autofocus /></label>
        <span class="text-secondary small">{{ filters.tags.join(', ') }}</span>
        <button class="btn btn-primary btn-sm" type="submit" :disabled="savingFilter">{{ t(savedFilterEditor.id ? 'Update view' : 'Save view') }}</button>
        <button class="btn btn-link btn-sm" type="button" :disabled="savingFilter" @click="savedFilterEditor = null">{{ t('Cancel') }}</button>
      </form>
      <table class="table table-sm inventory-table">
        <colgroup><col class="col-status" /><col class="col-device" /><col class="col-ip" /><col class="col-vendor" /><col class="col-activity" /><col class="col-services" /><col v-if="isAuthenticated" class="col-actions" /></colgroup>
        <thead><tr>
          <th scope="col"><button class="btn btn-outline-secondary btn-sm icon-btn" type="button" :title="t(allCategoriesCollapsed ? 'Open all categories' : 'Close all categories')" :aria-label="t(allCategoriesCollapsed ? 'Open all categories' : 'Close all categories')" :disabled="categoryKeys.length === 0" @click="toggleAllCategories"><AppIcon :name="allCategoriesCollapsed ? 'plus' : 'minus'" /></button></th>
          <th scope="col">{{ t('Device') }}</th><th scope="col">IP</th><th scope="col" class="inventory-vendor-column">{{ t('Vendor') }}</th><th scope="col" class="inventory-activity-column">{{ t('Activity') }}</th><th scope="col" class="inventory-services-column">{{ t('Services') }}</th>
          <th v-if="isAuthenticated" scope="col" class="inventory-actions-heading"><div class="inventory-actions-header"><span>{{ t('Actions') }}</span><button v-if="isDhcpSelected" class="btn btn-outline-primary btn-sm icon-btn" type="button" :title="t('Add category')" :aria-label="t('Add category')" @click="$emit('add-category')"><AppIcon name="folder-plus" /></button></div></th>
        </tr></thead>
        <tbody>
          <tr v-if="loading && tableRows.length === 0"><td class="text-secondary text-center py-4" :colspan="isAuthenticated ? 7 : 6">{{ t('Loading') }}</td></tr>
          <tr v-else-if="!loading && tableRows.length === 0"><td class="text-secondary text-center py-4" :colspan="isAuthenticated ? 7 : 6">{{ t('No hosts') }}</td></tr>
          <tr v-for="row in tableRows" :key="row.key" :class="[rowClass(row), row.type === 'host' ? downActivityClass(row.host.status, row.host?.stability?.current_seconds) : '', { 'inventory-host-row': row.type === 'host', 'inventory-host-row-collapsed': row.type === 'host' && row.collapsed }]" :tabindex="row.type === 'category' || (row.type === 'host' && !row.collapsed && (row.host?.id || row.host?.ip)) ? 0 : undefined" :aria-label="rowAriaLabel(row)" :aria-expanded="row.type === 'category' ? !row.collapsed : undefined" :aria-hidden="row.type === 'host' && row.collapsed ? 'true' : undefined" @click="activateRow(row, $event)" @keydown.enter.prevent="activateRow(row, $event)" @keydown.space="activateCategoryRow(row, $event)">
            <template v-if="row.type === 'category'">
              <td><button class="btn category-toggle" type="button" :title="t(row.collapsed ? 'Open category' : 'Close category')" :aria-label="t(row.collapsed ? 'Open category' : 'Close category')" @click="toggleCategory(row.categoryKey)"><AppIcon :name="row.collapsed ? 'chevron-right' : 'chevron-down'" /></button></td>
              <td class="category-name" colspan="5"><span class="category-title">{{ row.name }}</span><span class="category-summary">{{ row.total }} {{ t(row.total === 1 ? 'device' : 'devices') }} <span aria-hidden="true">·</span> {{ row.online }} {{ t('online') }}</span></td>
              <td v-if="isAuthenticated" class="text-end category-actions">
                <div class="inventory-actions">
                  <button v-if="isAuthenticated && isDhcpSelected && row.categoryIp" class="btn btn-outline-info btn-sm icon-btn" type="button" :title="t('Rename category')" :aria-label="t('Rename category')" @click="$emit('rename-category', row)"><AppIcon name="pencil" /></button>
                  <button v-if="isAuthenticated && isDhcpSelected && row.categoryIp" class="btn btn-outline-danger btn-sm icon-btn" type="button" :title="t('Delete category')" :aria-label="t('Delete category')" @click="$emit('delete-category', row)"><AppIcon name="trash" /></button>
                </div>
              </td>
            </template>
            <template v-else>
              <td class="status-cell"><div class="status-icons">
                <span :class="statusClass(row.host.status)" :title="statusTitle(row.host.status)" :aria-label="statusTitle(row.host.status)" class="status-pill" role="img"><AppIcon :name="statusIcon(row.host.status)" /></span>
              </div></td>
              <td class="inventory-device-cell text-truncate-cell" :title="deviceTitle(row.host)">
                <AppIcon v-if="hostIconName(row.host.icon)" :name="hostIconName(row.host.icon)" class="text-primary host-custom-icon" />
                <span class="host-name-value">{{ deviceName(row.host) }}</span>
                <span v-if="row.host.tags?.length" class="inventory-host-tags">
                  <span v-for="tag in row.host.tags" :key="tag.toLowerCase()" class="badge bg-blue-lt text-blue">{{ tag }}</span>
                </span>
                <AppIcon v-if="toFlag(row.host.is_new)" name="alert-triangle" class="text-warning host-role-icon" :title="t('New device — approve it in IPAM')" />
                <AppIcon v-if="toFlag(row.host.mac_mismatch)" name="alert-triangle" class="text-danger host-role-icon" :title="`${t('Reserved MAC differs from detected MAC')}: ${formatMac(row.host.mac)} → ${formatMac(row.host.detected_mac)}`" />
                <AppIcon v-if="toFlag(row.host.repeater)" name="wifi" class="text-secondary host-role-icon" :title="t('Router/repeater')" />
                <AppIcon v-if="row.host.via" name="antenna-bars-5" class="text-secondary host-role-icon" :title="row.host.via" />
                <a v-if="row.host.web == 1 && row.host.ip" class="host-web-link" :href="`http://${row.host.ip}`" target="_blank" rel="noopener noreferrer" :title="t('Open web interface')" :aria-label="t('Open web interface')"><AppIcon name="external-link" /></a>
                <span class="inventory-mobile-meta">{{ mobileMeta(row.host) }}</span>
              </td>
              <td class="text-truncate-cell font-monospace inventory-ip-cell" :title="row.host.ip || ''">{{ row.host.ip }}</td>
              <td class="text-truncate-cell inventory-vendor-column" :title="row.host.vendor || t('Unknown vendor')">{{ row.host.vendor || t('Unknown') }}</td>
              <td class="text-truncate-cell inventory-activity-column" :title="activityTitle(row.host)">{{ activityLabel(row.host) }}</td>
              <td class="text-truncate-cell inventory-services-column" :title="serviceTitle(row.host)">{{ serviceLabel(row.host) }}</td>
              <td v-if="isAuthenticated" class="text-end action-cell">
                <div class="inventory-actions">
                  <button v-if="row.host.ip" class="btn btn-sm inventory-action-btn inventory-scan-btn" :class="scanActionClass(row.host)" type="button" :title="scanButtonTitle(row.host)" :aria-label="scanButtonTitle(row.host)" :disabled="isScanRunning(row.host)" @click="$emit('scan-host', row.host)"><AppIcon :name="isScanRunning(row.host) ? 'loader-2' : 'search'" /><span class="inventory-action-label">{{ scanButtonLabel(row.host) }}</span></button>
                  <button v-if="scanIsActiveState(row.host?.scan?.state)" class="btn btn-outline-danger btn-sm icon-btn" type="button" :disabled="!scanCanCancel(row.host.scan) || isCancelling(row.host.scan)" :title="t(row.host.scan.cancel_requested ? 'Cancelling' : 'Cancel scan')" :aria-label="t(row.host.scan.cancel_requested ? 'Cancelling' : 'Cancel scan')" @click="$emit('cancel-scan', row.host.scan)"><AppIcon :name="row.host.scan.cancel_requested || isCancelling(row.host.scan) ? 'loader-2' : 'x'" :class="{ 'is-spinning': row.host.scan.cancel_requested || isCancelling(row.host.scan) }" /></button>
                  <button v-if="isAuthenticated && row.host.id && toFlag(row.host.network_is_dhcp)" class="btn btn-outline-warning btn-sm icon-btn" type="button" :title="t('Edit host')" :aria-label="t('Edit host')" @click="$emit('open-edit', row.host)"><AppIcon name="edit" /></button>
                  <button v-if="isAuthenticated && !toFlag(row.host.network_is_dhcp) && row.host.metadata_editable" class="btn btn-outline-warning btn-sm icon-btn" type="button" :title="t('Edit metadata')" :aria-label="t('Edit metadata')" @click="$emit('open-metadata', row.host)"><AppIcon name="edit" /></button>
                  <button v-if="isAuthenticated && isDhcpSelected && !row.host.id && row.host.mac" class="btn btn-outline-primary btn-sm icon-btn" type="button" :title="t('Create host')" :aria-label="t('Create host')" @click="$emit('open-create', row.host)"><AppIcon name="plus" /></button>
                </div>
              </td>
            </template>
          </tr>
        </tbody>
      </table>
    </div>
    <InventoryExportModal v-if="exportOpen" :network="selectedCidr" @close="exportOpen = false" @download="exportOpen = false" />
  </section>
</template>

<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import InventoryExportModal from '../components/InventoryExportModal.vue';
import { apiJson, isAbortError } from '../lib/api.js';
import { inventoryFilterDefaults, inventoryFiltersActive, inventoryHostMatches, matchingSavedInventoryFilter, normalizeInventoryFilters, normalizeTags } from '../lib/inventoryFilters.js';
import { hostIconName } from '../lib/hostIcons.js';
import { inventoryNetworkFallback, inventoryNetworkIsDhcp, inventoryNetworkLabel, inventoryNetworkUrl } from '../lib/inventoryNetworks.js';
import { t } from '../lib/i18n.js';
import { useAbortableTask } from '../composables/useAbortableTask.js';
import { useLiveRefresh } from '../composables/useLiveUpdates.js';
import { useNow } from '../composables/useNow.js';
import { usePageController } from '../composables/usePageController.js';
import { downActivityClass, formatActivityDuration, formatMac, formatServerDate, parseServerDate, scanCanCancel, scanIsActiveState, scanProgressLabel, statusClass, statusIcon, statusLabel, statusTitle, toFlag } from '../lib/formatters.js';

defineOptions({ inheritAttrs: false });
const props = defineProps({
  isAuthenticated: Boolean,
  scanning: Boolean,
  refreshQueued: Boolean,
  scanningHosts: { type: Object, required: true },
  cancellingScans: { type: Object, required: true }
});
const emit = defineEmits(['add-category', 'cancel-scan', 'delete-category', 'host-detail', 'network', 'selected-network', 'open-create', 'open-edit', 'open-metadata', 'open-history', 'ping-refresh', 'rename-category', 'scan-host']);
const hosts = ref([]);
const networks = ref([]);
const availableTags = ref([]);
const savedFilters = ref([]);
const savedFilterEditor = ref(null);
const savingFilter = ref(false);
const selectedCidr = ref(readStorage('fenping_selected_network', ''));
const dhcpCidr = ref('');
const loading = ref(false);
const error = ref('');
const exportOpen = ref(false);
const request = useAbortableTask();
const now = useNow(30000);
const filterGroups = computed(() => [
  { key: 'status', label: t('Status filter'), options: [{ value: 'down', label: t('Down') }, { value: 'all', label: t('All') }, { value: 'up', label: t('Up') }] },
  { key: 'importance', label: t('Importance filter'), options: [{ value: 'normal', label: t('Normal') }, { value: 'all', label: t('All') }, { value: 'important', label: t('Important') }] },
  { key: 'newness', label: t('New-device filter'), options: [{ value: 'known', label: t('Known') }, { value: 'all', label: t('All') }, { value: 'new', label: t('New') }] }
]);
const filters = ref(normalizeInventoryFilters(readStorage('fenping_filters', inventoryFilterDefaults)));
writeStorage('fenping_filters', filters.value);
const collapsed = ref(new Set(readStorage('fenping_collapsed_categories', [])));
const hasActiveFilters = computed(() => inventoryFiltersActive(filters.value));
const isDhcpSelected = computed(() => inventoryNetworkIsDhcp(selectedCidr.value, dhcpCidr.value));
const matchingSavedFilter = computed(() => matchingSavedInventoryFilter(filters.value.tags, savedFilters.value));
const selectedSavedFilterValue = computed(() => matchingSavedFilter.value ? String(matchingSavedFilter.value.id) : '');
const tagOptions = computed(() => normalizeTags([
  ...availableTags.value,
  ...filters.value.tags,
  ...savedFilters.value.flatMap((filter) => filter.tags || [])
]));
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
    rows.push({ type: 'host', key: `host-${host.id || ''}-${host.device_identity?.network || ''}-${host.device_identity?.container || ''}-${host.ip || host.mac || ''}`, host, collapsed: current !== '' && collapsed.value.has(current) });
  }
  return rows;
});

usePageController({
  loading,
  label: computed(() => t(loading.value ? 'Loading' : !props.isAuthenticated ? 'Read only' : props.scanning && props.refreshQueued ? 'Queued' : props.scanning ? 'Scanning' : 'Ready')),
  title: computed(() => t('Refresh')),
  disabled: false,
  refresh: () => props.isAuthenticated ? emit('ping-refresh') : load(),
  reload: load
});
useLiveRefresh(['hosts', 'status', 'scans', 'leases', 'networks', 'vendors'], load);
watch(filters, (value) => writeStorage('fenping_filters', value), { deep: true });
watch(collapsed, (value) => writeStorage('fenping_collapsed_categories', Array.from(value)));
onMounted(load);

async function load() {
  const signal = request.nextSignal(); loading.value = true; error.value = '';
  try {
    const data = await apiJson(inventoryNetworkUrl(selectedCidr.value), { signal });
    if (!request.isCurrent(signal)) return;
    networks.value = data.networks || [];
    dhcpCidr.value = data.dhcp_network || '';
    selectedCidr.value = inventoryNetworkFallback(networks.value, data.selected_network || selectedCidr.value, dhcpCidr.value);
    writeStorage('fenping_selected_network', selectedCidr.value);
    emit('network', data.network || '');
    emit('selected-network', selectedCidr.value);
    hosts.value = data.hosts || [];
    availableTags.value = normalizeTags(data.available_tags);
    savedFilters.value = Array.isArray(data.saved_filters) ? data.saved_filters : [];
  } catch (loadError) {
    if (!isAbortError(loadError) && request.isCurrent(signal) && selectedCidr.value) {
      selectedCidr.value = '';
      writeStorage('fenping_selected_network', '');
      loading.value = false;
      return load();
    }
    if (!isAbortError(loadError) && request.isCurrent(signal)) error.value = loadError.message;
  } finally { if (request.isCurrent(signal)) loading.value = false; }
}

function selectNetwork() {
  writeStorage('fenping_selected_network', selectedCidr.value);
  emit('selected-network', selectedCidr.value);
  load();
}

function readStorage(name, fallback) { try { const value = localStorage.getItem(name); return value ? JSON.parse(value) : fallback; } catch { return fallback; } }
function writeStorage(name, value) { try { localStorage.setItem(name, JSON.stringify(value)); } catch {} }
function resetFilters() { filters.value = { ...inventoryFilterDefaults, tags: [] }; savedFilterEditor.value = null; }
function tagSelected(tag) { return filters.value.tags.some((item) => item.toLowerCase() === tag.toLowerCase()); }
function toggleTag(tag) {
  filters.value = {
    ...filters.value,
    tags: tagSelected(tag)
      ? filters.value.tags.filter((item) => item.toLowerCase() !== tag.toLowerCase())
      : normalizeTags([...filters.value.tags, tag])
  };
}
function applySavedFilter(value) {
  const filter = savedFilters.value.find((item) => String(item.id) === String(value));
  if (filter)
    filters.value = { ...filters.value, tags: normalizeTags(filter.tags) };
}
function beginSavedFilterCreate() {
  if (!filters.value.tags.length) return;
  savedFilterEditor.value = { id: null, name: '' };
}
function beginSavedFilterEdit() {
  if (!matchingSavedFilter.value) return;
  savedFilterEditor.value = { id: matchingSavedFilter.value.id, name: matchingSavedFilter.value.name };
}
async function submitSavedFilter() {
  const editor = savedFilterEditor.value;
  if (!editor || !filters.value.tags.length) return;
  savingFilter.value = true;
  error.value = '';
  try {
    const id = editor.id;
    const filter = await apiJson(id ? `/api/inventory/saved-filters/${encodeURIComponent(id)}` : '/api/inventory/saved-filters', {
      method: id ? 'PUT' : 'POST',
      body: JSON.stringify({ name: editor.name, tags: filters.value.tags })
    });
    savedFilters.value = id
      ? savedFilters.value.map((item) => item.id === filter.id ? filter : item)
      : [...savedFilters.value, filter];
    savedFilters.value.sort((left, right) => left.name.localeCompare(right.name, undefined, { sensitivity: 'base' }));
    availableTags.value = normalizeTags([...availableTags.value, ...filter.tags]);
    filters.value = { ...filters.value, tags: normalizeTags(filter.tags) };
    savedFilterEditor.value = null;
  } catch (saveError) {
    error.value = saveError.message;
  } finally {
    savingFilter.value = false;
  }
}
async function deleteSavedFilter() {
  const filter = matchingSavedFilter.value;
  if (!filter || !window.confirm(t('Delete saved view {name}?', { name: filter.name }))) return;
  savingFilter.value = true;
  error.value = '';
  try {
    await apiJson(`/api/inventory/saved-filters/${encodeURIComponent(filter.id)}`, { method: 'DELETE' });
    savedFilters.value = savedFilters.value.filter((item) => item.id !== filter.id);
    savedFilterEditor.value = null;
  } catch (deleteError) {
    error.value = deleteError.message;
  } finally {
    savingFilter.value = false;
  }
}
function categoryKey(host) { return `category-${host.category_ip || host.category}`; }
function toggleCategory(key) { const next = new Set(collapsed.value); next.has(key) ? next.delete(key) : next.add(key); collapsed.value = next; }
function toggleAllCategories() { collapsed.value = allCategoriesCollapsed.value ? new Set() : new Set(categoryKeys.value); }
function hostKey(host) { return String(host?.ip || host?.id || host?.mac || ''); }
function isScanningHost(host) { const key = hostKey(host); return key !== '' && props.scanningHosts.has(key); }
function isScanRunning(host) { return isScanningHost(host) || scanIsActiveState(host?.scan?.state); }
function scanActionClass(host) { return { 'btn-outline-primary': !['failed', 'timeout'].includes(host?.scan?.state), 'btn-outline-danger': host?.scan?.state === 'failed', 'btn-outline-warning': host?.scan?.state === 'timeout', 'is-spinning': isScanRunning(host) }; }
function isCancelling(scan) { return props.cancellingScans.has(Number(scan?.id || 0)); }
function scanButtonLabel(host) {
  if (scanIsActiveState(host?.scan?.state)) return scanProgressLabel(host.scan);
  if (isScanningHost(host)) return t('Scanning');
  if (['failed', 'timeout'].includes(host?.scan?.state)) return t('Retry');
  return t('Scan');
}
function scanButtonTitle(host) {
  if (scanIsActiveState(host?.scan?.state)) return scanProgressLabel(host.scan);
  if (isScanningHost(host)) return t('Scanning');
  if (host?.scan?.state === 'failed') return `${t('Scan failed')}${host.scan.error ? `: ${host.scan.error}` : ''}`;
  if (host?.scan?.state === 'timeout') return `${t('Scan timed out')}${host.scan.error ? `: ${host.scan.error}` : ''}`;
  return host?.scan?.date_end ? t('Scan host, last {date}', { date: formatServerDate(host.scan.date_end) }) : t('Scan host');
}
function isOnline(host) { return ['Up', 'arp'].includes(host?.status); }
function activityAge(host) {
  const timestamp = parseServerDate(host?.date);
  return Number.isNaN(timestamp) ? null : Math.max(0, Math.floor((now.value - timestamp) / 1000));
}
function activityLabel(host) {
  if (!isOnline(host) && host?.stability?.current_seconds !== undefined)
    return t('Down {duration}', { duration: formatActivityDuration(host.stability.current_seconds) });
  const age = activityAge(host);
  if (age === null) return statusLabel(host?.status);
  return t(isOnline(host) ? 'Seen {duration} ago' : 'Checked {duration} ago', { duration: formatActivityDuration(age) });
}
function activityTitle(host) {
  const parts = [activityLabel(host)];
  if (host?.date) parts.push(formatServerDate(host.date));
  if (host?.stability) parts.push(t('Uptime {percent}%', { percent: Math.round(Number(host.stability.uptime_percent || 0)) }), t('{count} changes', { count: Number(host.stability.transitions || 0) }));
  return parts.join(' | ');
}
function serviceLabel(host) {
  if (scanIsActiveState(host?.scan?.state)) return scanProgressLabel(host.scan);
  if (isScanningHost(host)) return t('Scanning');
  if (host?.scan?.state === 'failed') return t('Scan failed');
  if (host?.scan?.state === 'timeout') return t('Scan timed out');
  if (!host?.scan?.result_available && !host?.scan?.snapshot_id) return '-';
  const count = Number(host?.scan?.effective_ports_count ?? host?.scan?.ports_count ?? 0);
  return t(count === 1 ? '{count} service' : '{count} services', { count });
}
function serviceTitle(host) {
  if (!host?.scan) return t('No scan available');
  const last = host.scan.date_end || host.scan.date_begin;
  return `${serviceLabel(host)}${last ? ` | ${t('Last scan {date}', { date: formatServerDate(last) })}` : ''}`;
}
function deviceName(host) {
  return host?.display_name || host?.name || host?.device_identity?.container || host?.ip || formatMac(host?.mac);
}

function deviceTitle(host) {
  return [deviceName(host), host?.name, host?.device_identity?.network, host?.device_identity?.container, formatMac(host?.mac), host?.vendor].filter(Boolean).join(' | ');
}
function mobileMeta(host) {
  return [host?.vendor || t('Unknown vendor'), formatMac(host?.mac), activityLabel(host), serviceLabel(host)].filter(Boolean).join(' · ');
}
function rowClass(row) { return row.type === 'category' ? 'category-row' : row.host.important == 1 && row.host.status !== 'Up' ? 'important-down' : ''; }
function rowAriaLabel(row) {
  if (row.type === 'category') return t(row.collapsed ? 'Open category {name}' : 'Close category {name}', { name: row.name });
  return !row.collapsed && (row.host?.id || row.host?.ip) ? t('Details for {name}', { name: deviceName(row.host) }) : undefined;
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
