<template>
  <section>
    <div v-if="error" class="alert alert-danger mb-3" role="alert">{{ error }}</div>

    <div class="notify-header">
      <div>
        <h2>Notify</h2>
        <div class="text-secondary small">Last {{ notify.hours || 24 }}h of status and service changes</div>
      </div>
      <button class="btn btn-outline-secondary btn-sm" type="button" :disabled="loading" @click="load">
        <i class="ti ti-refresh me-1" :class="{ 'is-spinning': loading }"></i>
        Refresh
      </button>
    </div>

    <div class="notify-summary">
      <div class="notify-summary-item"><span>Total</span><strong>{{ summary.total || 0 }}</strong></div>
      <div class="notify-summary-item"><span>Hosts</span><strong>{{ summary.hosts || 0 }}</strong></div>
      <div class="notify-summary-item"><span>Services</span><strong>{{ summary.port_total || 0 }}</strong></div>
      <div v-for="item in statusCounts" :key="item.status" class="notify-summary-item">
        <span>{{ item.status || 'Unknown' }}</span><strong>{{ item.count }}</strong>
      </div>
    </div>

    <div class="notification-section-heading"><h3>Host status</h3><span class="text-secondary small">{{ summary.status_total || changes.length }} changes</span></div>
    <div class="table-wrap">
      <table class="table table-sm notify-table">
        <thead><tr><th>Time</th><th>Host</th><th>Change</th><th>Duration</th></tr></thead>
        <tbody>
          <tr v-if="loading && changes.length === 0"><td class="text-secondary text-center py-4" colspan="4">Loading</td></tr>
          <tr v-else-if="!loading && changes.length === 0"><td class="text-secondary text-center py-4" colspan="4">No changes in the last {{ notify.hours || 24 }}h</td></tr>
          <tr v-for="change in changes" :key="change.id" :class="{ 'important-down': change.important == 1 && change.status !== 'Up' }">
            <td class="notify-time">
              <span>{{ formatNotifyDate(change.date_begin) }}</span>
              <small>{{ formatRelativeAge(change.begin, now) }}</small>
            </td>
            <td class="notify-host">
              <button class="btn btn-link btn-sm p-0 notify-host-name" type="button" @click="$emit('open-history', change.ip)">{{ hostName(change) }}</button>
              <span class="font-monospace">{{ change.ip }}</span>
              <span class="font-monospace text-secondary">{{ formatMac(change.mac) }}</span>
              <span v-if="change.vendor" class="notify-vendor" :title="change.vendor">{{ change.vendor }}</span>
            </td>
            <td>
              <div class="notify-change">
                <span v-if="change.previous_status" :class="statusClass(change.previous_status)" :title="statusTitle(change.previous_status)"><i :class="statusIcon(change.previous_status)"></i></span>
                <span v-else class="status-pill status-unknown" title="new"><i class="ti ti-point"></i></span>
                <i class="ti ti-arrow-right text-secondary"></i>
                <span :class="statusClass(change.status)" :title="statusTitle(change.status)"><i :class="statusIcon(change.status)"></i></span>
                <strong>{{ change.status || 'Unknown' }}</strong>
              </div>
            </td>
            <td class="notify-duration">
              {{ formatDuration(displayDuration(change)) }}
              <span v-if="change.current == 1" class="badge bg-blue-lt text-blue ms-1">current</span>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <div class="notification-section-heading"><h3>Services</h3><span class="text-secondary small">{{ portChanges.length }} changes</span></div>
    <div class="table-wrap">
      <table class="table table-sm notify-table service-change-table">
        <thead><tr><th>Time</th><th>Host</th><th>Port</th><th>Change</th><th>Service</th><th>Scan</th></tr></thead>
        <tbody>
          <tr v-if="loading && portChanges.length === 0"><td class="text-secondary text-center py-4" colspan="6">Loading</td></tr>
          <tr v-else-if="!loading && portChanges.length === 0"><td class="text-secondary text-center py-4" colspan="6">No service changes in the last {{ notify.hours || 24 }}h</td></tr>
          <tr v-for="change in portChanges" :key="`port-${change.id}`" :class="{ 'important-down': change.important == 1 && change.change_type === 'disappeared' }">
            <td class="notify-time"><span>{{ formatNotifyDate(change.created_at) }}</span><small>{{ formatRelativeAge(change.created, now) }}</small></td>
            <td class="notify-host">
              <button class="btn btn-link btn-sm p-0 notify-host-name" type="button" @click="$emit('open-scan', change.ip, change.scan_id)">{{ hostName(change) }}</button>
              <span class="font-monospace">{{ change.ip }}</span>
              <span class="font-monospace text-secondary">{{ formatMac(change.mac) }}</span>
              <span v-if="change.vendor" class="notify-vendor" :title="change.vendor">{{ change.vendor }}</span>
            </td>
            <td class="font-monospace text-nowrap"><strong>{{ change.port }}</strong>/{{ change.protocol }}</td>
            <td><span class="badge" :class="portChangeClass(change.change_type)">{{ portChangeLabel(change.change_type) }}</span></td>
            <td class="service-change-value">
              <template v-if="change.change_type === 'changed'">
                <span class="text-secondary">{{ serviceLabel(change, 'previous') }}</span>
                <i class="ti ti-arrow-right"></i>
                <strong>{{ serviceLabel(change, 'current') }}</strong>
              </template>
              <strong v-else>{{ serviceLabel(change, change.change_type === 'appeared' ? 'current' : 'previous') }}</strong>
            </td>
            <td><span class="badge" :class="scanProfileBadgeClass(change.mode)">{{ scanProfileLabel(change.mode) }}</span></td>
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
import {
  formatDuration,
  formatMac,
  formatNotifyDate,
  formatRelativeAge,
  statusClass,
  statusIcon,
  statusTitle
} from '../lib/formatters.js';
import { scanProfileBadgeClass, scanProfileLabel } from '../lib/scanProfiles.js';

defineOptions({ inheritAttrs: false });
const emit = defineEmits(['network', 'open-history', 'open-scan']);
const notify = ref({ hours: 24, summary: {}, changes: [], port_changes: [] });
const loading = ref(false);
const error = ref('');
const now = useNow();
const request = useAbortableTask();
const changes = computed(() => notify.value.changes || []);
const portChanges = computed(() => notify.value.port_changes || []);
const summary = computed(() => notify.value.summary || {});
const statusCounts = computed(() => Object.entries(summary.value.status_counts || {})
  .sort(([a], [b]) => String(a).localeCompare(String(b)))
  .map(([status, count]) => ({ status, count })));

usePageController({
  loading,
  label: computed(() => loading.value ? 'Loading' : 'Notify'),
  title: 'Refresh notifications',
  disabled: false,
  refresh: load
});

onMounted(load);

async function load() {
  const signal = request.nextSignal();
  loading.value = true;
  error.value = '';
  try {
    const data = await apiJson('/api/notify', { signal });
    if (!request.isCurrent(signal)) return;
    emit('network', data.network || '');
    notify.value = {
      hours: data.hours || 24,
      summary: data.summary || {},
      changes: data.changes || [],
      port_changes: data.port_changes || []
    };
  } catch (loadError) {
    if (!isAbortError(loadError) && request.isCurrent(signal)) error.value = loadError.message;
  } finally {
    if (request.isCurrent(signal)) loading.value = false;
  }
}

function displayDuration(change) {
  if (change.current == 1 && Number(change.begin || 0) > 0)
    return Math.max(0, Math.floor(now.value / 1000) - Number(change.begin));
  return change.duration;
}

function hostName(change) {
  return change?.name || change?.ip || formatMac(change?.mac) || 'Unknown';
}

function portChangeLabel(type) {
  return ({ appeared: 'Appeared', disappeared: 'Disappeared', changed: 'Version changed' })[type] || 'Changed';
}

function portChangeClass(type) {
  return ({ appeared: 'bg-green-lt text-green', disappeared: 'bg-red-lt text-red', changed: 'bg-yellow-lt text-yellow' })[type] || 'bg-secondary-lt text-secondary';
}

function serviceLabel(change, prefix) {
  return [change?.[`${prefix}_service`], change?.[`${prefix}_version`]].filter(Boolean).join(' ') || '-';
}
</script>
