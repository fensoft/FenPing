<template>
  <section>
    <div v-if="error" class="alert alert-danger mb-3" role="alert">{{ error }}</div>
    <div class="page-header">
      <div>
        <RouterLink class="btn btn-link btn-sm p-0 mb-1" to="/"><i class="ti ti-arrow-left me-1"></i>Inventory</RouterLink>
        <h2>{{ title }}</h2><div class="text-secondary small font-monospace">{{ host.ip || 'No IP' }}</div>
      </div>
      <div class="page-actions">
        <button v-if="host.ip" class="btn btn-outline-secondary btn-sm" type="button" :disabled="!latestScan || !scanHasXml(latestScan)" @click="$emit('open-scan', host.ip, latestScan?.id)"><i class="ti ti-file-search me-1"></i>View scan</button>
        <button v-if="isAuthenticated && host.ip" class="btn btn-outline-primary btn-sm" type="button" :disabled="isScanning || scanIsActiveState(latestScan?.state)" @click="$emit('scan-host', host)"><i :class="isScanning ? 'ti ti-loader-2 is-spinning me-1' : 'ti ti-search me-1'"></i>Scan</button>
        <button v-if="isAuthenticated && host.id" class="btn btn-primary btn-sm" type="button" @click="$emit('open-edit', host)"><i class="ti ti-edit me-1"></i>Edit</button>
      </div>
    </div>
    <div v-if="loading" class="table-wrap detail-empty"><div class="text-secondary text-center py-4">Loading</div></div>
    <template v-else-if="detail">
      <div class="detail-summary">
        <div class="detail-fact"><span>Status</span><strong><span :class="statusClass(host.status)" class="status-pill"><i :class="statusIcon(host.status)"></i></span>{{ host.status || '-' }}</strong></div>
        <div class="detail-fact"><span>MAC</span><strong class="font-monospace">{{ formatMac(host.mac) || '-' }}</strong></div>
        <div class="detail-fact"><span>Vendor</span><strong>{{ host.vendor || '-' }}</strong></div>
        <div class="detail-fact"><span>Netboot</span><strong>{{ netbootName }}</strong></div>
      </div>
      <div class="detail-grid">
        <section class="detail-panel"><h3>Configuration</h3><dl class="detail-list">
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
            <tr v-for="item in historyRows" :key="item.id" :class="historyRowClass(item)"><td><span :class="statusClass(item.status)" :title="statusTitle(item.status)" class="status-pill"><i :class="statusIcon(item.status)"></i></span>{{ item.status || '-' }}</td><td class="font-monospace">{{ formatMac(item.mac) }}</td><td>{{ formatServerDate(item.date_begin) }}</td><td>{{ formatDuration(item.duration) }}</td></tr>
          </tbody>
        </table></div>
      </div>
      <div class="detail-section">
        <div class="detail-section-heading"><h3>Scan History</h3><span class="text-secondary small">{{ scans.length }} scans</span></div>
        <div class="table-wrap"><table class="table table-sm detail-table">
          <thead><tr><th>State</th><th>Profile</th><th>Status</th><th>Ports</th><th>Ended</th><th class="text-end">Actions</th></tr></thead>
          <tbody><tr v-if="scans.length === 0"><td class="text-secondary text-center py-4" colspan="6">No scans</td></tr>
            <tr v-for="scan in scans" :key="scan.id"><td><span :class="scanRunStateClass(scan.state)"><i :class="scan.state === 'running' ? 'ti ti-loader-2 is-spinning' : scanRunStateIcon(scan.state)"></i>{{ scan.state || '-' }}</span></td><td>{{ scanProfileLabel(scan.mode) }}</td><td>{{ scan.status || '-' }}</td><td>{{ Number(scan.ports_count || 0) }}</td><td>{{ formatScanDate(scan.date_end || scan.date_begin) }}</td><td class="text-end"><button class="btn btn-outline-secondary btn-sm icon-btn" type="button" title="View scan" :disabled="!scanHasXml(scan)" @click="$emit('open-scan', host.ip, scan.id)"><i class="ti ti-file-search"></i></button></td></tr>
          </tbody>
        </table></div>
      </div>
    </template>
  </section>
</template>

<script setup>
import { computed, ref, watch } from 'vue';
import { useRoute } from 'vue-router';
import { apiJson, isAbortError } from '../lib/api.js';
import { useAbortableTask } from '../composables/useAbortableTask.js';
import { usePageController } from '../composables/usePageController.js';
import { formatDuration, formatMac, formatScanDate, formatServerDate, historyRowClass, scanIsActiveState, scanRunStateClass, scanRunStateIcon, statusClass, statusIcon, statusTitle, toFlag } from '../lib/formatters.js';
import { scanCadenceLabel, scanProfileLabel } from '../lib/scanProfiles.js';

defineOptions({ inheritAttrs: false });
const props = defineProps({ isAuthenticated: Boolean, scanningHosts: { type: Object, required: true } });
defineEmits(['open-edit', 'open-scan', 'scan-host']);
const route = useRoute();
const detail = ref(null);
const loading = ref(false);
const error = ref('');
const request = useAbortableTask();
const host = computed(() => detail.value?.host || {});
const latestScan = computed(() => detail.value?.latest_scan || null);
const scans = computed(() => detail.value?.scans || []);
const historyRows = computed(() => detail.value?.history?.rows || []);
const title = computed(() => host.value.name || host.value.ip || 'Host detail');
const netbootName = computed(() => detail.value?.netboot_image?.name || detail.value?.netboot_image?.filename || '-');
const scanSchedule = computed(() => `${scanProfileLabel(host.value.scan_profile)} · ${scanCadenceLabel(host.value.scan_interval_hours)}`);
const isScanning = computed(() => props.scanningHosts.has(String(host.value?.ip || host.value?.id || host.value?.mac || '')));

usePageController({ loading, label: computed(() => loading.value ? 'Loading' : 'Device'), title: 'Refresh host', disabled: false, refresh: load });
watch(() => route.params.id, load, { immediate: true });

async function load() {
  const id = Number(route.params.id || 0);
  if (!id) return;
  const signal = request.nextSignal();
  loading.value = true;
  error.value = '';
  try {
    const data = await apiJson(`/api/hosts/${encodeURIComponent(id)}/detail`, { signal });
    if (request.isCurrent(signal)) detail.value = data;
  } catch (loadError) {
    if (!isAbortError(loadError) && request.isCurrent(signal)) { detail.value = null; error.value = loadError.message; }
  } finally {
    if (request.isCurrent(signal)) loading.value = false;
  }
}

function scanHasXml(scan) {
  if (!scan) return false;
  return Object.prototype.hasOwnProperty.call(scan, 'xml_usable') ? Boolean(scan.xml_usable) : Boolean(scan.xml || scan.xml_url);
}
</script>
