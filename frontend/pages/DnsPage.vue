<template>
  <section>
    <div v-if="error" class="alert alert-danger mb-3" role="alert">{{ error }}</div>
    <div class="dns-header">
      <div><h2>{{ t('DNS overrides') }}</h2><div class="text-secondary small">{{ t('Named hosts-file groups and local CNAME aliases') }}</div></div>
      <div class="d-flex gap-2">
        <button v-if="isAuthenticated" class="btn btn-primary btn-sm" type="button" @click="newGroup"><AppIcon name="plus" class="me-1" />{{ t('New group') }}</button>
        <button class="btn btn-outline-secondary btn-sm" type="button" :disabled="loading" @click="load"><AppIcon name="refresh" class="me-1" :class="{ 'is-spinning': loading }" />{{ t('Refresh') }}</button>
      </div>
    </div>
    <div v-if="!isAuthenticated" class="alert alert-info" role="alert">{{ t('Guest mode is read only. You can browse DNS groups.') }}</div>

    <div class="dns-workspace">
      <aside class="dns-group-list" :aria-label="t('DNS groups')">
        <div v-if="loading && groups.length === 0" class="text-secondary text-center p-4">{{ t('Loading') }}</div>
        <div v-else-if="groups.length === 0" class="text-secondary text-center p-4">{{ t('No DNS groups') }}</div>
        <button
          v-for="group in groups"
          :key="group.id"
          class="dns-group-item"
          :class="{ active: draft?.id === group.id }"
          type="button"
          @click="selectGroup(group)"
        >
          <span><strong>{{ group.name }}</strong><small>{{ t('{count} records', { count: group.record_count || 0 }) }}</small></span>
          <span class="badge" :class="group.enabled ? 'bg-green-lt text-green' : 'bg-secondary-lt text-secondary'">{{ t(group.enabled ? 'Enabled' : 'Disabled') }}</span>
        </button>
      </aside>

      <div v-if="draft" class="dns-editor">
        <div class="dns-editor-toolbar">
          <label class="form-label flex-fill mb-0">{{ t('Name') }}<input v-model="draft.name" class="form-control form-control-sm" type="text" maxlength="80" :readonly="!isAuthenticated" /></label>
          <label class="form-check form-switch mb-1"><input v-model="draft.enabled" class="form-check-input" type="checkbox" :disabled="!isAuthenticated" /><span class="form-check-label">{{ t('Enabled') }}</span></label>
        </div>
        <div class="dns-editor-label">
          <label class="form-label mb-0" for="dns-records">{{ t('DNS records') }}</label>
          <label v-if="isAuthenticated" class="btn btn-outline-secondary btn-sm mb-0">{{ t('Import text file') }}<input ref="fileInput" class="visually-hidden" type="file" accept=".txt,.hosts,text/plain" @change="importFile" /></label>
        </div>
        <textarea id="dns-records" v-model="draft.contents" class="form-control dns-records-editor" rows="19" spellcheck="false" :readonly="!isAuthenticated" :placeholder="exampleText"></textarea>
        <div class="form-hint mt-2">{{ t('One record per line. Use IPv4 name [name ...] or CNAME alias target.') }}</div>
        <div class="form-hint">{{ t('CNAME targets must resolve to a managed FenPing name or an enabled custom IP record.') }}</div>
        <div v-if="isAuthenticated" class="dns-editor-actions">
          <button v-if="draft.id" class="btn btn-outline-danger btn-sm" type="button" :disabled="saving" @click="remove"><AppIcon name="trash" class="me-1" />{{ t('Delete') }}</button>
          <span class="flex-fill"></span>
          <button class="btn btn-primary btn-sm" type="button" :disabled="saving || !draft.name.trim()" @click="save"><AppIcon name="device-floppy" class="me-1" />{{ t(draft.id ? 'Save' : 'Create') }}</button>
        </div>
      </div>
      <div v-else class="dns-editor-empty text-secondary">{{ t('Select a DNS group or create one.') }}</div>
    </div>
  </section>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue';
import { apiJson, isAbortError } from '../lib/api.js';
import { t } from '../lib/i18n.js';
import { useAbortableTask } from '../composables/useAbortableTask.js';
import { useLiveRefresh } from '../composables/useLiveUpdates.js';
import { usePageController } from '../composables/usePageController.js';

defineOptions({ inheritAttrs: false });
const props = defineProps({ isAuthenticated: Boolean });
const emit = defineEmits(['login', 'notice']);
const groups = ref([]);
const draft = ref(null);
const loading = ref(false);
const saving = ref(false);
const error = ref('');
const fileInput = ref(null);
const loadRequest = useAbortableTask();
const mutationRequest = useAbortableTask();
const exampleText = '# IP overrides\n192.168.1.20 printer.example.test printer\n\n# Alias to a local record\nCNAME print.example.test printer.example.test';

usePageController({ loading, label: computed(() => t(loading.value ? 'Loading' : 'DNS')), title: computed(() => t('Refresh DNS groups')), disabled: false, refresh: load });
useLiveRefresh(['dns'], load);
onMounted(load);

async function load() {
  const signal = loadRequest.nextSignal();
  const selectedId = draft.value?.id;
  loading.value = true;
  error.value = '';
  try {
    const data = await apiJson('/api/dns/groups', { signal });
    if (!loadRequest.isCurrent(signal)) return;
    groups.value = data?.groups || [];
    const selected = groups.value.find(group => group.id === selectedId) || groups.value[0];
    draft.value = selected ? copy(selected) : null;
  } catch (loadError) {
    if (!isAbortError(loadError) && loadRequest.isCurrent(signal)) error.value = loadError.message;
  } finally {
    if (loadRequest.isCurrent(signal)) loading.value = false;
  }
}

function copy(group) { return { id: group.id, name: group.name || '', enabled: Boolean(group.enabled), contents: group.contents || '' }; }
function selectGroup(group) { draft.value = copy(group); error.value = ''; }
function newGroup() {
  if (!props.isAuthenticated) return emit('login');
  draft.value = { id: null, name: '', enabled: true, contents: '' };
  error.value = '';
}

async function importFile(event) {
  const file = event.target.files?.[0];
  if (!file || !draft.value) return;
  try { draft.value.contents = await file.text(); }
  catch { error.value = t('Unable to read file'); }
  finally { if (fileInput.value) fileInput.value.value = ''; }
}

async function save() {
  if (!props.isAuthenticated) return emit('login');
  const signal = mutationRequest.nextSignal();
  saving.value = true;
  error.value = '';
  try {
    const creating = !draft.value.id;
    const path = creating ? '/api/dns/groups' : `/api/dns/groups/${encodeURIComponent(draft.value.id)}`;
    const response = await apiJson(path, { method: creating ? 'POST' : 'PUT', body: JSON.stringify(draft.value), signal });
    if (!mutationRequest.isCurrent(signal)) return;
    draft.value = copy(response.group);
    emit('notice', t(creating ? 'DNS group created' : 'DNS group updated'));
    await load();
  } catch (saveError) {
    if (!isAbortError(saveError) && mutationRequest.isCurrent(signal)) error.value = saveError.message;
  } finally {
    if (mutationRequest.isCurrent(signal)) saving.value = false;
  }
}

async function remove() {
  if (!props.isAuthenticated) return emit('login');
  if (!window.confirm(t('Delete {name}?', { name: draft.value.name }))) return;
  const signal = mutationRequest.nextSignal();
  saving.value = true;
  error.value = '';
  try {
    await apiJson(`/api/dns/groups/${encodeURIComponent(draft.value.id)}`, { method: 'DELETE', signal });
    if (!mutationRequest.isCurrent(signal)) return;
    emit('notice', t('DNS group deleted'));
    draft.value = null;
    await load();
  } catch (deleteError) {
    if (!isAbortError(deleteError) && mutationRequest.isCurrent(signal)) error.value = deleteError.message;
  } finally {
    if (mutationRequest.isCurrent(signal)) saving.value = false;
  }
}
</script>
