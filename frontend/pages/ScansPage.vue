<template>
  <section>
    <div v-if="error" class="alert alert-danger mb-3" role="alert">{{ error }}</div>
    <div class="page-refresh-header">
      <div><h2>{{ t('Scans') }}</h2><div class="text-secondary small">{{ t('Queued, running, and recent inventory scans') }}</div></div>
      <button class="btn btn-outline-secondary btn-sm" type="button" :disabled="loading" @click="load">
        <AppIcon name="refresh" class="me-1" :class="{ 'is-spinning': loading }" />{{ t('Refresh') }}
      </button>
    </div>
    <div v-if="policy" class="scan-policy-grid mb-3">
      <div class="scan-policy-card"><span>{{ t('Global concurrency') }}</span><strong>{{ policy.global?.running || 0 }} / {{ policy.global?.concurrency_limit || 0 }}</strong></div>
      <div v-for="networkPolicy in policy.networks || []" :key="networkPolicy.network" class="scan-policy-card">
        <span class="font-monospace">{{ networkPolicy.network }}</span>
        <strong>{{ t('{running}/{limit} running', { running: networkPolicy.running, limit: networkPolicy.concurrency_limit }) }}</strong>
        <small>{{ t('{used}/{budget} scheduled scans in 24 hours', { used: networkPolicy.scheduled_starts_24h, budget: networkPolicy.daily_budget }) }}</small>
        <small v-if="networkPolicy.budget_eligible_at">{{ t('Eligible {date}', { date: formatScanDate(networkPolicy.budget_eligible_at) }) }}</small>
      </div>
    </div>
    <div class="table-wrap">
      <table class="table table-sm scan-queue-table">
        <colgroup>
          <col class="scan-col-state" /><col class="scan-col-host" /><col class="scan-col-mode" />
          <col class="scan-col-network" /><col class="scan-col-status" /><col class="scan-col-progress" /><col class="scan-col-ports" /><col class="scan-col-started" />
          <col class="scan-col-duration" /><col class="scan-col-error" /><col class="scan-col-actions" />
        </colgroup>
        <thead><tr><th>{{ t('State') }}</th><th>{{ t('Host') }}</th><th>{{ t('Profile') }}</th><th>{{ t('Network') }}</th><th>{{ t('Status') }}</th><th>{{ t('Progress') }}</th><th>{{ t('Ports') }}</th><th>{{ t('Started') }}</th><th>{{ t('Duration') }}</th><th>{{ t('Error') }}</th><th class="text-end">{{ t('Actions') }}</th></tr></thead>
        <tbody>
          <tr v-if="loading && scans.length === 0"><td class="text-secondary text-center py-4" colspan="11">{{ t('Loading') }}</td></tr>
          <tr v-else-if="!loading && scans.length === 0"><td class="text-secondary text-center py-4" colspan="11">{{ t('No scans') }}</td></tr>
          <tr v-for="scan in scans" :key="scan.id" :class="rowClass(scan)">
            <td><span :class="scanRunStateClass(scan.state)"><AppIcon :name="scan.state === 'running' ? 'loader-2' : scanRunStateIcon(scan.state)" :class="{ 'is-spinning': scan.state === 'running' }" />{{ scanRunStateLabel(scan.state) }}</span></td>
            <td class="scan-queue-host">
              <button v-if="scan.host_id" class="btn btn-link btn-sm p-0 scan-queue-host-name" type="button" :title="displayName(scan)" @click="$emit('host-detail', scan.host_id)">{{ displayName(scan) }}</button>
              <strong v-else class="scan-queue-host-name" :title="displayName(scan)">{{ displayName(scan) }}</strong>
              <small class="font-monospace" :title="scan.ip || ''">{{ scan.ip }}</small>
            </td>
            <td><span class="badge" :class="scanProfileBadgeClass(scan.mode)">{{ scanProfileLabel(scan.mode) }}</span></td>
            <td class="font-monospace text-nowrap">{{ scan.network || '-' }}</td><td>{{ scan.status ? t(scan.status) : '-' }}</td>
            <td class="scan-progress-cell"><div class="scan-progress-copy">{{ scanProgressLabel(scan) }}</div><div v-if="scan.state === 'running'" class="progress progress-sm" role="progressbar" :aria-label="scanProgressLabel(scan)" :aria-valuenow="progressWidth(scan)" aria-valuemin="0" aria-valuemax="100"><div class="progress-bar" :style="{ width: `${progressWidth(scan)}%` }"></div></div><small v-if="scan.budget_eligible_at" class="text-secondary">{{ t('Eligible {date}', { date: formatScanDate(scan.budget_eligible_at) }) }}</small></td>
            <td>{{ Number(scan.ports_count || 0) }}</td>
            <td class="text-nowrap">{{ formatScanDate(scan.date_begin) }}</td>
            <td class="text-nowrap">{{ formatScanDuration(activeScanDuration(scan, now)) }}</td>
            <td class="text-center"><button v-if="scan.error" class="btn btn-outline-danger btn-sm icon-btn" type="button" :title="t('Details')" :aria-label="`${t('Error')}: ${t('Details')}`" @click="$emit('open-scan-error', scan)"><AppIcon name="question-mark" /></button><span v-else>-</span></td>
            <td class="text-end action-cell">
              <button v-if="isAuthenticated && scanIsActiveState(scan.state)" class="btn btn-outline-danger btn-sm icon-btn" type="button" :disabled="!scanCanCancel(scan) || isCancelling(scan)" :title="t(scan.cancel_requested ? 'Cancelling' : 'Cancel scan')" :aria-label="t(scan.cancel_requested ? 'Cancelling' : 'Cancel scan')" @click="$emit('cancel-scan', scan)">
                <AppIcon :name="scan.cancel_requested || isCancelling(scan) ? 'loader-2' : 'x'" :class="{ 'is-spinning': scan.cancel_requested || isCancelling(scan) }" />
              </button>
              <button v-if="isAuthenticated && scan.ip" class="btn btn-outline-secondary btn-sm icon-btn" :class="{ 'is-spinning': isScanning(scan) || scan.state === 'running' }" type="button" :disabled="isScanning(scan) || scanIsActiveState(scan.state)" :title="t('Scan host')" @click="$emit('scan-host', scan)">
                <AppIcon :name="isScanning(scan) || scan.state === 'running' ? 'loader-2' : 'search'" />
              </button>
              <button class="btn btn-outline-secondary btn-sm icon-btn" type="button" :title="t('View scan')" :disabled="!(scan.result_available ?? scan.xml_usable)" @click="$emit('open-scan', scan.ip, scan.id)"><AppIcon name="file-search" /></button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </section>
</template>

<script setup>
import { computed, onMounted, onUnmounted, ref } from 'vue';
import { apiJson, isAbortError } from '../lib/api.js';
import { t } from '../lib/i18n.js';
import { useAbortableTask } from '../composables/useAbortableTask.js';
import { useLiveRefresh } from '../composables/useLiveUpdates.js';
import { useNow } from '../composables/useNow.js';
import { usePageController } from '../composables/usePageController.js';
import { activeScanDuration, formatScanDate, formatScanDuration, scanCanCancel, scanIsActiveState, scanProgressLabel, scanRunStateClass, scanRunStateIcon, scanRunStateLabel } from '../lib/formatters.js';
import { scanProfileBadgeClass, scanProfileLabel } from '../lib/scanProfiles.js';

defineOptions({ inheritAttrs: false });
const props = defineProps({ isAuthenticated: Boolean, scanningHosts: { type: Object, required: true }, cancellingScans: { type: Object, required: true } });
defineEmits(['cancel-scan', 'host-detail', 'open-scan', 'open-scan-error', 'scan-host']);
const scans = ref([]);
const policy = ref(null);
const loading = ref(false);
const error = ref('');
const request = useAbortableTask();
const now = useNow();
let pollTimer = null;

usePageController({ loading, label: computed(() => t(loading.value ? 'Loading' : 'Scans')), title: computed(() => t('Refresh scans')), disabled: false, refresh: load });
useLiveRefresh(['hosts', 'status', 'scans', 'vendors'], load);
onMounted(() => {
  load();
  pollTimer = window.setInterval(() => {
    if (!loading.value && scans.value.some((scan) => scanIsActiveState(scan.state)))
      load();
  }, 5000);
});
onUnmounted(() => {
  if (pollTimer !== null)
    window.clearInterval(pollTimer);
});

async function load() {
  const signal = request.nextSignal();
  loading.value = true;
  error.value = '';
  try {
    const data = await apiJson('/api/scans', { signal });
    if (request.isCurrent(signal)) {
      scans.value = data?.scans || [];
      policy.value = data?.policy || null;
    }
  } catch (loadError) {
    if (!isAbortError(loadError) && request.isCurrent(signal)) error.value = loadError.message;
  } finally {
    if (request.isCurrent(signal)) loading.value = false;
  }
}

function scanKey(scan) { return String(scan?.ip || scan?.id || scan?.mac || ''); }
function isScanning(scan) { const key = scanKey(scan); return key !== '' && props.scanningHosts.has(key); }
function displayName(scan) { return scan?.name || scan?.ip || t('Unknown'); }
function isCancelling(scan) { return props.cancellingScans.has(Number(scan?.id || 0)); }
function progressWidth(scan) { return Math.max(0, Math.min(100, Number(scan?.progress_percent || 0))); }
function rowClass(scan) {
  if (scan?.state) return `scan-row-${scan.state}`;
  if (scan?.important == 1 && scan?.status !== 'up') return 'important-down';
  return '';
}
</script>
