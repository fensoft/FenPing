<template>
  <section>
    <div v-if="error" class="alert alert-danger mb-3" role="alert">{{ error }}</div>
    <div class="netboot-header">
      <div><h2>Netboot Images</h2><div class="text-secondary small">Private boot files served by dnsmasq TFTP</div></div>
      <button class="btn btn-outline-secondary btn-sm" type="button" :disabled="loading" @click="load"><AppIcon name="refresh" class="me-1" :class="{ 'is-spinning': loading }" />Refresh</button>
    </div>
    <div v-if="!isAuthenticated" class="alert alert-info" role="alert">Guest mode is read only. You can browse and download images.</div>
    <form v-if="isAuthenticated" class="netboot-upload" @submit.prevent="upload">
      <label class="form-label netboot-name-field">Name<input v-model.trim="uploadForm.name" class="form-control form-control-sm" type="text" placeholder="Optional display name" /></label>
      <label class="form-label netboot-file-field">File<input ref="fileInput" class="form-control form-control-sm" type="file" accept=".efi,.kpxe,.kkpxe,.kkkpxe,.pxe,.lkrn,.0,.ipxe" @change="onFile" /></label>
      <button class="btn btn-primary btn-sm" type="submit" :disabled="uploading"><AppIcon name="upload" class="me-1" :class="{ 'is-spinning': uploading }" />Upload</button>
    </form>
    <div class="table-wrap">
      <table class="table table-sm netboot-table">
        <colgroup><col class="netboot-col-name" /><col class="netboot-col-file" /><col class="netboot-col-size" /><col class="netboot-col-hosts" /><col class="netboot-col-created" /><col v-if="isAuthenticated" class="netboot-col-actions" /></colgroup>
        <thead><tr><th>Name</th><th>File</th><th class="text-nowrap">Size</th><th class="text-center text-nowrap">Hosts</th><th>Created</th><th v-if="isAuthenticated" class="text-end">Actions</th></tr></thead>
        <tbody>
          <tr v-if="loading && images.length === 0"><td class="text-secondary text-center py-4" :colspan="isAuthenticated ? 6 : 5">Loading</td></tr>
          <tr v-else-if="!loading && images.length === 0"><td class="text-secondary text-center py-4" :colspan="isAuthenticated ? 6 : 5">No images</td></tr>
          <tr v-for="image in images" :key="image.id">
            <td class="text-truncate-cell" :title="image.name"><strong>{{ image.name }}</strong><small v-if="image.original_name && image.original_name !== image.name" class="text-secondary">{{ image.original_name }}</small></td>
            <td class="text-truncate-cell font-monospace" :title="image.filename"><a :href="image.url" target="_blank" rel="noopener noreferrer">{{ image.filename }}</a></td>
            <td class="text-nowrap">{{ formatBytes(image.size) }}</td><td class="text-center text-nowrap">{{ image.hosts || 0 }}</td><td class="text-nowrap">{{ formatServerDate(image.created_at) }}</td>
            <td v-if="isAuthenticated" class="text-end"><button class="btn btn-outline-danger btn-sm icon-btn" type="button" title="Delete image" :disabled="loading" @click="remove(image)"><AppIcon name="trash" /></button></td>
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
import { formatBytes, formatServerDate } from '../lib/formatters.js';

defineOptions({ inheritAttrs: false });
const props = defineProps({ isAuthenticated: Boolean });
const emit = defineEmits(['login', 'notice']);
const images = ref([]);
const loading = ref(false);
const uploading = ref(false);
const error = ref('');
const uploadForm = ref({ name: '', file: null });
const fileInput = ref(null);
const loadRequest = useAbortableTask();
const mutationRequest = useAbortableTask();

usePageController({ loading, label: computed(() => loading.value ? 'Loading' : 'Netboot'), title: 'Refresh netboot images', disabled: false, refresh: load });
onMounted(load);

async function load() {
  const signal = loadRequest.nextSignal();
  loading.value = true;
  error.value = '';
  try {
    const data = await apiJson('/api/netboot/images', { signal });
    if (loadRequest.isCurrent(signal)) images.value = data?.images || [];
  } catch (loadError) {
    if (!isAbortError(loadError) && loadRequest.isCurrent(signal)) error.value = loadError.message;
  } finally {
    if (loadRequest.isCurrent(signal)) loading.value = false;
  }
}

function onFile(event) { uploadForm.value.file = event.target.files?.[0] || null; }

async function upload() {
  if (!props.isAuthenticated) return emit('login');
  if (!uploadForm.value.file) { error.value = 'Choose a file to upload'; return; }
  const signal = mutationRequest.nextSignal();
  uploading.value = true;
  error.value = '';
  try {
    const body = new FormData();
    body.append('file', uploadForm.value.file);
    body.append('name', uploadForm.value.name || '');
    await apiJson('/api/netboot/images', { method: 'POST', body, signal });
    if (!mutationRequest.isCurrent(signal)) return;
    emit('notice', 'Image uploaded');
    uploadForm.value = { name: '', file: null };
    if (fileInput.value) fileInput.value.value = '';
    await load();
  } catch (uploadError) {
    if (!isAbortError(uploadError) && mutationRequest.isCurrent(signal)) error.value = uploadError.message;
  } finally {
    if (mutationRequest.isCurrent(signal)) uploading.value = false;
  }
}

async function remove(image) {
  if (!props.isAuthenticated) return emit('login');
  if (!window.confirm(`Delete ${image?.name || image?.filename || 'this image'}?`)) return;
  const signal = mutationRequest.nextSignal();
  loading.value = true;
  error.value = '';
  try {
    await apiJson(`/api/netboot/images/${encodeURIComponent(image.id)}`, { method: 'DELETE', signal });
    if (!mutationRequest.isCurrent(signal)) return;
    emit('notice', 'Image deleted');
    await load();
  } catch (deleteError) {
    if (!isAbortError(deleteError) && mutationRequest.isCurrent(signal)) error.value = deleteError.message;
  } finally {
    if (mutationRequest.isCurrent(signal)) loading.value = false;
  }
}
</script>
