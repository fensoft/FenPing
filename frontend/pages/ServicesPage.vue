<template>
  <section>
    <div v-if="error" class="alert alert-danger mb-3" role="alert">{{ error }}</div>
    <div class="page-refresh-header">
      <div><h2>{{ t('Services') }}</h2><div class="text-secondary small">{{ t("Open services and manually monitored endpoints") }}</div></div>
      <div class="btn-list">
        <button v-if="isAuthenticated" class="btn btn-primary btn-sm" type="button" @click="openCreate"><AppIcon name="plus" class="me-1" />{{ t('Add manual service') }}</button>
        <button class="btn btn-outline-secondary btn-sm" type="button" :disabled="loading" @click="load"><AppIcon name="refresh" class="me-1" :class="{ 'is-spinning': loading }" />{{ t('Refresh') }}</button>
      </div>
    </div>
    <div v-if="!isAuthenticated" class="alert alert-info" role="alert">{{ t('Guest mode is read only. Login to pin or manage services.') }}<button class="btn btn-primary btn-sm ms-2" type="button" @click="$emit('login')">{{ t('Login') }}</button></div>

    <div class="notify-summary">
      <div class="notify-summary-item"><span>{{ t('Computers') }}</span><strong>{{ summary.hosts || 0 }}</strong></div>
      <div class="notify-summary-item"><span>{{ t('Services') }}</span><strong>{{ summary.services || 0 }}</strong></div>
      <div class="notify-summary-item"><span>{{ t('Important') }}</span><strong>{{ summary.important || 0 }}</strong></div>
      <div class="notify-summary-item"><span>{{ t('Manual') }}</span><strong>{{ summary.manual || 0 }}</strong></div>
    </div>

    <div class="table-toolbar services-toolbar">
      <div class="input-icon filter-search"><span class="input-icon-addon"><AppIcon name="search" /></span><input v-model="search" class="form-control form-control-sm" type="search" :placeholder="t('Search host, port, service, or version')" /></div>
      <button v-if="search" class="btn btn-outline-secondary btn-sm icon-btn" type="button" :title="t('Clear search')" @click="search = ''"><AppIcon name="x" /></button>
    </div>

    <section class="services-section" aria-labelledby="important-services-title">
      <div class="services-section-heading"><div><h3 id="important-services-title">{{ t('Important services') }}</h3><div class="small text-secondary">{{ t('Pinned discoveries and manually checked endpoints') }}</div></div><span class="badge bg-yellow-lt text-yellow">{{ visibleImportant.length }}</span></div>
      <div class="table-wrap">
        <table class="table table-sm services-table important-services-table">
          <thead><tr><th>{{ t('Service') }}</th><th>{{ t('Target') }}</th><th>{{ t('Type') }}</th><th>{{ t('Status') }}</th><th>{{ t('Detail') }}</th><th>{{ t('Last checked') }}</th><th class="text-end">{{ t('Actions') }}</th></tr></thead>
          <tbody>
            <tr v-if="loading && importantServices.length === 0"><td class="text-secondary text-center py-4" colspan="7">{{ t('Loading') }}</td></tr>
            <tr v-else-if="!loading && visibleImportant.length === 0"><td class="text-secondary text-center py-4" colspan="7">{{ t(search ? 'No matching services' : 'No important services') }}</td></tr>
            <tr v-for="row in visibleImportant" :key="`important-${row.id}`">
              <td><strong>{{ importantName(row) }}</strong><small>{{ row.origin === 'manual' ? t('Manual') : (row.service || t('unknown')) }}</small></td>
              <td class="font-monospace"><a v-if="row.type === 'https'" :href="row.target" target="_blank" rel="noopener noreferrer">{{ row.target }}</a><template v-else>{{ importantTarget(row) }}</template></td>
              <td><span class="badge bg-azure-lt text-azure">{{ typeLabel(row) }}</span></td>
              <td><span class="badge" :class="healthClass(row)">{{ healthLabel(row) }}</span></td>
              <td class="services-version" :title="importantDetail(row)">{{ importantDetail(row) }}</td>
              <td class="text-nowrap"><span>{{ formatScanDate(row.last_checked_at || row.last_seen_at) }}</span><small v-if="row.origin === 'discovered'">{{ t('Last seen') }}</small></td>
              <td class="text-end action-cell"><div class="btn-list justify-content-end flex-nowrap">
                <template v-if="row.origin === 'manual' && isAuthenticated">
                  <button class="btn btn-outline-secondary btn-sm icon-btn" type="button" :disabled="checkingIds.has(row.id)" :title="t('Recheck')" @click="checkManual(row)"><AppIcon name="refresh" :class="{ 'is-spinning': checkingIds.has(row.id) }" /></button>
                  <button class="btn btn-outline-secondary btn-sm icon-btn" type="button" :title="t('Edit')" @click="openEdit(row)"><AppIcon name="edit" /></button>
                  <button class="btn btn-outline-danger btn-sm icon-btn" type="button" :title="t('Delete')" @click="deleteManual(row)"><AppIcon name="trash" /></button>
                </template>
                <button v-else-if="row.origin === 'discovered' && isAuthenticated" class="btn btn-outline-warning btn-sm icon-btn" type="button" :title="t('Unpin service')" @click="unpin(row)"><AppIcon name="pin" /></button>
                <button v-if="row.origin === 'discovered' && row.available && row.scan_id" class="btn btn-outline-secondary btn-sm icon-btn" type="button" :title="t('View scan')" @click="$emit('open-scan', row.ip, row.scan_id)"><AppIcon name="file-search" /></button>
              </div></td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>

    <section class="services-section" aria-labelledby="discovered-services-title">
      <div class="services-section-heading"><div><h3 id="discovered-services-title">{{ t('Discovered services') }}</h3><div class="small text-secondary">{{ t("Open services from each host's latest effective scan") }}</div></div><span class="badge bg-secondary-lt text-secondary">{{ visibleServices.length }}</span></div>
      <div class="table-wrap">
        <table class="table table-sm services-table">
          <thead><tr><th>{{ t('Computer') }}</th><th>IP</th><th>{{ t('Port') }}</th><th>{{ t('Service') }}</th><th>{{ t('Version') }}</th><th>{{ t('Source') }}</th><th>{{ t('Scanned') }}</th><th class="text-end">{{ t('Actions') }}</th></tr></thead>
          <tbody>
            <tr v-if="loading && services.length === 0"><td class="text-secondary text-center py-4" colspan="8">{{ t('Loading') }}</td></tr>
            <tr v-else-if="!loading && visibleServices.length === 0"><td class="text-secondary text-center py-4" colspan="8">{{ t(search ? 'No matching services' : 'No open services found') }}</td></tr>
            <tr v-for="row in visibleServices" :key="`${row.ip}-${row.protocol}-${row.port}`">
              <td class="services-host"><template v-if="row.first_for_host"><button v-if="row.host_id" class="btn btn-link btn-sm p-0 services-host-name" type="button" @click="$emit('host-detail', row.host_id)">{{ hostName(row) }}</button><strong v-else>{{ hostName(row) }}</strong><small v-if="row.mac" class="font-monospace">{{ formatMac(row.mac) }}</small><small v-if="row.vendor" :title="row.vendor">{{ row.vendor }}</small></template></td>
              <td class="font-monospace text-nowrap">{{ row.first_for_host ? row.ip : '' }}</td>
              <td class="font-monospace text-nowrap"><strong>{{ row.port }}</strong>/{{ row.protocol }}</td>
              <td><a v-if="serviceUrl(row)" :href="serviceUrl(row)" target="_blank" rel="noopener noreferrer" :title="t('Open web service')"><strong>{{ row.service || t('unknown') }}</strong></a><strong v-else>{{ row.service || t('unknown') }}</strong></td>
              <td class="services-version" :title="row.version || ''">{{ row.version || '-' }}</td>
              <td><span class="badge" :class="scanProfileBadgeClass(row.source || row.scan_mode)">{{ scanProfileLabel(row.source || row.scan_mode) }}</span></td>
              <td class="text-nowrap"><span>{{ formatScanDate(row.scan_date) }}</span><small v-if="row.merged">{{ scanProfileLabel(row.scan_mode) }} + {{ t('Deep') }}</small></td>
              <td class="text-end action-cell"><div class="btn-list justify-content-end flex-nowrap"><button v-if="isAuthenticated" class="btn btn-outline-warning btn-sm icon-btn" type="button" :title="t('Pin service')" @click="pin(row)"><AppIcon name="pin" /></button><button class="btn btn-outline-secondary btn-sm icon-btn" type="button" :title="t('View scan')" @click="$emit('open-scan', row.ip, row.scan_id)"><AppIcon name="file-search" /></button></div></td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>

    <div v-if="modal" ref="modalRoot" class="modal modal-blur show d-block" tabindex="-1" role="dialog" aria-modal="true" aria-labelledby="manual-service-title" :aria-busy="saving" @mousedown.self="closeModal">
      <div class="modal-dialog modal-dialog-centered" role="document"><div class="modal-content">
        <div class="modal-header"><h2 id="manual-service-title" class="modal-title">{{ t(modal.id ? 'Edit manual service' : 'Add manual service') }}</h2><button class="btn-close" type="button" :aria-label="t('Close')" :disabled="saving" @click="closeModal"></button></div>
        <form @submit.prevent="saveManual">
          <div class="modal-body"><div v-if="modalError" class="alert alert-danger" role="alert">{{ modalError }}</div>
            <label class="form-label">{{ t('Name') }}<input v-model.trim="modal.name" class="form-control" type="text" maxlength="80" required autofocus /></label>
            <label class="form-label">{{ t('Type') }}<select v-model="modal.type" class="form-select" @change="applyTypeDefaults"><option value="https">HTTPS</option><option value="ssh">SSH</option><option value="proxy">{{ t('HTTP proxy') }}</option><option value="socks5">{{ t('SOCKS5 proxy') }}</option></select></label>
            <label v-if="modal.type === 'https'" class="form-label">{{ t('HTTPS URL') }}<input v-model.trim="modal.url" class="form-control font-monospace" type="url" maxlength="2048" placeholder="https://service.example.test/health" required /><small class="form-hint">{{ t('Certificates are verified and the final response must be 2xx.') }}</small></label>
            <div v-else class="manual-service-target-grid"><label class="form-label">{{ t('Host') }}<input v-model.trim="modal.host" class="form-control font-monospace" type="text" required /></label><label class="form-label">{{ t('Port') }}<input v-model.number="modal.port" class="form-control" type="number" min="1" max="65535" required /></label></div>
            <div class="alert alert-info mb-0">{{ t('The service will be checked immediately and every five minutes.') }}</div>
          </div>
          <div class="modal-footer"><button class="btn btn-link" type="button" :disabled="saving" @click="closeModal">{{ t('Cancel') }}</button><button class="btn btn-primary" type="submit" :disabled="saving"><AppIcon :name="saving ? 'loader-2' : 'device-floppy'" class="me-1" :class="{ 'is-spinning': saving }" />{{ t(modal.id ? 'Save and check' : 'Add and check') }}</button></div>
        </form>
      </div></div>
    </div>
  </section>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue';
import { apiJson, isAbortError } from '../lib/api.js';
import { t } from '../lib/i18n.js';
import { useAbortableTask } from '../composables/useAbortableTask.js';
import { useAccessibleModal } from '../composables/useAccessibleModal.js';
import { useLiveRefresh } from '../composables/useLiveUpdates.js';
import { usePageController } from '../composables/usePageController.js';
import { formatMac, formatScanDate } from '../lib/formatters.js';
import { scanProfileBadgeClass, scanProfileLabel } from '../lib/scanProfiles.js';

defineOptions({ inheritAttrs: false });
const props = defineProps({ isAuthenticated: Boolean });
const emit = defineEmits(['host-detail', 'network', 'open-scan', 'login', 'notice']);
const services = ref([]);
const importantServices = ref([]);
const summary = ref({ hosts: 0, services: 0, important: 0, manual: 0 });
const loading = ref(false);
const saving = ref(false);
const error = ref('');
const modalError = ref('');
const search = ref('');
const modal = ref(null);
const checkingIds = ref(new Set());
const request = useAbortableTask();
const mutationRequest = useAbortableTask();
const modalRoot = useAccessibleModal(computed(() => modal.value?.id ?? (modal.value ? 'new' : '')), closeModal);

const matchesSearch = (row, query) => [row.name, row.ip, row.target, row.host, row.port, row.protocol, row.service, row.version, row.type, row.check_detail, row.observed_ip]
  .some((value) => String(value || '').toLowerCase().includes(query));
const visibleImportant = computed(() => {
  const query = search.value.trim().toLowerCase();
  return query ? importantServices.value.filter((row) => matchesSearch(row, query)) : importantServices.value;
});
const visibleServices = computed(() => {
  const query = search.value.trim().toLowerCase();
  const rows = services.value.filter((row) => !row.important && (!query || matchesSearch(row, query)));
  return rows.map((row, index) => ({ ...row, first_for_host: index === 0 || String(rows[index - 1]?.ip || '') !== String(row.ip || '') }));
});

usePageController({ loading, label: computed(() => t(loading.value ? 'Loading' : 'Services')), title: computed(() => t('Refresh services')), disabled: false, refresh: load });
useLiveRefresh(['hosts', 'status', 'scans', 'vendors', 'services'], load);
onMounted(load);

async function load() {
  const signal = request.nextSignal(); loading.value = true; error.value = '';
  try {
    const data = await apiJson('/api/services', { signal });
    if (!request.isCurrent(signal)) return;
    emit('network', data.network || ''); services.value = data.services || []; importantServices.value = data.important_services || [];
    summary.value = data.summary || { hosts: 0, services: 0, important: 0, manual: 0 };
  } catch (loadError) { if (!isAbortError(loadError) && request.isCurrent(signal)) error.value = loadError.message; }
  finally { if (request.isCurrent(signal)) loading.value = false; }
}

async function mutate(path, options, notice) {
  if (!props.isAuthenticated) return emit('login');
  const signal = mutationRequest.nextSignal(); error.value = '';
  try { await apiJson(path, { ...options, signal }); if (!mutationRequest.isCurrent(signal)) return; emit('notice', t(notice)); await load(); }
  catch (mutationError) { if (!isAbortError(mutationError) && mutationRequest.isCurrent(signal)) error.value = mutationError.message; }
}
function pin(row) { return mutate('/api/services/pins', { method: 'POST', body: JSON.stringify({ ip: row.ip, protocol: row.protocol, port: row.port }) }, 'Service pinned'); }
function unpin(row) { return mutate(`/api/services/pins/${encodeURIComponent(row.id)}`, { method: 'DELETE' }, 'Service unpinned'); }
async function checkManual(row) {
  if (!props.isAuthenticated) return emit('login');
  checkingIds.value = new Set([...checkingIds.value, row.id]);
  try { await mutate(`/api/services/manual/${encodeURIComponent(row.id)}/check`, { method: 'POST' }, 'Service checked'); }
  finally { const next = new Set(checkingIds.value); next.delete(row.id); checkingIds.value = next; }
}
function deleteManual(row) { if (window.confirm(t('Delete {name}?', { name: row.name }))) return mutate(`/api/services/manual/${encodeURIComponent(row.id)}`, { method: 'DELETE' }, 'Manual service deleted'); }

function openCreate() { if (!props.isAuthenticated) return emit('login'); modal.value = { id: null, name: '', type: 'https', url: 'https://', host: '', port: 22 }; modalError.value = ''; }
function openEdit(row) { modal.value = { id: row.id, name: row.name || '', type: row.type, url: row.type === 'https' ? row.target : '', host: row.type === 'https' ? '' : row.target, port: row.port || (row.type === 'ssh' ? 22 : 8080) }; modalError.value = ''; }
function applyTypeDefaults() { if (modal.value.type === 'ssh' && !modal.value.port) modal.value.port = 22; if (modal.value.type === 'proxy' && (!modal.value.port || [22, 1080].includes(modal.value.port))) modal.value.port = 8080; if (modal.value.type === 'socks5' && (!modal.value.port || [22, 8080].includes(modal.value.port))) modal.value.port = 1080; if (modal.value.type === 'https' && !modal.value.url) modal.value.url = 'https://'; }
function closeModal() { if (saving.value) return; modal.value = null; modalError.value = ''; }
async function saveManual() {
  const signal = mutationRequest.nextSignal(); saving.value = true; modalError.value = '';
  const editing = Boolean(modal.value.id); const path = editing ? `/api/services/manual/${encodeURIComponent(modal.value.id)}` : '/api/services/manual';
  const body = modal.value.type === 'https' ? { name: modal.value.name, type: modal.value.type, url: modal.value.url } : { name: modal.value.name, type: modal.value.type, host: modal.value.host, port: modal.value.port };
  try { await apiJson(path, { method: editing ? 'PUT' : 'POST', body: JSON.stringify(body), signal }); if (!mutationRequest.isCurrent(signal)) return; modal.value = null; emit('notice', t(editing ? 'Manual service updated' : 'Manual service added')); await load(); }
  catch (saveError) { if (!isAbortError(saveError) && mutationRequest.isCurrent(signal)) modalError.value = saveError.message; }
  finally { if (mutationRequest.isCurrent(signal)) saving.value = false; }
}

function hostName(row) { return row?.name || row?.ip || formatMac(row?.mac) || t('Unknown'); }
function importantName(row) { return row.origin === 'manual' ? row.name : (row.name || row.ip || t('Unknown')); }
function importantTarget(row) { return row.origin === 'manual' ? `${row.target}:${row.port}` : `${row.ip}:${row.port}/${row.protocol}`; }
function importantDetail(row) { if (row.origin === 'manual') return row.check_detail || t('Not checked yet'); return row.available ? (row.version || row.service || '-') : t('Not present in latest scan'); }
function typeLabel(row) { if (row.origin === 'discovered') return `${row.port}/${row.protocol}`; if (row.type === 'proxy') return t('HTTP proxy'); if (row.type === 'socks5') return t('SOCKS5 proxy'); return row.type.toUpperCase(); }
function healthLabel(row) { if (row.origin === 'discovered') return t(row.available ? 'Available' : 'Unavailable'); return t(row.check_status === 'healthy' ? 'Healthy' : row.check_status === 'unhealthy' ? 'Unhealthy' : 'Pending'); }
function healthClass(row) { const healthy = row.origin === 'discovered' ? row.available : row.check_status === 'healthy'; const pending = row.origin === 'manual' && row.check_status === 'pending'; return pending ? 'bg-secondary-lt text-secondary' : healthy ? 'bg-green-lt text-green' : 'bg-red-lt text-red'; }
function serviceUrl(row) { const service = String(row?.service || '').toLowerCase(); if (!service.includes('http')) return ''; const port = Number(row?.port || 0); const secure = String(row?.tunnel || '').toLowerCase() === 'ssl' || service.includes('https') || service.includes('ssl/http') || [443, 8443, 9443].includes(port); const scheme = secure ? 'https' : 'http'; const defaultPort = secure ? 443 : 80; return `${scheme}://${row.ip}${port && port !== defaultPort ? `:${port}` : ''}`; }
</script>
