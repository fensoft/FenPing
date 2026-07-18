<template>
  <section>
    <div v-if="error" class="alert alert-danger mb-3" role="alert">{{ error }}</div>
    <div class="page-refresh-header">
      <div><h2>{{ t('Audit log') }}</h2><div class="text-secondary small">{{ t('Administrative changes and security events') }}</div></div>
      <button class="btn btn-outline-secondary btn-sm" type="button" :disabled="loading || !isAuthenticated" @click="load">
        <AppIcon name="refresh" class="me-1" :class="{ 'is-spinning': loading }" />{{ t('Refresh') }}
      </button>
    </div>

    <div v-if="!isAuthenticated" class="alert alert-info" role="alert">
      {{ t('Login to view the audit log.') }}
      <button class="btn btn-primary btn-sm ms-2" type="button" @click="$emit('login')">{{ t('Login') }}</button>
    </div>

    <template v-else>
      <form class="audit-filter-toolbar" role="search" @submit.prevent="applyFilters">
        <label class="form-label audit-search-label">
          <span class="visually-hidden">{{ t('Search audit log') }}</span>
          <input v-model.trim="search" class="form-control form-control-sm" type="search" :placeholder="t('Search audit log')" />
        </label>
        <label class="form-label">
          <span class="visually-hidden">{{ t('Resource') }}</span>
          <select v-model="resourceType" class="form-select form-select-sm" @change="applyFilters">
            <option value="">{{ t('All resources') }}</option>
            <option v-for="value in filters.resource_types" :key="value" :value="value">{{ resourceLabel(value) }}</option>
          </select>
        </label>
        <label class="form-label">
          <span class="visually-hidden">{{ t('Action') }}</span>
          <select v-model="action" class="form-select form-select-sm" @change="applyFilters">
            <option value="">{{ t('All actions') }}</option>
            <option v-for="value in filters.actions" :key="value" :value="value">{{ actionLabel(value) }}</option>
          </select>
        </label>
        <button class="btn btn-primary btn-sm" type="submit">{{ t('Search') }}</button>
      </form>

      <div class="table-wrap">
        <table class="table table-sm audit-table">
          <thead><tr><th>{{ t('Time') }}</th><th>{{ t('Action') }}</th><th>{{ t('Summary') }}</th><th>{{ t('Resource') }}</th><th>{{ t('Actor') }}</th><th>{{ t('Source') }}</th><th>{{ t('Details') }}</th></tr></thead>
          <tbody>
            <tr v-if="loading && events.length === 0"><td class="text-secondary text-center py-4" colspan="7">{{ t('Loading') }}</td></tr>
            <tr v-else-if="!loading && events.length === 0"><td class="text-secondary text-center py-4" colspan="7">{{ t('No audit events') }}</td></tr>
            <tr v-for="event in events" :key="event.id">
              <td class="text-nowrap">{{ formatServerDate(event.occurred_at) }}</td>
              <td><span class="badge bg-blue-lt text-blue text-nowrap">{{ actionLabel(event.action) }}</span></td>
              <td class="audit-summary">{{ event.summary }}</td>
              <td><span class="text-nowrap">{{ resourceLabel(event.resource_type) }}</span><small v-if="event.resource_id" class="d-block text-secondary font-monospace">{{ event.resource_id }}</small></td>
              <td class="text-nowrap">{{ event.actor }}</td>
              <td><span class="font-monospace text-nowrap">{{ event.remote_address || '-' }}</span><small v-if="event.user_agent" class="d-block text-secondary audit-user-agent" :title="event.user_agent">{{ event.user_agent }}</small></td>
              <td>
                <details v-if="hasDetails(event.details)" class="audit-details">
                  <summary>{{ t('View details') }}</summary>
                  <pre>{{ formatDetails(event.details) }}</pre>
                </details>
                <span v-else class="text-secondary">-</span>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
      <AppPagination :pagination="pagination" @update:page="changePage" />
    </template>
  </section>
</template>

<script setup>
import { computed, ref, watch } from 'vue';
import AppPagination from '../components/AppPagination.vue';
import { useAbortableTask } from '../composables/useAbortableTask.js';
import { usePageController } from '../composables/usePageController.js';
import { apiJson, isAbortError } from '../lib/api.js';
import { formatServerDate } from '../lib/formatters.js';
import { t } from '../lib/i18n.js';

defineOptions({ inheritAttrs: false });
const props = defineProps({ isAuthenticated: Boolean });
defineEmits(['login']);

const events = ref([]);
const filters = ref({ actions: [], resource_types: [] });
const pagination = ref({ page: 1, per_page: 50, pages: 1, total: 0 });
const search = ref('');
const resourceType = ref('');
const action = ref('');
const loading = ref(false);
const error = ref('');
const request = useAbortableTask();

usePageController({
  loading,
  label: computed(() => t(loading.value ? 'Loading' : 'Audit log')),
  title: computed(() => t('Refresh audit log')),
  disabled: computed(() => !props.isAuthenticated),
  refresh: load
});

watch(() => props.isAuthenticated, (authenticated) => {
  if (authenticated) load();
  else { events.value = []; pagination.value = { page: 1, per_page: 50, pages: 1, total: 0 }; }
}, { immediate: true });

async function load(page = pagination.value.page || 1) {
  if (!props.isAuthenticated) return;
  const signal = request.nextSignal();
  const query = new URLSearchParams({ page: String(page), per_page: '50' });
  if (search.value) query.set('search', search.value);
  if (resourceType.value) query.set('resource_type', resourceType.value);
  if (action.value) query.set('action', action.value);
  loading.value = true;
  error.value = '';
  try {
    const data = await apiJson(`/api/audit?${query}`, { signal });
    if (!request.isCurrent(signal)) return;
    events.value = data?.events || [];
    filters.value = data?.filters || { actions: [], resource_types: [] };
    pagination.value = data?.pagination || { page: 1, per_page: 50, pages: 1, total: 0 };
  } catch (loadError) {
    if (!isAbortError(loadError) && request.isCurrent(signal)) error.value = loadError.message;
  } finally {
    if (request.isCurrent(signal)) loading.value = false;
  }
}

function applyFilters() { load(1); }
function changePage(page) { load(page); }
function hasDetails(details) { return details && typeof details === 'object' && Object.keys(details).length > 0; }
function formatDetails(details) { return JSON.stringify(details, null, 2); }
function actionLabel(value) { return String(value || '').replaceAll('_', ' ').replaceAll('.', ' · '); }
function resourceLabel(value) { return String(value || '').replaceAll('_', ' '); }
</script>
