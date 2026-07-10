<template>
  <section>
    <div v-if="error" class="alert alert-danger mb-3" role="alert">{{ error }}</div>
    <div class="page-header">
      <div><h2>Services</h2><div class="text-secondary small">Open services from each host's latest effective scan</div></div>
      <button class="btn btn-outline-secondary btn-sm" type="button" :disabled="loading" @click="load"><i class="ti ti-refresh me-1" :class="{ 'is-spinning': loading }"></i>Refresh</button>
    </div>

    <div class="notify-summary">
      <div class="notify-summary-item"><span>Computers</span><strong>{{ summary.hosts || 0 }}</strong></div>
      <div class="notify-summary-item"><span>Services</span><strong>{{ summary.services || 0 }}</strong></div>
      <div class="notify-summary-item"><span>Visible</span><strong>{{ visibleServices.length }}</strong></div>
    </div>

    <div class="table-wrap">
      <div class="table-toolbar">
        <div class="input-icon filter-search"><span class="input-icon-addon"><i class="ti ti-search"></i></span><input v-model="search" class="form-control form-control-sm" type="search" placeholder="Search host, port, service, or version" /></div>
        <button v-if="search" class="btn btn-outline-secondary btn-sm icon-btn" type="button" title="Clear search" @click="search = ''"><i class="ti ti-x"></i></button>
      </div>
      <table class="table table-sm services-table">
        <thead><tr><th>Computer</th><th>IP</th><th>Port</th><th>Service</th><th>Version</th><th>Source</th><th>Scanned</th><th class="text-end">Actions</th></tr></thead>
        <tbody>
          <tr v-if="loading && services.length === 0"><td class="text-secondary text-center py-4" colspan="8">Loading</td></tr>
          <tr v-else-if="!loading && visibleServices.length === 0"><td class="text-secondary text-center py-4" colspan="8">{{ search ? 'No matching services' : 'No open services found' }}</td></tr>
          <tr v-for="row in visibleServices" :key="`${row.ip}-${row.protocol}-${row.port}`">
            <td class="services-host">
              <button v-if="row.host_id" class="btn btn-link btn-sm p-0 services-host-name" type="button" @click="$emit('host-detail', row.host_id)">{{ hostName(row) }}</button>
              <strong v-else>{{ hostName(row) }}</strong>
              <small v-if="row.mac" class="font-monospace">{{ formatMac(row.mac) }}</small>
              <small v-if="row.vendor" :title="row.vendor">{{ row.vendor }}</small>
            </td>
            <td class="font-monospace text-nowrap">{{ row.ip }}</td>
            <td class="font-monospace text-nowrap"><strong>{{ row.port }}</strong>/{{ row.protocol }}</td>
            <td><strong>{{ row.service || 'unknown' }}</strong></td>
            <td class="services-version" :title="row.version || ''">{{ row.version || '-' }}</td>
            <td><span class="badge" :class="scanProfileBadgeClass(row.source || row.scan_mode)">{{ scanProfileLabel(row.source || row.scan_mode) }}</span></td>
            <td class="text-nowrap"><span>{{ formatScanDate(row.scan_date) }}</span><small v-if="row.merged">{{ scanProfileLabel(row.scan_mode) }} + Deep</small></td>
            <td class="text-end action-cell">
              <a v-if="serviceUrl(row)" class="btn btn-outline-primary btn-sm icon-btn" :href="serviceUrl(row)" target="_blank" rel="noopener noreferrer" title="Open web service"><i class="ti ti-external-link"></i></a>
              <button class="btn btn-outline-secondary btn-sm icon-btn" type="button" title="View scan" @click="$emit('open-scan', row.ip, row.scan_id)"><i class="ti ti-file-search"></i></button>
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
import { usePageController } from '../composables/usePageController.js';
import { formatMac, formatScanDate } from '../lib/formatters.js';
import { scanProfileBadgeClass, scanProfileLabel } from '../lib/scanProfiles.js';

defineOptions({ inheritAttrs: false });
const emit = defineEmits(['host-detail', 'network', 'open-scan']);
const services = ref([]);
const summary = ref({ hosts: 0, services: 0 });
const loading = ref(false);
const error = ref('');
const search = ref('');
const request = useAbortableTask();
const visibleServices = computed(() => {
  const query = search.value.trim().toLowerCase();
  if (query === '') return services.value;
  return services.value.filter((row) => [row.name, row.ip, row.mac, row.vendor, row.port, row.protocol, row.service, row.version, row.source]
    .some((value) => String(value || '').toLowerCase().includes(query)));
});

usePageController({ loading, label: computed(() => loading.value ? 'Loading' : 'Services'), title: 'Refresh services', disabled: false, refresh: load });
onMounted(load);

async function load() {
  const signal = request.nextSignal();
  loading.value = true;
  error.value = '';
  try {
    const data = await apiJson('/api/services', { signal });
    if (!request.isCurrent(signal)) return;
    emit('network', data.network || '');
    services.value = data.services || [];
    summary.value = data.summary || { hosts: 0, services: 0 };
  } catch (loadError) {
    if (!isAbortError(loadError) && request.isCurrent(signal)) error.value = loadError.message;
  } finally {
    if (request.isCurrent(signal)) loading.value = false;
  }
}

function hostName(row) {
  return row?.name || row?.ip || formatMac(row?.mac) || 'Unknown';
}

function serviceUrl(row) {
  const service = String(row?.service || '').toLowerCase();
  if (!service.includes('http')) return '';
  const port = Number(row?.port || 0);
  const secure = String(row?.tunnel || '').toLowerCase() === 'ssl'
    || service.includes('https')
    || service.includes('ssl/http')
    || [443, 8443, 9443].includes(port);
  const scheme = secure ? 'https' : 'http';
  const defaultPort = secure ? 443 : 80;
  return `${scheme}://${row.ip}${port && port !== defaultPort ? `:${port}` : ''}`;
}
</script>
