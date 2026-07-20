<template>
  <section>
    <div v-if="error" class="alert alert-danger mb-3" role="alert">{{ error }}</div>

    <div class="page-refresh-header">
      <div>
        <h2>{{ t('Notify') }}</h2>
        <div class="text-secondary small">{{ t('Last {hours}h of status, service, and IP conflict changes', { hours: notify.hours || 24 }) }} · {{ t('Network anomalies') }}</div>
      </div>
      <div class="d-flex flex-wrap gap-2">
        <select v-model.number="selectedHours" class="form-select form-select-sm w-auto" :aria-label="t('History range')" @change="load">
          <option :value="24">{{ t('Last 24 hours') }}</option>
          <option :value="168">{{ t('Last 7 days') }}</option>
          <option :value="720">{{ t('Last 30 days') }}</option>
        </select>
        <button class="btn btn-outline-primary btn-sm" type="button" @click="openNotificationSettings">
          <AppIcon name="edit" class="me-1" />{{ t('Notification delivery') }}
        </button>
        <button class="btn btn-outline-secondary btn-sm" type="button" :disabled="loading" @click="load">
          <AppIcon name="refresh" class="me-1" :class="{ 'is-spinning': loading }" />{{ t('Refresh') }}
        </button>
      </div>
    </div>

    <div class="notification-filter-toolbar">
      <fieldset v-for="group in filterGroups" :key="group.key" class="filter-segment">
        <legend class="visually-hidden">{{ group.label }}</legend>
        <div class="btn-group" role="group" :aria-label="group.label">
          <template v-for="option in group.options" :key="option.value">
            <input :id="`notification-filter-${group.key}-${option.value}`" v-model="filters[group.key]" class="btn-check" type="radio" :name="`notification-filter-${group.key}`" :value="option.value" autocomplete="off" />
            <label class="btn btn-outline-secondary btn-sm" :for="`notification-filter-${group.key}-${option.value}`">{{ option.label }}</label>
          </template>
        </div>
      </fieldset>
      <label class="notification-page-size text-secondary small">
        <span>{{ t('Rows per page') }}</span>
        <select v-model.number="pageSize" class="form-select form-select-sm" :aria-label="t('Rows per page')">
          <option v-for="size in pageSizes" :key="size" :value="size">{{ size }}</option>
        </select>
      </label>
      <button v-if="hasActiveFilters" class="btn btn-outline-secondary btn-sm icon-btn" type="button" :title="t('Clear filters')" :aria-label="t('Clear filters')" @click="resetFilters"><AppIcon name="filter-x" /></button>
    </div>

    <div class="notify-summary">
      <div class="notify-summary-item"><span>{{ t('Total') }}</span><strong>{{ filteredSummary.total }}</strong></div>
      <div class="notify-summary-item"><span>{{ t('Hosts') }}</span><strong>{{ filteredSummary.hosts }}</strong></div>
      <div class="notify-summary-item"><span>{{ t('Anomalies') }}</span><strong>{{ filteredSummary.anomaly_total }}</strong></div>
      <div class="notify-summary-item"><span>{{ t('Services') }}</span><strong>{{ filteredSummary.port_total }}</strong></div>
      <div class="notify-summary-item"><span>{{ t('IP conflicts') }}</span><strong>{{ filteredSummary.conflict_total }}</strong></div>
      <div v-for="item in statusCounts" :key="item.status" class="notify-summary-item">
        <span>{{ statusLabel(item.status) }}</span><strong>{{ item.count }}</strong>
      </div>
    </div>

    <template v-if="typeVisible('anomalies')">
    <div class="notification-section-heading"><h3>{{ t('Network anomalies') }}</h3><span class="text-secondary small">{{ t('{count} anomaly events', { count: anomalyChanges.length }) }}</span></div>
    <div class="table-wrap">
      <table class="table table-sm notify-table anomaly-table">
        <thead><tr><th>{{ t('Time') }}</th><th>{{ t('Type') }}</th><th>{{ t('Identity') }}</th><th>{{ t('Event') }}</th><th>{{ t('Evidence') }}</th></tr></thead>
        <tbody>
          <tr v-if="loading && anomalyChanges.length === 0"><td class="text-secondary text-center py-4" colspan="5">{{ t('Loading') }}</td></tr>
          <tr v-else-if="!loading && anomalyChanges.length === 0"><td class="text-secondary text-center py-4" colspan="5">{{ hasActiveFilters ? t('No matching notifications') : t('No network anomalies in the selected period') }}</td></tr>
          <tr v-for="change in anomalyPage.items" :key="change.event_id" :class="{ 'table-success': change.event === 'resolved' }">
            <td class="notify-time"><span>{{ formatNotifyDate(change.occurred_at) }}</span><small>{{ formatRelativeAge(change.occurred, now) }}</small></td>
            <td><span class="badge bg-yellow-lt text-yellow">{{ anomalyTypeLabel(change) }}</span><small v-if="change.subtype" class="d-block text-secondary">{{ anomalySubtypeLabel(change.subtype) }}</small></td>
            <td class="notify-host">
              <button v-if="change.ip" class="btn btn-link btn-sm p-0 notify-host-name" type="button" @click="openAnomaly(change)">{{ change.name || change.ip }}</button>
              <strong v-else>{{ change.hostname || change.mac || change.network || t('Unknown') }}</strong>
              <span v-if="change.ip" class="font-monospace">{{ change.ip }}</span>
              <span v-if="change.mac" class="font-monospace text-secondary">{{ formatMac(change.mac) }}</span>
            </td>
            <td><span class="badge" :class="change.event === 'resolved' ? 'bg-green-lt text-green' : 'bg-orange-lt text-orange'">{{ t(change.event === 'resolved' ? 'Resolved' : 'Detected') }}</span></td>
            <td class="small">{{ anomalyEvidence(change) }}</td>
          </tr>
        </tbody>
      </table>
    </div>
    <AppPagination :pagination="anomalyPage" @update:page="pages.anomalies = $event" />
    </template>

    <template v-if="typeVisible('conflicts')">
    <div class="notification-section-heading"><h3>{{ t('IP conflicts') }}</h3><span class="text-secondary small">{{ t('{count} conflict events', { count: conflictChanges.length }) }}</span></div>
    <div class="table-wrap">
      <table class="table table-sm notify-table conflict-table">
        <thead><tr><th>{{ t('Time') }}</th><th>IP</th><th>{{ t('Change') }}</th><th>{{ t('Devices') }}</th></tr></thead>
        <tbody>
          <tr v-if="loading && conflictChanges.length === 0"><td class="text-secondary text-center py-4" colspan="4">{{ t('Loading') }}</td></tr>
          <tr v-else-if="!loading && conflictChanges.length === 0"><td class="text-secondary text-center py-4" colspan="4">{{ hasActiveFilters ? t('No matching notifications') : t('No IP conflict events in the last {hours}h', { hours: notify.hours || 24 }) }}</td></tr>
          <tr v-for="change in conflictPage.items" :key="change.event_id" :class="change.type === 'detected' ? 'table-danger' : ''">
            <td class="notify-time"><span>{{ formatNotifyDate(change.occurred_at) }}</span><small>{{ formatRelativeAge(change.occurred, now) }}</small></td>
            <td><strong class="font-monospace">{{ change.ip }}</strong><small class="d-block text-secondary">{{ change.network }}</small></td>
            <td><span class="badge" :class="change.type === 'resolved' ? 'bg-green-lt text-green' : 'bg-red-lt text-red'">{{ t(change.type === 'resolved' ? 'Resolved' : 'Detected') }}</span></td>
            <td>
              <div v-for="device in change.devices" :key="device.mac" class="mb-1"><strong>{{ conflictDeviceName(device) }}</strong><small class="d-block font-monospace text-secondary">{{ formatMac(device.mac) }}</small></div>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
    <AppPagination :pagination="conflictPage" @update:page="pages.conflicts = $event" />
    </template>

    <template v-if="typeVisible('status')">
    <div class="notification-section-heading"><h3>{{ t('Host status') }}</h3><span class="text-secondary small">{{ t('{count} changes', { count: changes.length }) }}</span></div>
    <div class="table-wrap">
      <table class="table table-sm notify-table">
        <thead><tr><th>{{ t('Time') }}</th><th>{{ t('Host') }}</th><th>{{ t('Change') }}</th><th>{{ t('Duration') }}</th></tr></thead>
        <tbody>
          <tr v-if="loading && changes.length === 0"><td class="text-secondary text-center py-4" colspan="4">{{ t('Loading') }}</td></tr>
          <tr v-else-if="!loading && changes.length === 0"><td class="text-secondary text-center py-4" colspan="4">{{ hasActiveFilters ? t('No matching notifications') : t('No changes in the last {hours}h', { hours: notify.hours || 24 }) }}</td></tr>
          <tr v-for="change in statusPage.items" :key="change.id" :class="{ 'important-down': change.important == 1 && change.status !== 'Up' }">
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
                <span v-if="change.previous_status" :class="statusClass(change.previous_status)" :title="statusTitle(change.previous_status)"><AppIcon :name="statusIcon(change.previous_status)" /></span>
                <span v-else class="status-pill status-unknown" :title="t('New')"><AppIcon name="point" /></span>
                <AppIcon name="arrow-right" class="text-secondary" />
                <span :class="statusClass(change.status)" :title="statusTitle(change.status)"><AppIcon :name="statusIcon(change.status)" /></span>
                <strong>{{ statusLabel(change.status) }}</strong>
              </div>
            </td>
            <td class="notify-duration">
              {{ formatDuration(displayDuration(change)) }}
              <span v-if="change.current == 1" class="badge bg-blue-lt text-blue ms-1">{{ t('current') }}</span>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
    <AppPagination :pagination="statusPage" @update:page="pages.status = $event" />
    </template>

    <template v-if="typeVisible('services')">
    <div class="notification-section-heading"><h3>{{ t('Services') }}</h3><span class="text-secondary small">{{ t('{count} changes', { count: portChanges.length }) }}</span></div>
    <div class="table-wrap">
      <table class="table table-sm notify-table service-change-table">
        <thead><tr><th>{{ t('Time') }}</th><th>{{ t('Host') }}</th><th>{{ t('Port') }}</th><th>{{ t('Change') }}</th><th>{{ t('Service') }}</th><th>{{ t('Scan') }}</th></tr></thead>
        <tbody>
          <tr v-if="loading && portChanges.length === 0"><td class="text-secondary text-center py-4" colspan="6">{{ t('Loading') }}</td></tr>
          <tr v-else-if="!loading && portChanges.length === 0"><td class="text-secondary text-center py-4" colspan="6">{{ hasActiveFilters ? t('No matching notifications') : t('No service changes in the last {hours}h', { hours: notify.hours || 24 }) }}</td></tr>
          <tr v-for="change in servicePage.items" :key="`port-${change.id}`" :class="{ 'important-down': change.important == 1 && change.change_type === 'disappeared' }">
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
                <AppIcon name="arrow-right" />
                <strong>{{ serviceLabel(change, 'current') }}</strong>
              </template>
              <strong v-else>{{ serviceLabel(change, change.change_type === 'appeared' ? 'current' : 'previous') }}</strong>
            </td>
            <td><span class="badge" :class="scanProfileBadgeClass(change.mode)">{{ scanProfileLabel(change.mode) }}</span></td>
          </tr>
        </tbody>
      </table>
    </div>
    <AppPagination :pagination="servicePage" @update:page="pages.services = $event" />
    </template>
    <NotificationDeliveryModal
      v-if="settingsOpen"
      v-model:rules="draftRules"
      v-model:reports="draftReports"
      v-model:telegram-chat-id="draftTelegramChatId"
      :delivery="delivery"
      :is-authenticated="isAuthenticated"
      :dirty="settingsDirty"
      :saving="saving"
      :settings-error="settingsError"
      :settings-success="settingsSuccess"
      :telegram-chats="telegramChats"
      :telegram-chats-error="telegramChatsError"
      :telegram-chats-loading="telegramChatsLoading"
      @close="closeNotificationSettings"
      @refresh-telegram-chats="loadTelegramChats"
      @save="saveRules"
    />
  </section>
</template>

<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue';
import AppPagination from '../components/AppPagination.vue';
import NotificationDeliveryModal from '../components/NotificationDeliveryModal.vue';
import { apiJson, isAbortError } from '../lib/api.js';
import { useAbortableTask } from '../composables/useAbortableTask.js';
import { useLiveRefresh } from '../composables/useLiveUpdates.js';
import { useNow } from '../composables/useNow.js';
import { usePageController } from '../composables/usePageController.js';
import {
  formatDuration,
  formatMac,
  formatNotifyDate,
  formatRelativeAge,
  statusClass,
  statusIcon,
  statusLabel,
  statusTitle
} from '../lib/formatters.js';
import { t } from '../lib/i18n.js';
import {
  filterNotificationCollections,
  notificationFilterDefaults,
  paginateItems
} from '../lib/notificationFilters.js';
import { scanProfileBadgeClass, scanProfileLabel } from '../lib/scanProfiles.js';

defineOptions({ inheritAttrs: false });
const props = defineProps({ isAuthenticated: Boolean });
const emit = defineEmits(['network', 'notice', 'open-history', 'open-scan']);
const defaultRules = Object.freeze({
  restart: true,
  host_status: { normal: true, important: true },
  service_changes: { normal: true, important: true },
  ip_conflicts: true,
  network_anomalies: Object.fromEntries(['open_ports', 'unexpected_vendors', 'ip_changes', 'duplicate_identities', 'churn'].map((key) => [key, { normal: true, important: true }]))
});
const defaultReports = Object.freeze({
  daily_enabled: false,
  weekly_enabled: false,
  hour_utc: 8,
  weekly_day: 1,
  certificate_warning_days: 30
});
const notify = ref({ hours: 24, summary: {}, changes: [], port_changes: [], conflict_changes: [], anomaly_changes: [] });
const selectedHours = ref(24);
const delivery = ref({
  rules: cloneRules(defaultRules),
  discord: { configured: false, mention_target: null },
  telegram: { configured: false, chat_selected: false },
  reports: { settings: cloneReports(defaultReports), last_runs: {} }
});
const savedRules = ref(cloneRules(defaultRules));
const draftRules = ref(cloneRules(defaultRules));
const savedReports = ref(cloneReports(defaultReports));
const draftReports = ref(cloneReports(defaultReports));
const telegramChats = ref([]);
const savedTelegramChatId = ref('');
const draftTelegramChatId = ref('');
const telegramChatsLoading = ref(false);
const telegramChatsLoaded = ref(false);
const telegramChatsError = ref('');
const loading = ref(false);
const saving = ref(false);
const error = ref('');
const settingsError = ref('');
const settingsSuccess = ref('');
const settingsOpen = ref(false);
const now = useNow();
const request = useAbortableTask();
const filters = ref({ ...notificationFilterDefaults });
const pageSizes = Object.freeze([10, 25, 50]);
const pageSize = ref(10);
const pages = reactive({ anomalies: 1, conflicts: 1, status: 1, services: 1 });
const filterGroups = computed(() => [
  { key: 'importance', label: t('Importance filter'), options: [{ value: 'normal', label: t('Normal') }, { value: 'all', label: t('All') }, { value: 'important', label: t('Important') }] },
  { key: 'type', label: t('Notification type filter'), options: [{ value: 'all', label: t('All') }, { value: 'anomalies', label: t('Anomalies') }, { value: 'conflicts', label: t('IP conflicts') }, { value: 'status', label: t('Host status') }, { value: 'services', label: t('Services') }] }
]);
const hasActiveFilters = computed(() => filters.value.importance !== 'all' || filters.value.type !== 'all');
const filtered = computed(() => filterNotificationCollections({
  anomalies: notify.value.anomaly_changes,
  conflicts: notify.value.conflict_changes,
  status: notify.value.changes,
  services: notify.value.port_changes
}, filters.value));
const changes = computed(() => filtered.value.status);
const anomalyChanges = computed(() => filtered.value.anomalies);
const portChanges = computed(() => filtered.value.services);
const conflictChanges = computed(() => filtered.value.conflicts);
const conflictPage = computed(() => paginateItems(conflictChanges.value, pages.conflicts, pageSize.value));
const anomalyPage = computed(() => paginateItems(anomalyChanges.value, pages.anomalies, pageSize.value));
const statusPage = computed(() => paginateItems(changes.value, pages.status, pageSize.value));
const servicePage = computed(() => paginateItems(portChanges.value, pages.services, pageSize.value));
const filteredSummary = computed(() => {
  const allRows = [...anomalyChanges.value, ...conflictChanges.value, ...changes.value, ...portChanges.value];
  const statusCounts = {};
  for (const change of changes.value) {
    const status = String(change?.status || '');
    statusCounts[status] = (statusCounts[status] || 0) + 1;
  }
  return {
    total: allRows.length,
    hosts: new Set(allRows.map((row) => row?.ip).filter(Boolean)).size,
    status_total: changes.value.length,
    port_total: portChanges.value.length,
    conflict_total: conflictChanges.value.length,
    anomaly_total: anomalyChanges.value.length,
    status_counts: statusCounts
  };
});
const statusCounts = computed(() => Object.entries(filteredSummary.value.status_counts)
  .sort(([a], [b]) => String(a).localeCompare(String(b)))
  .map(([status, count]) => ({ status, count })));
const rulesDirty = computed(() => JSON.stringify(draftRules.value) !== JSON.stringify(savedRules.value));
const reportsDirty = computed(() => JSON.stringify(draftReports.value) !== JSON.stringify(savedReports.value));
const telegramChatDirty = computed(() => telegramChatsLoaded.value
  && draftTelegramChatId.value !== savedTelegramChatId.value);
const settingsDirty = computed(() => rulesDirty.value || reportsDirty.value || telegramChatDirty.value);
usePageController({
  loading,
  label: computed(() => loading.value ? t('Loading') : t('Notify')),
  title: computed(() => t('Refresh notifications')),
  disabled: false,
  refresh: load
});
useLiveRefresh(['hosts', 'status', 'scans', 'conflicts', 'vendors', 'anomalies'], load);
watch(
  () => [filters.value.importance, filters.value.type, pageSize.value],
  () => resetPages()
);

onMounted(load);

function resetPages() {
  pages.anomalies = 1;
  pages.conflicts = 1;
  pages.status = 1;
  pages.services = 1;
}

function resetFilters() {
  filters.value = { ...notificationFilterDefaults };
}

function typeVisible(type) {
  return filters.value.type === 'all' || filters.value.type === type;
}

function openNotificationSettings() {
  settingsError.value = '';
  settingsSuccess.value = '';
  settingsOpen.value = true;
  if (props.isAuthenticated && delivery.value.telegram.configured)
    loadTelegramChats();
}

function closeNotificationSettings() {
  if (!saving.value) settingsOpen.value = false;
}

async function load() {
  const signal = request.nextSignal();
  loading.value = true;
  error.value = '';
  try {
    const data = await apiJson(`/api/notify?hours=${selectedHours.value}`, { signal });
    if (!request.isCurrent(signal)) return;
    emit('network', data.network || '');
    notify.value = {
      hours: data.hours || 24,
      summary: data.summary || {},
      changes: data.changes || [],
      port_changes: data.port_changes || [],
      conflict_changes: data.conflict_changes || [],
      anomaly_changes: data.anomaly_changes || []
    };
    const rulesWereDirty = rulesDirty.value;
    const reportsWereDirty = reportsDirty.value;
    const incoming = normalizeDelivery(data.delivery);
    delivery.value = incoming;
    savedRules.value = cloneRules(incoming.rules);
    if (!rulesWereDirty) draftRules.value = cloneRules(incoming.rules);
    savedReports.value = cloneReports(incoming.reports.settings);
    if (!reportsWereDirty) draftReports.value = cloneReports(incoming.reports.settings);
  } catch (loadError) {
    if (!isAbortError(loadError) && request.isCurrent(signal)) error.value = loadError.message;
  } finally {
    if (request.isCurrent(signal)) loading.value = false;
  }
}

async function saveRules() {
  if (!props.isAuthenticated || saving.value || !settingsDirty.value) return;
  saving.value = true;
  settingsError.value = '';
  settingsSuccess.value = '';
  try {
    const payload = { rules: cloneRules(draftRules.value), reports: cloneReports(draftReports.value) };
    if (telegramChatsLoaded.value)
      payload.telegram_chat_id = draftTelegramChatId.value || null;
    const updated = await apiJson('/api/notify/delivery', {
      method: 'PUT',
      body: JSON.stringify(payload)
    });
    const incoming = normalizeDelivery(updated);
    delivery.value = incoming;
    savedRules.value = cloneRules(incoming.rules);
    draftRules.value = cloneRules(incoming.rules);
    savedReports.value = cloneReports(incoming.reports.settings);
    draftReports.value = cloneReports(incoming.reports.settings);
    if (telegramChatsLoaded.value)
      savedTelegramChatId.value = draftTelegramChatId.value;
    emit('notice', t('Notification rules saved'));
    settingsSuccess.value = t('Notification rules saved');
  } catch (saveError) {
    settingsError.value = saveError.message;
  } finally {
    saving.value = false;
  }
}

async function loadTelegramChats() {
  if (!props.isAuthenticated || !delivery.value.telegram.configured || telegramChatsLoading.value)
    return;
  const wasDirty = telegramChatDirty.value;
  telegramChatsLoading.value = true;
  telegramChatsError.value = '';
  try {
    const data = await apiJson('/api/notify/telegram/chats');
    telegramChats.value = Array.isArray(data.chats) ? data.chats : [];
    const selected = typeof data.selected_chat_id === 'string' ? data.selected_chat_id : '';
    const preserveDraft = wasDirty || telegramChatDirty.value;
    savedTelegramChatId.value = selected;
    if (!preserveDraft)
      draftTelegramChatId.value = selected;
    telegramChatsLoaded.value = true;
    delivery.value.telegram = {
      configured: Boolean(data.configured),
      chat_selected: Boolean(selected)
    };
  } catch (chatError) {
    telegramChatsError.value = chatError.message;
  } finally {
    telegramChatsLoading.value = false;
  }
}

function normalizeDelivery(value) {
  return {
    rules: cloneRules(value?.rules || defaultRules),
    discord: {
      configured: Boolean(value?.discord?.configured),
      mention_target: ['everyone', 'user'].includes(value?.discord?.mention_target) ? value.discord.mention_target : null
    },
    telegram: {
      configured: Boolean(value?.telegram?.configured),
      chat_selected: Boolean(value?.telegram?.chat_selected)
    },
    reports: {
      settings: cloneReports(value?.reports?.settings || defaultReports),
      last_runs: value?.reports?.last_runs && typeof value.reports.last_runs === 'object' ? value.reports.last_runs : {}
    }
  };
}

function cloneRules(value) {
  return {
    restart: Boolean(value?.restart),
    host_status: {
      normal: Boolean(value?.host_status?.normal),
      important: Boolean(value?.host_status?.important)
    },
    service_changes: {
      normal: Boolean(value?.service_changes?.normal),
      important: Boolean(value?.service_changes?.important)
    },
    ip_conflicts: Boolean(value?.ip_conflicts),
    network_anomalies: Object.fromEntries(['open_ports', 'unexpected_vendors', 'ip_changes', 'duplicate_identities', 'churn'].map((key) => [key, {
      normal: Boolean(value?.network_anomalies?.[key]?.normal ?? true),
      important: Boolean(value?.network_anomalies?.[key]?.important ?? true)
    }]))
  };
}

function cloneReports(value) {
  return {
    daily_enabled: Boolean(value?.daily_enabled),
    weekly_enabled: Boolean(value?.weekly_enabled),
    hour_utc: Number.isInteger(value?.hour_utc) ? value.hour_utc : 8,
    weekly_day: Number.isInteger(value?.weekly_day) ? value.weekly_day : 1,
    certificate_warning_days: Number.isInteger(value?.certificate_warning_days) ? value.certificate_warning_days : 30
  };
}

function displayDuration(change) {
  if (change.current == 1 && Number(change.begin || 0) > 0)
    return Math.max(0, Math.floor(now.value / 1000) - Number(change.begin));
  return change.duration;
}

function hostName(change) {
  return change?.name || change?.ip || formatMac(change?.mac) || t('Unknown');
}

function portChangeLabel(type) {
  return t(({ appeared: 'Appeared', disappeared: 'Disappeared', changed: 'Version changed' })[type] || 'Changed');
}

function portChangeClass(type) {
  return ({ appeared: 'bg-green-lt text-green', disappeared: 'bg-red-lt text-red', changed: 'bg-yellow-lt text-yellow' })[type] || 'bg-secondary-lt text-secondary';
}

function serviceLabel(change, prefix) {
  return [change?.[`${prefix}_service`], change?.[`${prefix}_version`]].filter(Boolean).join(' ') || '-';
}

function conflictDeviceName(device) {
  return device?.name || device?.vendor || formatMac(device?.mac) || t('Unknown device');
}

function anomalyTypeLabel(change) {
  return t(({ open_port: 'New open port', unexpected_vendor: 'Unexpected vendor', ip_change: 'IP change', duplicate_identity: 'Duplicate identity', churn: 'Unusual churn' })[change?.type] || 'Network anomaly');
}
function anomalySubtypeLabel(value) { return t(String(value || '').replaceAll('_', ' ')); }
function anomalyEvidence(change) {
  const details = change?.details || {};
  if (change?.type === 'open_port') return `${details.port || '-'}${details.protocol ? `/${details.protocol}` : ''} ${[details.service, details.version].filter(Boolean).join(' ')}`.trim();
  if (change?.type === 'unexpected_vendor') return change.vendor || details.vendor || '-';
  if (change?.type === 'ip_change') return `${change.previous_ip || '-'} → ${change.ip || '-'}`;
  if (change?.subtype === 'duplicate_mac') return (details.ips || []).join(', ');
  if (change?.subtype === 'duplicate_hostname') return (details.macs || []).map(formatMac).join(', ');
  if (change?.type === 'churn') return t('{count} transitions in {hours}h', { count: details.transition_count || 0, hours: details.window_hours || 1 });
  return '-';
}
function openAnomaly(change) {
  if (change?.source === 'scan_port_change' && change?.details?.scan_id) emit('open-scan', change.ip, change.details.scan_id);
  else if (change?.ip) emit('open-history', change.ip);
}

</script>
