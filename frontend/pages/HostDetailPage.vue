<template>
  <section>
    <div v-if="error" class="alert alert-danger mb-3" role="alert">{{ error }}</div>
    <RouterLink v-if="!embedded" class="btn btn-link btn-sm p-0 mb-1" to="/"><AppIcon name="arrow-left" class="me-1" />Inventory</RouterLink>
    <div class="page-header host-detail-header">
      <div>
        <h2>{{ title }}</h2><div class="text-secondary small font-monospace">{{ host.ip || 'No IP' }}</div>
      </div>
      <div class="page-actions">
        <button v-if="host.ip" class="btn btn-outline-secondary btn-sm" type="button" title="Scan history" aria-label="Scan history" :disabled="!viewScan" @click="$emit('open-scan', host.ip, viewScan?.id)"><AppIcon name="history" class="me-1" />History</button>
        <button v-if="isAuthenticated && host.ip" class="btn btn-outline-primary btn-sm" type="button" :disabled="isScanning || scanIsActiveState(latestScan?.state)" @click="$emit('scan-host', host)"><AppIcon :name="isScanning ? 'loader-2' : 'search'" class="me-1" :class="{ 'is-spinning': isScanning }" />Scan</button>
        <button v-if="isAuthenticated && host.id" class="btn btn-primary btn-sm" type="button" @click="$emit('open-edit', host)"><AppIcon name="edit" class="me-1" />Edit</button>
      </div>
    </div>
    <div v-if="loading" class="table-wrap detail-empty"><div class="text-secondary text-center py-4">Loading</div></div>
    <template v-else-if="detail">
      <div class="detail-summary">
        <div class="detail-fact"><span>Status</span><strong><span :class="statusClass(host.status)" class="status-pill"><AppIcon :name="statusIcon(host.status)" /></span>{{ host.status || '-' }}</strong></div>
        <div class="detail-fact"><span>MAC</span><strong class="font-monospace">{{ formatMac(host.mac) || '-' }}</strong></div>
        <div class="detail-fact"><span>Vendor</span><strong>{{ host.vendor || '-' }}</strong></div>
        <div class="detail-fact"><span>Netboot</span><strong>{{ netbootName }}</strong></div>
      </div>
      <div class="detail-grid">
        <section class="detail-panel"><h3>Configuration</h3><dl class="detail-list">
          <div><dt>Management</dt><dd>{{ managementLabel }}</dd></div>
          <div><dt>Name</dt><dd>{{ host.name || '-' }}</dd></div><div><dt>IP</dt><dd class="font-monospace">{{ host.ip || '-' }}</dd></div>
          <div><dt>Router</dt><dd>{{ host.router || '-' }}</dd></div><div><dt>DNS</dt><dd>{{ host.dns || '-' }}</dd></div>
          <div><dt>Scheduled scan</dt><dd>{{ scanSchedule }}</dd></div>
          <div><dt>Flags</dt><dd><span v-if="toFlag(host.important)" class="badge bg-red-lt text-red me-1">Important</span><span v-if="toFlag(host.repeater)" class="badge bg-blue-lt text-blue me-1">Router/repeater</span><span v-if="toFlag(host.web)" class="badge bg-green-lt text-green me-1">Web</span><span v-if="!toFlag(host.important) && !toFlag(host.repeater) && !toFlag(host.web)">-</span></dd></div>
        </dl></section>
        <section class="detail-panel"><h3>Latest Scan</h3><dl class="detail-list">
          <div><dt>State</dt><dd>{{ latestScan?.state || '-' }}</dd></div><div><dt>Status</dt><dd>{{ latestScan?.status || '-' }}</dd></div>
          <div><dt>Profile</dt><dd>{{ scanProfileLabel(latestScan?.mode) }}</dd></div><div><dt>Ports</dt><dd>{{ latestScan?.ports_count ?? 0 }}</dd></div>
          <div><dt>Last</dt><dd>{{ formatScanDate(latestScan?.date_end || latestScan?.date_begin) }}</dd></div>
        </dl></section>
      </div>
      <div class="detail-section">
        <div class="detail-section-heading"><h3>History</h3><span class="text-secondary small">{{ historyRows.length }} rows</span></div>
        <div class="table-wrap"><table class="table table-sm detail-table">
          <thead><tr><th>Status</th><th>MAC</th><th>Started</th><th>Duration</th></tr></thead>
          <tbody><tr v-if="historyRows.length === 0"><td class="text-secondary text-center py-4" colspan="4">No history</td></tr>
            <tr v-for="item in historyRows" :key="item.id" :class="historyRowClass(item)"><td><span :class="statusClass(item.status)" :title="statusTitle(item.status)" class="status-pill"><AppIcon :name="statusIcon(item.status)" /></span>{{ item.status || '-' }}</td><td class="font-monospace">{{ formatMac(item.mac) }}</td><td>{{ formatServerDate(item.date_begin) }}</td><td>{{ formatDuration(item.duration) }}</td></tr>
          </tbody>
        </table></div>
      </div>
      <div v-if="scanResultError" class="alert alert-warning mt-3 mb-0" role="alert">{{ scanResultError }}</div>
      <template v-if="scanResult">
        <div class="detail-grid detail-scan-grid">
          <section class="detail-panel"><h3>Hostnames</h3><table class="table table-sm scan-table"><tbody>
            <tr v-if="scanResult.hostnames.length === 0"><td class="text-secondary">No hostnames detected</td></tr>
            <tr v-for="hostname in scanResult.hostnames" :key="`${hostname.name}-${hostname.type}`"><td>{{ hostname.name }}</td><td class="scan-type">{{ hostname.type || '-' }}</td></tr>
          </tbody></table></section>
          <section class="detail-panel"><h3>OS</h3><table class="table table-sm scan-table"><tbody>
            <tr v-if="scanResult.os.length === 0"><td class="text-secondary">No OS match detected</td></tr>
            <tr v-for="os in scanResult.os" :key="os.name"><td>{{ os.name }}</td><td class="scan-type">{{ os.accuracy }}%</td></tr>
          </tbody></table></section>
        </div>
        <div class="detail-section">
          <div class="detail-section-heading"><h3>Ports</h3><span class="text-secondary small">{{ scanResult.ports.length }} ports</span></div>
          <div class="table-wrap"><table class="table table-sm scan-table detail-ports-table"><thead><tr><th>Port</th><th>State</th><th>Service</th><th>Details</th><th>Source</th></tr></thead><tbody>
            <tr v-if="scanResult.ports.length === 0"><td class="text-secondary text-center py-4" colspan="5">No ports found</td></tr>
            <tr v-for="port in scanResult.ports" :key="`${port.protocol}-${port.port}`"><td class="font-monospace">{{ port.port }}/{{ port.protocol }}</td><td><span :class="scanStateClass(port.state)">{{ port.state || '-' }}</span></td><td>{{ port.service || '-' }}</td><td class="text-truncate-cell" :title="port.details">{{ port.details || '-' }}</td><td class="scan-type">{{ scanProfileLabel(port.source || scanResult.metadata?.mode) }}</td></tr>
          </tbody></table></div>
        </div>
      </template>
      <div v-else-if="!viewScan && !scanResultError" class="table-wrap detail-empty mt-3"><div class="text-secondary text-center py-4">No scan details</div></div>
    </template>
  </section>
</template>

<script setup>
import { computed, ref, watch } from 'vue';
import { useRoute } from 'vue-router';
import { apiJson, isAbortError } from '../lib/api.js';
import { useAbortableTask } from '../composables/useAbortableTask.js';
import { usePageController } from '../composables/usePageController.js';
import { formatDuration, formatMac, formatScanDate, formatServerDate, historyRowClass, scanIsActiveState, scanStateClass, statusClass, statusIcon, statusTitle, toFlag } from '../lib/formatters.js';
import { scanCadenceLabel, scanProfileLabel } from '../lib/scanProfiles.js';

defineOptions({ inheritAttrs: false });
const props = defineProps({ device: { type: Object, default: null }, embedded: Boolean, isAuthenticated: Boolean, scanningHosts: { type: Object, required: true } });
defineEmits(['open-edit', 'open-scan', 'scan-host']);
const route = useRoute();
const detail = ref(null);
const loading = ref(false);
const error = ref('');
const request = useAbortableTask();
const host = computed(() => detail.value?.host || {});
const latestScan = computed(() => detail.value?.latest_scan || null);
const scans = computed(() => detail.value?.scans || []);
const viewScan = computed(() => scans.value.find(scanHasResult) || null);
const scanResult = ref(null);
const scanResultError = ref('');
const historyRows = computed(() => detail.value?.history?.rows || []);
const title = computed(() => host.value.name || host.value.ip || 'Host detail');
const netbootName = computed(() => detail.value?.netboot_image?.name || detail.value?.netboot_image?.filename || '-');
const managementLabel = computed(() => host.value.id ? 'Managed' : 'Not managed');
const scanSchedule = computed(() => host.value.id ? `${scanProfileLabel(host.value.scan_profile)} · ${scanCadenceLabel(host.value.scan_interval_hours)}` : 'Not managed');
const isScanning = computed(() => props.scanningHosts.has(String(host.value?.ip || host.value?.id || host.value?.mac || '')));

if (!props.embedded)
  usePageController({ loading, label: computed(() => loading.value ? 'Loading' : 'Device'), title: 'Refresh host', disabled: false, refresh: load });
watch(() => props.embedded ? `${props.device?.id || ''}|${props.device?.ip || ''}` : route.fullPath, load, { immediate: true });

async function load() {
  const id = Number((props.embedded ? props.device?.id : route.params.id) || 0);
  const ip = String((props.embedded ? props.device?.ip : route.params.ip) || '');
  if (!id && !ip) return;
  const signal = request.nextSignal();
  loading.value = true;
  error.value = '';
  scanResult.value = null;
  scanResultError.value = '';
  try {
    const endpoint = id ? `/api/hosts/${encodeURIComponent(id)}/detail` : `/api/hosts/by-ip/${encodeURIComponent(ip)}/detail`;
    const data = await apiJson(endpoint, { signal });
    if (!request.isCurrent(signal)) return;
    detail.value = data;
    const resultMetadata = (data.scans || []).find(scanHasResult);
    if (resultMetadata) {
      try {
        scanResult.value = await apiJson(`/api/scans/${encodeURIComponent(data.host.ip)}/history/${encodeURIComponent(resultMetadata.id)}`, { signal });
      } catch (scanError) {
        if (!isAbortError(scanError) && request.isCurrent(signal)) scanResultError.value = `Scan details unavailable: ${scanError.message}`;
      }
    }
  } catch (loadError) {
    if (!isAbortError(loadError) && request.isCurrent(signal)) { detail.value = null; error.value = loadError.message; }
  } finally {
    if (request.isCurrent(signal)) loading.value = false;
  }
}

function scanHasResult(scan) {
  if (!scan) return false;
  if (Object.prototype.hasOwnProperty.call(scan, 'result_available')) return Boolean(scan.result_available);
  return Object.prototype.hasOwnProperty.call(scan, 'xml_usable') ? Boolean(scan.xml_usable) : Boolean(scan.xml || scan.xml_url);
}
</script>
