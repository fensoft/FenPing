<template>
  <section>
    <div class="page-refresh-header">
      <div><h2>{{ t('Operations') }}</h2><div class="text-secondary small">{{ t('Operator health, exceptions, and live diagnostics') }}</div></div>
      <div class="d-flex gap-2">
        <button class="btn btn-outline-secondary btn-sm" type="button" :disabled="loading" @click="loadAll"><AppIcon name="refresh" class="me-1" :class="{ 'is-spinning': healthLoading }" />{{ t('Refresh') }}</button>
        <button class="btn btn-primary btn-sm" type="button" :disabled="doctorLoading || !isAuthenticated" @click="loadDoctor"><AppIcon name="stethoscope" class="me-1" :class="{ 'is-spinning': doctorLoading }" />{{ t('Run diagnostics') }}</button>
      </div>
    </div>

    <div v-if="healthError" class="alert alert-danger mb-3" role="alert">{{ healthError }}</div>
    <div v-if="health" class="operations-exception-lead" :class="statusBorderClass(health.status)" role="status">
      <AppIcon :name="health.status === 'ok' ? 'circle-check' : 'alert-triangle'" class="operations-exception-icon" />
      <div class="flex-fill"><div class="operations-kicker">{{ t('Current exceptions') }}</div><h3>{{ exceptionHeadline }}</h3><div class="small text-secondary">{{ t('Updated {time}', { time: formatServerDate(health.checked_at) }) }}</div></div>
      <span class="badge" :class="statusBadgeClass(health.status)">{{ healthStatusLabel(health.status) }}</span>
    </div>
    <div v-else-if="healthLoading" class="card mb-3"><div class="card-body text-secondary text-center py-4"><AppIcon name="loader-2" class="is-spinning me-1" />{{ t('Loading appliance health') }}</div></div>

    <template v-if="health">
      <div class="operations-exception-links">
        <RouterLink class="operations-exception-link" to="/ipam"><span>{{ t('New devices') }}</span><strong>{{ valueOrDash(health.exceptions?.new_devices) }}</strong></RouterLink>
        <RouterLink class="operations-exception-link" to="/"><span>{{ t('Important hosts down') }}</span><strong>{{ valueOrDash(health.exceptions?.important_hosts_down) }}</strong></RouterLink>
        <RouterLink class="operations-exception-link" to="/scans"><span>{{ t('Failed scans') }}</span><strong>{{ health.scans?.failed || 0 }}</strong></RouterLink>
        <RouterLink class="operations-exception-link" to="/scans"><span>{{ t('Timed-out scans') }}</span><strong>{{ health.scans?.timed_out || 0 }}</strong></RouterLink>
        <RouterLink class="operations-exception-link" to="/backups"><span>{{ t('Backup') }}</span><strong>{{ health.jobs?.backup?.overdue ? t('Overdue') : t('Current') }}</strong></RouterLink>
      </div>

      <div class="operations-section-heading"><div><h3>{{ t('Appliance health') }}</h3><div class="small text-secondary">{{ t('Capacity, background jobs, and service delivery') }}</div></div></div>
      <div class="row g-3 operations-health-grid">
        <div class="col-12 col-md-6 col-xl-4"><article class="card h-100"><div class="card-body">
          <div class="operations-card-title"><AppIcon name="radar" /><h4>{{ t('Scan queue') }}</h4><span class="badge" :class="levelBadgeClass(health.scans?.queue_status)">{{ levelLabel(health.scans?.queue_status) }}</span></div>
          <div class="operations-metrics">
            <div><span>{{ t('Queued') }}</span><strong>{{ health.scans?.queued || 0 }}</strong></div><div><span>{{ t('Running') }}</span><strong>{{ health.scans?.running || 0 }}</strong></div>
            <div><span>{{ t('Failed') }}</span><strong>{{ health.scans?.failed || 0 }}</strong></div><div><span>{{ t('Timed out') }}</span><strong>{{ health.scans?.timed_out || 0 }}</strong></div>
          </div>
          <div class="small text-secondary mt-3">{{ t('Oldest queued job: {age}', { age: ageLabel(health.scans?.oldest_queued_age_seconds) }) }}</div>
        </div></article></div>

        <div class="col-12 col-md-6 col-xl-4"><article class="card h-100"><div class="card-body">
          <div class="operations-card-title"><AppIcon name="clock-check" /><h4>{{ t('Background jobs') }}</h4></div>
          <div class="operations-job-list"><div v-for="job in jobs" :key="job.key"><span>{{ job.label }}</span><strong :class="{ 'text-danger': job.data?.overdue }">{{ job.data?.overdue ? t('Overdue') : ageLabel(job.data?.age_seconds) }}</strong><small>{{ dateOrNever(job.data?.last_success_at) }}</small></div></div>
        </div></article></div>

        <div class="col-12 col-md-6 col-xl-4"><article class="card h-100"><div class="card-body">
          <div class="operations-card-title"><AppIcon name="database" /><h4>{{ t('SQLite and disk') }}</h4><span class="badge" :class="levelBadgeClass(health.storage?.status)">{{ levelLabel(health.storage?.status) }}</span></div>
          <dl class="operations-details">
            <div><dt>{{ t('Database') }}</dt><dd>{{ formatBytes(health.storage?.sqlite_bytes) }}</dd></div><div><dt>WAL</dt><dd>{{ formatBytes(health.storage?.wal_bytes) }}</dd></div>
            <div><dt>{{ t('Free disk space') }}</dt><dd>{{ formatBytes(health.storage?.disk_free_bytes) }}</dd></div><div><dt>{{ t('Disk used') }}</dt><dd>{{ percentLabel(health.storage?.disk_used_percent) }}</dd></div>
            <div><dt>{{ t('Integrity check') }}</dt><dd><span class="badge" :class="levelBadgeClass(integrityLevel)">{{ integrityLabel }}</span></dd></div>
          </dl><div v-if="health.integrity?.error" class="small text-danger mt-2">{{ health.integrity.error }}</div>
        </div></article></div>

        <div class="col-12 col-md-6 col-xl-4"><article class="card h-100"><div class="card-body">
          <div class="operations-card-title"><AppIcon name="address-book" /><h4>{{ t('DHCP pool') }}</h4><span class="badge" :class="levelBadgeClass(health.dhcp?.status)">{{ levelLabel(health.dhcp?.status) }}</span></div>
          <div class="operations-capacity-value">{{ health.dhcp?.occupied ?? '-' }} / {{ health.dhcp?.total ?? '-' }}</div>
          <div class="progress progress-sm my-2"><div class="progress-bar" :class="progressClass(health.dhcp?.status)" :style="{ width: progressWidth(health.dhcp?.utilization_percent) }"></div></div>
          <div class="small text-secondary">{{ t('{count} addresses available', { count: health.dhcp?.available ?? '-' }) }} · {{ percentLabel(health.dhcp?.utilization_percent) }}</div>
        </div></article></div>

        <div class="col-12 col-md-6 col-xl-4"><article class="card h-100"><div class="card-body">
          <div class="operations-card-title"><AppIcon name="server" /><h4>{{ t('Core services') }}</h4></div>
          <dl class="operations-details">
            <div><dt>dnsmasq</dt><dd><span class="badge" :class="booleanBadge(health.dnsmasq?.running)">{{ booleanLabel(health.dnsmasq?.running) }}</span></dd></div>
            <div><dt>{{ t('Cron') }}</dt><dd><span class="badge" :class="booleanBadge(health.cron?.running)">{{ booleanLabel(health.cron?.running) }}</span></dd></div>
            <div><dt>{{ t('Configuration failures') }}</dt><dd>{{ health.dnsmasq?.generation?.recent_failures || 0 }}</dd></div>
            <div><dt>{{ t('Readiness') }}</dt><dd><span class="badge" :class="booleanBadge(health.readiness?.ready)">{{ health.readiness?.ready ? t('Ready') : t('Not ready') }}</span></dd></div>
          </dl><div v-if="health.dnsmasq?.generation?.last_error" class="small text-danger mt-2">{{ health.dnsmasq.generation.last_error }}</div>
        </div></article></div>

        <div class="col-12 col-md-6 col-xl-4"><article class="card h-100"><div class="card-body">
          <div class="operations-card-title"><AppIcon name="bell" /><h4>{{ t('Notifications') }}</h4></div>
          <dl class="operations-details">
            <div><dt>{{ t('Delivery') }}</dt><dd><span class="badge" :class="health.notifications?.enabled ? 'bg-blue-lt text-blue' : 'bg-secondary-lt text-secondary'">{{ t(health.notifications?.enabled ? 'Enabled' : 'Disabled') }}</span></dd></div>
            <div><dt>{{ t('Delivery failures') }}</dt><dd>{{ health.notifications?.delivery?.recent_failures || 0 }}</dd></div>
            <div><dt>{{ t('Last success') }}</dt><dd>{{ dateOrNever(health.notifications?.delivery?.last_success_at) }}</dd></div>
          </dl><div v-if="health.notifications?.delivery?.last_error" class="small text-danger mt-2">{{ health.notifications.delivery.last_error }}</div>
        </div></article></div>
      </div>
    </template>

    <div class="operations-section-heading mt-4"><div><h3>{{ t('Live diagnostics') }}</h3><div class="small text-secondary">{{ t('Privileged network, listener, and storage checks') }}</div></div></div>
    <div v-if="doctorError" class="alert alert-danger mb-3" role="alert">{{ doctorError }}</div>
    <div v-if="!isAuthenticated" class="alert alert-info" role="alert">{{ t('Login to run system diagnostics.') }}<button class="btn btn-primary btn-sm ms-2" type="button" @click="$emit('login')">{{ t('Login') }}</button></div>
    <template v-else>
      <div v-if="doctorLoading && !report" class="card"><div class="card-body text-secondary text-center py-5"><AppIcon name="loader-2" class="is-spinning me-1" />{{ t('Running diagnostics') }}</div></div>
      <template v-if="report">
        <div class="alert d-flex align-items-start gap-2" :class="report.status === 'ok' ? 'alert-success' : 'alert-danger'" role="status"><AppIcon :name="report.status === 'ok' ? 'check' : 'alert-triangle'" class="mt-1" /><div><strong>{{ t(report.status === 'ok' ? 'All checks passed' : 'One or more checks failed') }}</strong><div class="small">{{ t('Checked {time}', { time: formatServerDate(report.checked_at) }) }}</div></div></div>
        <div class="row g-3"><div v-for="check in report.checks || []" :key="check.id" class="col-12 col-lg-6"><article class="card h-100" :class="check.status === 'pass' ? 'border-success' : 'border-danger'"><div class="card-body">
          <div class="d-flex align-items-center gap-2 mb-2"><AppIcon :name="check.status === 'pass' ? 'check' : 'alert-triangle'" :class="check.status === 'pass' ? 'text-success' : 'text-danger'" /><h4 class="mb-0 flex-fill">{{ t(checkLabel(check.id)) }}</h4><span class="badge" :class="check.status === 'pass' ? 'bg-green-lt text-green' : 'bg-red-lt text-red'">{{ t(check.status === 'pass' ? 'Passed' : 'Failed') }}</span></div>
          <p class="mb-0">{{ check.message }}</p><div v-if="check.remediation" class="alert alert-warning py-2 px-3 mt-3 mb-0"><AppIcon name="bolt" class="me-1" />{{ check.remediation }}</div>
        </div></article></div></div>
      </template>
    </template>
  </section>
</template>

<script setup>
import { computed, ref, watch } from 'vue';
import { apiJson, isAbortError } from '../lib/api.js';
import { useAbortableTask } from '../composables/useAbortableTask.js';
import { usePageController } from '../composables/usePageController.js';
import { formatBytes, formatDuration, formatServerDate } from '../lib/formatters.js';
import { t } from '../lib/i18n.js';

defineOptions({ inheritAttrs: false });
const props = defineProps({ isAuthenticated: Boolean });
defineEmits(['login']);
const health = ref(null);
const report = ref(null);
const healthLoading = ref(false);
const doctorLoading = ref(false);
const healthError = ref('');
const doctorError = ref('');
const healthRequest = useAbortableTask();
const doctorRequest = useAbortableTask();
const loading = computed(() => healthLoading.value || doctorLoading.value);
const jobs = computed(() => [
  { key: 'ping', label: t('Ping'), data: health.value?.jobs?.ping },
  { key: 'discovery', label: t('Discovery'), data: health.value?.jobs?.discovery },
  { key: 'lease_import', label: t('Lease import'), data: health.value?.jobs?.lease_import },
  { key: 'oui_update', label: t('OUI update'), data: health.value?.jobs?.oui_update },
  { key: 'backup', label: t('Backup'), data: health.value?.jobs?.backup }
]);
const exceptionHeadline = computed(() => {
  const parts = [];
  const exceptions = health.value?.exceptions || {};
  const scans = health.value?.scans || {};
  if (Number(exceptions.new_devices || 0) > 0) parts.push(t('{count} new devices', { count: exceptions.new_devices }));
  if (Number(exceptions.important_hosts_down || 0) > 0) parts.push(t('{count} important hosts down', { count: exceptions.important_hosts_down }));
  if (Number(scans.failed || 0) > 0) parts.push(t('{count} failed scans', { count: scans.failed }));
  if (Number(scans.timed_out || 0) > 0) parts.push(t('{count} timed-out scans', { count: scans.timed_out }));
  if (health.value?.jobs?.backup?.overdue) parts.push(t('backup overdue'));
  return parts.length > 0 ? parts.join(', ') + '.' : t('No active exceptions');
});
const integrityLevel = computed(() => health.value?.integrity?.status === 'ok' ? 'ok' : health.value?.integrity?.status === 'failed' ? 'critical' : 'unknown');
const integrityLabel = computed(() => t(health.value?.integrity?.status === 'ok' ? 'Passed' : health.value?.integrity?.status === 'failed' ? 'Failed' : 'Unknown'));

usePageController({ loading, label: computed(() => t(loading.value ? 'Loading' : 'Operations')), title: computed(() => t('Refresh operations')), disabled: false, refresh: loadAll });
watch(() => props.isAuthenticated, (authenticated) => {
  loadHealth();
  if (authenticated) loadDoctor();
  else { doctorRequest.abort(); report.value = null; doctorError.value = ''; }
}, { immediate: true });

async function loadAll() { await Promise.allSettled([loadHealth(), props.isAuthenticated ? loadDoctor() : Promise.resolve()]); }
async function loadHealth() {
  const signal = healthRequest.nextSignal(); healthLoading.value = true; healthError.value = '';
  try { const data = await apiJson('/api/health', { signal }); if (healthRequest.isCurrent(signal)) health.value = data; }
  catch (error) { if (!isAbortError(error) && healthRequest.isCurrent(signal)) healthError.value = error.message; }
  finally { if (healthRequest.isCurrent(signal)) healthLoading.value = false; }
}
async function loadDoctor() {
  if (!props.isAuthenticated || doctorLoading.value) return;
  const signal = doctorRequest.nextSignal(); doctorLoading.value = true; doctorError.value = '';
  try { const data = await apiJson('/api/doctor', { signal }); if (doctorRequest.isCurrent(signal)) report.value = data; }
  catch (error) { if (!isAbortError(error) && doctorRequest.isCurrent(signal)) doctorError.value = error.message; }
  finally { if (doctorRequest.isCurrent(signal)) doctorLoading.value = false; }
}

function ageLabel(seconds) { return seconds === null || seconds === undefined ? t('Never') : t('{age} ago', { age: formatDuration(seconds) }); }
function dateOrNever(value) { return value ? formatServerDate(value) : t('Never'); }
function valueOrDash(value) { return value === null || value === undefined ? '-' : value; }
function percentLabel(value) { return value === null || value === undefined ? '-' : Number(value).toFixed(1) + '%'; }
function progressWidth(value) { return Math.min(100, Number(value || 0)) + '%'; }
function healthStatusLabel(status) { return t(status === 'ok' ? 'Healthy' : status === 'warning' ? 'Warning' : 'Degraded'); }
function statusBadgeClass(status) { return status === 'ok' ? 'bg-green-lt text-green' : status === 'warning' ? 'bg-yellow-lt text-yellow' : 'bg-red-lt text-red'; }
function statusBorderClass(status) { return status === 'ok' ? 'operations-status-ok' : status === 'warning' ? 'operations-status-warning' : 'operations-status-critical'; }
function levelLabel(level) { return t(level === 'ok' ? 'Healthy' : level === 'warning' ? 'Warning' : level === 'critical' ? 'Critical' : 'Unknown'); }
function levelBadgeClass(level) { return level === 'ok' ? 'bg-green-lt text-green' : level === 'warning' ? 'bg-yellow-lt text-yellow' : level === 'critical' ? 'bg-red-lt text-red' : 'bg-secondary-lt text-secondary'; }
function booleanLabel(value) { return t(value ? 'Running' : 'Stopped'); }
function booleanBadge(value) { return value ? 'bg-green-lt text-green' : 'bg-red-lt text-red'; }
function progressClass(level) { return level === 'critical' ? 'bg-danger' : level === 'warning' ? 'bg-warning' : 'bg-success'; }
function checkLabel(id) { return ({ interface: 'Interface', subnet: 'Subnet', router: 'Router', 'dhcp-pool': 'DHCP pool', ports: 'Ports', storage: 'Storage', 'dhcp-server': 'DHCP server' })[id] || id; }
</script>
