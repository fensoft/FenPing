<template>
  <section>
    <div v-if="error" class="alert alert-danger mb-3" role="alert">{{ error }}</div>
    <div class="page-refresh-header">
      <div><h2>{{ t('Doctor') }}</h2><div class="text-secondary small">{{ t('Administrator diagnostics for network and appliance services') }}</div></div>
      <button class="btn btn-primary btn-sm" type="button" :disabled="loading || !isAuthenticated" @click="load">
        <AppIcon name="stethoscope" class="me-1" :class="{ 'is-spinning': loading }" />{{ t('Run doctor') }}
      </button>
    </div>

    <div v-if="!isAuthenticated" class="alert alert-info" role="alert">
      {{ t('Login to run system diagnostics.') }}
      <button class="btn btn-primary btn-sm ms-2" type="button" @click="$emit('login')">{{ t('Login') }}</button>
    </div>
    <template v-else>
      <div v-if="loading && !report" class="card">
        <div class="card-body text-secondary text-center py-5"><AppIcon name="loader-2" class="is-spinning me-1" />{{ t('Running diagnostics') }}</div>
      </div>
      <template v-if="report">
        <div class="alert d-flex align-items-start gap-2" :class="report.status === 'ok' ? 'alert-success' : 'alert-danger'" role="status">
          <AppIcon :name="report.status === 'ok' ? 'check' : 'alert-triangle'" class="mt-1" />
          <div>
            <strong>{{ t(report.status === 'ok' ? 'All checks passed' : 'One or more checks failed') }}</strong>
            <div class="small">{{ t('Checked {time}', { time: formatServerDate(report.checked_at) }) }}</div>
          </div>
        </div>

        <div class="row g-3">
          <div v-for="check in report.checks || []" :key="check.id" class="col-12 col-lg-6">
            <article class="card h-100" :class="check.status === 'pass' ? 'border-success' : 'border-danger'">
              <div class="card-body">
                <div class="d-flex align-items-center gap-2 mb-2">
                  <AppIcon :name="check.status === 'pass' ? 'check' : 'alert-triangle'" :class="check.status === 'pass' ? 'text-success' : 'text-danger'" />
                  <h3 class="h4 mb-0 flex-fill">{{ t(checkLabel(check.id)) }}</h3>
                  <span class="badge" :class="check.status === 'pass' ? 'bg-green-lt text-green' : 'bg-red-lt text-red'">{{ t(check.status === 'pass' ? 'Passed' : 'Failed') }}</span>
                </div>
                <p class="mb-0">{{ check.message }}</p>
                <div v-if="check.remediation" class="alert alert-warning py-2 px-3 mt-3 mb-0"><AppIcon name="bolt" class="me-1" />{{ check.remediation }}</div>
              </div>
            </article>
          </div>
        </div>
      </template>
    </template>
  </section>
</template>

<script setup>
import { computed, ref, watch } from 'vue';
import { apiJson, isAbortError } from '../lib/api.js';
import { useAbortableTask } from '../composables/useAbortableTask.js';
import { usePageController } from '../composables/usePageController.js';
import { formatServerDate } from '../lib/formatters.js';
import { t } from '../lib/i18n.js';

defineOptions({ inheritAttrs: false });
const props = defineProps({ isAuthenticated: Boolean });
defineEmits(['login']);
const report = ref(null);
const loading = ref(false);
const error = ref('');
const request = useAbortableTask();

usePageController({
  loading,
  label: computed(() => t(loading.value ? 'Running diagnostics' : 'Doctor')),
  title: computed(() => t('Run doctor')),
  disabled: computed(() => loading.value || !props.isAuthenticated),
  refresh: load
});

watch(() => props.isAuthenticated, (authenticated) => {
  if (authenticated) load();
  else {
    request.abort();
    report.value = null;
    error.value = '';
  }
}, { immediate: true });

async function load() {
  if (!props.isAuthenticated || loading.value) return;
  const signal = request.nextSignal();
  loading.value = true;
  error.value = '';
  try {
    const data = await apiJson('/api/doctor', { signal });
    if (request.isCurrent(signal)) report.value = data;
  } catch (loadError) {
    if (!isAbortError(loadError) && request.isCurrent(signal)) error.value = loadError.message;
  } finally {
    if (request.isCurrent(signal)) loading.value = false;
  }
}

function checkLabel(id) {
  return ({
    interface: 'Interface',
    subnet: 'Subnet',
    router: 'Router',
    'dhcp-pool': 'DHCP pool',
    ports: 'Ports',
    storage: 'Storage',
    'dhcp-server': 'DHCP server'
  })[id] || id;
}
</script>
