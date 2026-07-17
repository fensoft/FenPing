<template>
  <section>
    <div v-if="error" class="alert alert-danger mb-3" role="alert">{{ error }}</div>
    <div class="netboot-header">
      <div><h2>{{ t('Backups') }}</h2><div class="text-secondary small">{{ t('Verified appliance database and netboot archives') }}</div></div>
      <div class="btn-list">
        <button class="btn btn-primary btn-sm" type="button" :disabled="busy || !isAuthenticated" @click="createBackup"><AppIcon :name="creating ? 'loader-2' : 'archive'" class="me-1" :class="{ 'is-spinning': creating }" />{{ t(creating ? 'Creating backup' : 'Create backup') }}</button>
        <button class="btn btn-outline-secondary btn-sm" type="button" :disabled="busy || !isAuthenticated" @click="load"><AppIcon name="refresh" class="me-1" :class="{ 'is-spinning': loading }" />{{ t('Refresh') }}</button>
      </div>
    </div>
    <div v-if="!isAuthenticated" class="alert alert-info" role="alert">{{ t('Login to view and download backups.') }}<button class="btn btn-primary btn-sm ms-2" type="button" @click="$emit('login')">{{ t('Login') }}</button></div>
    <template v-else>
      <div v-if="storage.same_filesystem" class="alert alert-warning" role="alert"><AppIcon name="alert-triangle" class="me-1" />{{ t('Backups are on the same disk as the appliance. Keep a copy on another device.') }}</div>
      <div class="table-wrap">
        <table class="table table-sm backup-table">
          <thead><tr><th>{{ t('Backup') }}</th><th>{{ t('Type') }}</th><th>{{ t('Status') }}</th><th>{{ t('Size') }}</th><th>{{ t('Created') }}</th><th>{{ t('Last restore test') }}</th><th>{{ t('Checksum') }}</th><th class="text-end">{{ t('Actions') }}</th></tr></thead>
          <tbody>
            <tr v-if="loading && backups.length === 0"><td class="text-secondary text-center py-4" colspan="8">{{ t('Loading') }}</td></tr>
            <tr v-else-if="!loading && backups.length === 0"><td class="text-secondary text-center py-4" colspan="8">{{ t('No backups') }}</td></tr>
            <tr v-for="backup in backups" :key="backup.filename">
              <td class="text-truncate-cell" :title="backup.filename"><strong>{{ backup.filename }}</strong><small v-if="backup.retention_roles?.length" class="text-secondary">{{ backup.retention_roles.map(roleLabel).join(', ') }}</small></td>
              <td class="text-nowrap">{{ kindLabel(backup.kind) }}</td>
              <td><span class="badge" :class="statusClass(backup.verification?.status)" :title="backup.verification?.message || ''">{{ statusLabel(backup.verification?.status) }}</span></td>
              <td class="text-nowrap">{{ formatBytes(backup.size) }}</td>
              <td class="text-nowrap">{{ formatServerDate(backup.created_at) }}</td>
              <td class="text-nowrap">{{ formatServerDate(backup.verification?.restore_tested_at) }}</td>
              <td class="backup-checksum font-monospace" :title="backup.sha256 || ''">{{ backup.sha256 ? backup.sha256.slice(0, 12) : '-' }}</td>
              <td class="text-end"><div class="btn-list justify-content-end flex-nowrap">
                <a class="btn btn-outline-primary btn-sm icon-btn" :href="backup.download_url" :title="t('Download backup')" :aria-label="t('Download backup')"><AppIcon name="download" /></a>
                <button class="btn btn-outline-danger btn-sm" type="button" :disabled="busy" :title="t('Restore backup')" @click="restoreBackup(backup)"><AppIcon :name="restoringFilename === backup.filename ? 'loader-2' : 'arrow-back-up'" class="me-1" :class="{ 'is-spinning': restoringFilename === backup.filename }" />{{ t(restoringFilename === backup.filename ? 'Restoring' : 'Restore') }}</button>
              </div></td>
            </tr>
          </tbody>
        </table>
      </div>
    </template>
  </section>
</template>

<script setup>
import { computed, ref, watch } from 'vue';
import { apiJson, isAbortError } from '../lib/api.js';
import { t } from '../lib/i18n.js';
import { formatBytes, formatServerDate } from '../lib/formatters.js';
import { useAbortableTask } from '../composables/useAbortableTask.js';
import { useLiveRefresh } from '../composables/useLiveUpdates.js';
import { usePageController } from '../composables/usePageController.js';

defineOptions({ inheritAttrs: false });
const props = defineProps({ isAuthenticated: Boolean });
const emit = defineEmits(['login', 'notice']);
const backups = ref([]);
const storage = ref({});
const loading = ref(false);
const creating = ref(false);
const restoringFilename = ref('');
const error = ref('');
const request = useAbortableTask();
const mutationRequest = useAbortableTask();
const busy = computed(() => loading.value || creating.value || restoringFilename.value !== '');

usePageController({ loading, label: computed(() => t(loading.value ? 'Loading' : 'Backups')), title: computed(() => t('Refresh backups')), disabled: computed(() => !props.isAuthenticated), refresh: load });
useLiveRefresh(['backups'], load);
watch(() => props.isAuthenticated, (authenticated) => { if (authenticated) load(); else backups.value = []; }, { immediate: true });

async function load() {
  if (!props.isAuthenticated) return;
  const signal = request.nextSignal();
  loading.value = true;
  error.value = '';
  try {
    const data = await apiJson('/api/backups', { signal });
    if (request.isCurrent(signal)) {
      backups.value = data?.backups || [];
      storage.value = data?.storage || {};
    }
  } catch (loadError) {
    if (!isAbortError(loadError) && request.isCurrent(signal)) error.value = loadError.message;
  } finally {
    if (request.isCurrent(signal)) loading.value = false;
  }
}

async function createBackup() {
  if (!props.isAuthenticated) return emit('login');
  const signal = mutationRequest.nextSignal();
  creating.value = true;
  error.value = '';
  try {
    await apiJson('/api/backups', { method: 'POST', signal });
    if (!mutationRequest.isCurrent(signal)) return;
    emit('notice', t('Backup created'));
    await load();
  } catch (createError) {
    if (!isAbortError(createError) && mutationRequest.isCurrent(signal)) error.value = createError.message;
  } finally {
    if (mutationRequest.isCurrent(signal)) creating.value = false;
  }
}

async function restoreBackup(backup) {
  if (!props.isAuthenticated) return emit('login');
  if (!backup?.filename || !window.confirm(t('Restore {name}? Current appliance data will be replaced. A safety backup will be created first.', { name: backup.filename }))) return;
  const signal = mutationRequest.nextSignal();
  restoringFilename.value = backup.filename;
  error.value = '';
  try {
    await apiJson(`/api/backups/${encodeURIComponent(backup.filename)}/restore`, { method: 'POST', signal });
    if (!mutationRequest.isCurrent(signal)) return;
    emit('notice', t('Backup {name} restored', { name: backup.filename }));
    await load();
  } catch (restoreError) {
    if (!isAbortError(restoreError) && mutationRequest.isCurrent(signal)) error.value = restoreError.message;
  } finally {
    if (mutationRequest.isCurrent(signal)) restoringFilename.value = '';
  }
}

function statusLabel(status) { return t(status === 'verified' ? 'Verified' : status === 'failed' ? 'Failed' : 'Unverified'); }
function statusClass(status) { return status === 'verified' ? 'bg-green-lt text-green' : status === 'failed' ? 'bg-red-lt text-red' : 'bg-secondary-lt text-secondary'; }
function kindLabel(kind) {
  const labels = { daily: 'Daily', 'pre-upgrade': 'Pre-upgrade', 'rollback-rescue': 'Rollback rescue', manual: 'Manual', demo: 'Demo', imported: 'Imported' };
  return t(labels[kind] || kind || 'Imported');
}
function roleLabel(role) { return t(role === 'checkpoint' ? 'Rollback checkpoint' : role === 'weekly' ? 'Weekly retention' : 'Daily retention'); }
</script>
