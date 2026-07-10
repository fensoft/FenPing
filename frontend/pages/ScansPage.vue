<template>
  <section>
    <div v-if="error" class="alert alert-danger mb-3" role="alert">{{ error }}</div>
    <div class="page-header">
      <div><h2>Scans</h2><div class="text-secondary small">Queued, running, and recent inventory scans</div></div>
      <button class="btn btn-outline-secondary btn-sm" type="button" :disabled="loading" @click="load">
        <i class="ti ti-refresh me-1" :class="{ 'is-spinning': loading }"></i>Refresh
      </button>
    </div>
    <div class="table-wrap">
      <table class="table table-sm scan-queue-table">
        <colgroup>
          <col class="scan-col-state" /><col class="scan-col-host" /><col class="scan-col-mode" />
          <col class="scan-col-status" /><col class="scan-col-ports" /><col class="scan-col-started" />
          <col class="scan-col-duration" /><col class="scan-col-error" /><col class="scan-col-actions" />
        </colgroup>
        <thead><tr><th>State</th><th>Host</th><th>Profile</th><th>Status</th><th>Ports</th><th>Started</th><th>Duration</th><th>Error</th><th class="text-end">Actions</th></tr></thead>
        <tbody>
          <tr v-if="loading && scans.length === 0"><td class="text-secondary text-center py-4" colspan="9">Loading</td></tr>
          <tr v-else-if="!loading && scans.length === 0"><td class="text-secondary text-center py-4" colspan="9">No scans</td></tr>
          <tr v-for="scan in scans" :key="scan.id" :class="rowClass(scan)">
            <td><span :class="scanRunStateClass(scan.state)"><i :class="scan.state === 'running' ? 'ti ti-loader-2 is-spinning' : scanRunStateIcon(scan.state)"></i>{{ scan.state || '-' }}</span></td>
            <td class="scan-queue-host">
              <button v-if="scan.host_id" class="btn btn-link btn-sm p-0 scan-queue-host-name" type="button" @click="$emit('host-detail', scan.host_id)">{{ displayName(scan) }}</button>
              <strong v-else>{{ displayName(scan) }}</strong>
              <small class="font-monospace">{{ scan.ip }}</small>
            </td>
            <td>{{ scanProfileLabel(scan.mode) }}</td><td>{{ scan.status || '-' }}</td><td>{{ Number(scan.ports_count || 0) }}</td>
            <td class="text-nowrap">{{ formatScanDate(scan.date_begin) }}</td>
            <td class="text-nowrap">{{ formatScanDuration(activeScanDuration(scan, now)) }}</td>
            <td class="text-truncate-cell" :title="scan.error || ''">{{ scan.error || '-' }}</td>
            <td class="text-end action-cell">
              <button v-if="isAuthenticated && scan.ip" class="btn btn-outline-secondary btn-sm icon-btn" :class="{ 'is-spinning': isScanning(scan) || scan.state === 'running' }" type="button" :disabled="isScanning(scan) || scanIsActiveState(scan.state)" title="Scan host" @click="$emit('scan-host', scan)">
                <i :class="isScanning(scan) || scan.state === 'running' ? 'ti ti-loader-2' : 'ti ti-search'"></i>
              </button>
              <button class="btn btn-outline-secondary btn-sm icon-btn" type="button" title="View scan" :disabled="!scan.xml_usable" @click="$emit('open-scan', scan.ip, scan.id)"><i class="ti ti-file-search"></i></button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </section>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue';
import { apiJson, isAbortError } from '../lib/api.js';
import { useAbortableTask } from '../composables/useAbortableTask.js';
import { useNow } from '../composables/useNow.js';
import { usePageController } from '../composables/usePageController.js';
import { activeScanDuration, formatScanDate, formatScanDuration, scanIsActiveState, scanRunStateClass, scanRunStateIcon } from '../lib/formatters.js';
import { scanProfileLabel } from '../lib/scanProfiles.js';

defineOptions({ inheritAttrs: false });
const props = defineProps({ isAuthenticated: Boolean, scanningHosts: { type: Object, required: true } });
defineEmits(['host-detail', 'open-scan', 'scan-host']);
const scans = ref([]);
const loading = ref(false);
const error = ref('');
const request = useAbortableTask();
const now = useNow();

usePageController({ loading, label: computed(() => loading.value ? 'Loading' : 'Scans'), title: 'Refresh scans', disabled: false, refresh: load });
onMounted(load);

async function load() {
  const signal = request.nextSignal();
  loading.value = true;
  error.value = '';
  try {
    const data = await apiJson('/api/scans', { signal });
    if (request.isCurrent(signal)) scans.value = data?.scans || [];
  } catch (loadError) {
    if (!isAbortError(loadError) && request.isCurrent(signal)) error.value = loadError.message;
  } finally {
    if (request.isCurrent(signal)) loading.value = false;
  }
}

function scanKey(scan) { return String(scan?.ip || scan?.id || scan?.mac || ''); }
function isScanning(scan) { const key = scanKey(scan); return key !== '' && props.scanningHosts.has(key); }
function displayName(scan) { return scan?.name || scan?.ip || 'Unknown'; }
function rowClass(scan) {
  if (scan?.state) return `scan-row-${scan.state}`;
  if (scan?.important == 1 && scan?.status !== 'up') return 'important-down';
  return '';
}
</script>
