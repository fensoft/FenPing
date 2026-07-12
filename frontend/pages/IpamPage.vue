<template>
  <section>
    <div v-if="error" class="alert alert-danger mb-3" role="alert">{{ error }}</div>
    <div class="page-refresh-header">
      <div><h2>IPAM</h2><div class="text-secondary small">{{ t('Device onboarding and DHCP pool utilization') }}</div></div>
      <button class="btn btn-outline-secondary btn-sm" type="button" :disabled="loading" @click="load"><AppIcon name="refresh" class="me-1" :class="{ 'is-spinning': loading }" />{{ t('Refresh') }}</button>
    </div>

    <div class="ipam-pool">
      <div class="notify-summary mb-2">
        <div class="notify-summary-item"><span>{{ t('Pool') }}</span><strong>{{ pool.start || '-' }} – {{ pool.end || '-' }}</strong></div>
        <div class="notify-summary-item"><span>{{ t('Occupied') }}</span><strong>{{ pool.occupied || 0 }}/{{ pool.total || 0 }}</strong></div>
        <div class="notify-summary-item"><span>{{ t('Available') }}</span><strong>{{ pool.available || 0 }}</strong></div>
        <div class="notify-summary-item"><span>{{ t('Leases') }}</span><strong>{{ pool.active_leases || 0 }}</strong></div>
        <div class="notify-summary-item"><span>{{ t('Reservations') }}</span><strong>{{ pool.fixed_reservations || 0 }}</strong></div>
      </div>
      <div class="ipam-utilization-heading"><span>{{ t('Utilization') }}</span><strong>{{ Number(pool.utilization_percent || 0).toFixed(1) }}%</strong></div>
      <div class="progress ipam-progress" role="progressbar" :aria-label="t('DHCP pool utilization')" aria-valuemin="0" aria-valuemax="100" :aria-valuenow="pool.utilization_percent || 0">
        <div class="progress-bar" :class="utilizationClass" :style="{ width: `${Math.min(100, Number(pool.utilization_percent || 0))}%` }"></div>
      </div>
    </div>

    <div v-if="!isAuthenticated" class="alert alert-info mt-3" role="alert">{{ t('Guest mode is read only. Log in to approve devices or create reservations.') }}</div>

    <div class="notification-section-heading"><h3>{{ t('Pending devices') }}</h3><span class="text-secondary small">{{ t('{count} seen within 7 days', { count: pending.length }) }}</span></div>
    <div class="table-wrap">
      <table class="table table-sm ipam-table">
        <thead><tr><th>{{ t('Device') }}</th><th>IP</th><th>{{ t('Status') }}</th><th>{{ t('Last seen') }}</th><th>{{ t('Lease expires') }}</th><th class="text-end">{{ t('Actions') }}</th></tr></thead>
        <tbody>
          <tr v-if="loading && pending.length === 0"><td class="text-secondary text-center py-4" colspan="6">{{ t('Loading') }}</td></tr>
          <tr v-else-if="!loading && pending.length === 0"><td class="text-secondary text-center py-4" colspan="6">{{ t('No new devices') }}</td></tr>
          <tr v-for="device in pending" :key="device.mac" class="ipam-device-new">
            <td><div class="ipam-device-name"><AppIcon name="alert-triangle" class="text-warning" /><strong>{{ deviceName(device) }}</strong></div><small class="font-monospace">{{ formatMac(device.mac) }}</small><small v-if="device.vendor" :title="device.vendor">{{ device.vendor }}</small></td>
            <td class="font-monospace">{{ device.ip || '-' }}</td>
            <td><span :class="statusClass(device.status)" :title="statusTitle(device.status)" class="status-pill"><AppIcon :name="statusIcon(device.status)" /></span>{{ statusLabel(device.status) }}</td>
            <td class="text-nowrap">{{ formatServerDate(device.last_seen) }}</td>
            <td class="text-nowrap"><span>{{ formatServerDate(device.lease_expires) }}</span><small v-if="device.lease_active" class="text-green">{{ t('active') }}</small></td>
            <td class="text-end action-cell">
              <button v-if="isAuthenticated" class="btn btn-outline-success btn-sm" type="button" :disabled="savingMac !== ''" @click="approve(device)"><AppIcon name="check" class="me-1" />{{ t('Approve') }}</button>
              <button v-if="isAuthenticated" class="btn btn-outline-primary btn-sm" type="button" :disabled="savingMac !== ''" @click="$emit('reserve-device', device)"><AppIcon name="pin" class="me-1" />{{ t('Reserve') }}</button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <div class="notification-section-heading"><h3>{{ t('Approved dynamic devices') }}</h3><span class="text-secondary small">{{ t('{count} acknowledged', { count: approved.length }) }}</span></div>
    <div class="table-wrap">
      <table class="table table-sm ipam-table">
        <thead><tr><th>{{ t('Device') }}</th><th>IP</th><th>{{ t('Status') }}</th><th>{{ t('Last seen') }}</th><th>{{ t('Approved') }}</th><th class="text-end">{{ t('Actions') }}</th></tr></thead>
        <tbody>
          <tr v-if="loading && approved.length === 0"><td class="text-secondary text-center py-4" colspan="6">{{ t('Loading') }}</td></tr>
          <tr v-else-if="!loading && approved.length === 0"><td class="text-secondary text-center py-4" colspan="6">{{ t('No approved dynamic devices') }}</td></tr>
          <tr v-for="device in approved" :key="device.mac">
            <td><strong>{{ deviceName(device) }}</strong><small class="font-monospace">{{ formatMac(device.mac) }}</small><small v-if="device.vendor" :title="device.vendor">{{ device.vendor }}</small></td>
            <td class="font-monospace">{{ device.ip || '-' }}</td>
            <td><span :class="statusClass(device.status)" :title="statusTitle(device.status)" class="status-pill"><AppIcon :name="statusIcon(device.status)" /></span>{{ statusLabel(device.status) }}</td>
            <td class="text-nowrap">{{ formatServerDate(device.last_seen) }}</td>
            <td class="text-nowrap">{{ formatServerDate(device.approved_at) }}</td>
            <td class="text-end action-cell">
              <button v-if="isAuthenticated" class="btn btn-outline-warning btn-sm" type="button" :disabled="savingMac !== ''" @click="unapprove(device)"><AppIcon name="arrow-back-up" class="me-1" />{{ t('Mark new') }}</button>
              <button v-if="isAuthenticated" class="btn btn-outline-primary btn-sm" type="button" :disabled="savingMac !== ''" @click="$emit('reserve-device', device)"><AppIcon name="pin" class="me-1" />{{ t('Reserve') }}</button>
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
import { t } from '../lib/i18n.js';
import { useAbortableTask } from '../composables/useAbortableTask.js';
import { usePageController } from '../composables/usePageController.js';
import { formatMac, formatServerDate, statusClass, statusIcon, statusLabel, statusTitle } from '../lib/formatters.js';

defineOptions({ inheritAttrs: false });
const props = defineProps({ isAuthenticated: Boolean });
const emit = defineEmits(['network', 'notice', 'reserve-device']);
const pool = ref({});
const pending = ref([]);
const approved = ref([]);
const loading = ref(false);
const error = ref('');
const savingMac = ref('');
const loadRequest = useAbortableTask();
const mutationRequest = useAbortableTask();
const utilizationClass = computed(() => Number(pool.value.utilization_percent || 0) >= 90 ? 'bg-red' : Number(pool.value.utilization_percent || 0) >= 75 ? 'bg-yellow' : 'bg-blue');

usePageController({ loading, label: computed(() => loading.value ? t('Loading') : 'IPAM'), title: computed(() => t('Refresh IPAM')), disabled: false, refresh: load, reload: load });
onMounted(load);

async function load() {
  const signal = loadRequest.nextSignal();
  loading.value = true;
  error.value = '';
  try {
    const data = await apiJson('/api/ipam', { signal });
    if (!loadRequest.isCurrent(signal)) return;
    emit('network', data.network || '');
    pool.value = data.pool || {};
    pending.value = data.pending || [];
    approved.value = data.approved || [];
  } catch (loadError) {
    if (!isAbortError(loadError) && loadRequest.isCurrent(signal)) error.value = loadError.message;
  } finally {
    if (loadRequest.isCurrent(signal)) loading.value = false;
  }
}

async function approve(device) {
  await changeApproval(device, true);
}

async function unapprove(device) {
  await changeApproval(device, false);
}

async function changeApproval(device, value) {
  if (!props.isAuthenticated) return;
  const signal = mutationRequest.nextSignal();
  savingMac.value = device.mac;
  error.value = '';
  try {
    await apiJson(`/api/ipam/devices/${encodeURIComponent(device.mac)}/approval`, { method: value ? 'PUT' : 'DELETE', signal });
    if (!mutationRequest.isCurrent(signal)) return;
    emit('notice', t(value ? 'Device approved' : 'Device marked as new'));
    await load();
  } catch (mutationError) {
    if (!isAbortError(mutationError) && mutationRequest.isCurrent(signal)) error.value = mutationError.message;
  } finally {
    if (mutationRequest.isCurrent(signal)) savingMac.value = '';
  }
}

function deviceName(device) {
  return device?.name || device?.ip || formatMac(device?.mac) || t('Unknown device');
}
</script>
