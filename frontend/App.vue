<template>
  <div class="app-shell">
    <header class="app-header py-2" :inert="modal ? true : undefined" :aria-hidden="modal ? 'true' : undefined">
      <div class="container-xl d-flex align-items-center justify-content-between gap-2">
        <div><h1 class="app-title">FenPing</h1><div class="text-secondary small">{{ network || 'Network' }}</div></div>
        <div class="toolbar">
          <button class="btn btn-outline-secondary icon-btn" :class="{ active: isInventoryRoute }" type="button" title="Inventory" @click="go('/')"><i class="ti ti-list-details"></i></button>
          <button class="btn btn-outline-secondary icon-btn" :class="{ active: route.name === routeNames.notify }" type="button" title="Notify" @click="go('/notify')"><i class="ti ti-bell"></i></button>
          <button class="btn btn-outline-secondary icon-btn" :class="{ active: route.name === routeNames.scans }" type="button" title="Scans" @click="go('/scans')"><i class="ti ti-radar"></i></button>
          <button class="btn btn-outline-secondary icon-btn" :class="{ active: route.name === routeNames.services }" type="button" title="Services" @click="go('/services')"><i class="ti ti-world-www"></i></button>
          <button class="btn btn-outline-secondary icon-btn" :class="{ active: route.name === routeNames.netboot }" type="button" title="Netboot images" @click="go('/netboot-images')"><i class="ti ti-server"></i></button>
          <span class="badge auth-badge" :class="isAuthenticated ? 'bg-green-lt text-green' : 'bg-secondary-lt text-secondary'">{{ isAuthenticated ? 'Admin' : 'Guest' }}</span>
          <button class="btn btn-outline-primary auth-button" type="button" :disabled="authLoading" :title="isAuthenticated ? 'Logout' : 'Login'" @click="isAuthenticated ? logout() : openLogin()"><i :class="isAuthenticated ? 'ti ti-logout' : 'ti ti-login'"></i><span class="d-none d-sm-inline ms-1">{{ isAuthenticated ? 'Logout' : 'Login' }}</span></button>
          <button class="btn btn-outline-secondary icon-btn" type="button" :title="darkMode ? 'Light mode' : 'Dark mode'" @click="toggleDarkMode"><i :class="darkMode ? 'ti ti-sun' : 'ti ti-moon'"></i></button>
          <button class="btn btn-outline-primary icon-btn refresh-btn" :class="{ 'is-spinning': pageLoading || scanning, 'is-pulsing': refreshPulsing }" type="button" :title="refreshTitle" :disabled="refreshDisabled" @click="requestRefresh"><i class="ti ti-refresh"></i></button>
          <span class="text-secondary small">{{ refreshLabel }}</span>
        </div>
      </div>
    </header>

    <main class="container-xl py-3" :inert="modal ? true : undefined" :aria-hidden="modal ? 'true' : undefined">
      <div v-if="globalError" class="alert alert-danger mb-3" role="alert">{{ globalError }}</div>
      <div v-if="notice" class="alert alert-success mb-3" role="status">{{ notice }}</div>
      <RouterView v-slot="{ Component }">
        <component
          :is="Component"
          :is-authenticated="isAuthenticated"
          :scanning="scanning"
          :refresh-queued="refreshQueued"
          :scanning-hosts="scanningHosts"
          @network="network = $event"
          @login="openLogin"
          @notice="setNotice"
          @ping-refresh="refreshScan"
          @quick-scan="quickScanHost"
          @host-detail="navigateHostDetail"
          @open-history="openHistory"
          @open-scan="openScan"
          @open-edit="openEdit"
          @open-create="openCreate"
          @add-category="openAddCategory"
          @rename-category="openRenameCategory"
          @delete-category="openDeleteCategory"
        />
      </RouterView>
    </main>

    <AppModal
      v-if="modal"
      :modal="modal"
      :error="modalError"
      :saving="saving"
      :network="network"
      :netboot-images="netbootImages"
      @close="closeModal"
      @submit-login="submitLogin"
      @submit-edit="submitEdit"
      @delete-host="openDeleteHost(modal.form)"
      @submit-create="submitCreate"
      @submit-category="submitCategory"
      @submit-rename-category="submitRenameCategory"
      @submit-delete-host="submitDeleteHost"
      @submit-delete-category="submitDeleteCategory"
      @select-scan="selectScanHistory"
      @toggle-raw="toggleScanRaw"
    />
  </div>
</template>

<script setup>
import { computed, onMounted, onUnmounted, ref, unref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import AppModal from './components/AppModal.vue';
import { apiJson, apiText, isAbortError } from './lib/api.js';
import { useAbortableTask } from './composables/useAbortableTask.js';
import { providePageController } from './composables/usePageController.js';
import { formatMac, toFlag } from './lib/formatters.js';
import { routeNames } from './router.js';

const router = useRouter();
const route = useRoute();
const pageController = providePageController();
const network = ref('');
const notice = ref('');
const globalError = ref('');
const auth = ref({ authenticated: false, configured: false });
const authLoading = ref(false);
const darkMode = ref(readCookie('fenping_theme') === 'dark');
const scanning = ref(false);
const scanningHosts = ref(new Set());
const refreshQueued = ref(false);
const refreshPulsing = ref(false);
const modal = ref(null);
const modalError = ref('');
const saving = ref(false);
const netbootImages = ref([]);
const appRequest = useAbortableTask();
const modalRequest = useAbortableTask();
const pollingTimers = new Set();

const isAuthenticated = computed(() => Boolean(auth.value?.authenticated));
const isInventoryRoute = computed(() => route.name === routeNames.inventory || route.name === routeNames.host);
const pageLoading = computed(() => Boolean(controllerValue('loading', false)));
const refreshLabel = computed(() => controllerValue('label', isAuthenticated.value ? 'Ready' : 'Read only'));
const refreshTitle = computed(() => controllerValue('title', isAuthenticated.value ? 'Refresh' : 'Login to refresh'));
const refreshDisabled = computed(() => Boolean(controllerValue('disabled', false)));

onMounted(async () => {
  applyTheme();
  await loadSession();
  if (isAuthenticated.value && route.name === routeNames.inventory)
    refreshScan();
});

onUnmounted(() => {
  for (const timer of pollingTimers) window.clearTimeout(timer);
  pollingTimers.clear();
});

watch(() => route.fullPath, () => {
  globalError.value = '';
  if (modal.value) closeModal();
});

function controllerValue(key, fallback) {
  const value = pageController.value?.[key];
  return value === undefined ? fallback : unref(value);
}

function go(path) { if (route.path !== path) router.push(path); }
function navigateHostDetail(id) { if (id) router.push({ name: routeNames.host, params: { id } }); }
function setNotice(message) { globalError.value = ''; notice.value = message || ''; }

async function reloadCurrentPage() {
  const action = pageController.value?.reload || pageController.value?.refresh;
  if (typeof action === 'function') await action();
}

function requestRefresh() {
  pulseRefresh();
  const action = pageController.value?.refresh;
  if (typeof action === 'function') action();
}

function pulseRefresh() {
  refreshPulsing.value = false;
  requestAnimationFrame(() => {
    refreshPulsing.value = true;
    window.setTimeout(() => { refreshPulsing.value = false; }, 350);
  });
}

async function loadSession() {
  const signal = appRequest.nextSignal();
  authLoading.value = true;
  try {
    auth.value = await apiJson('/api/auth/session', { signal }) || { authenticated: false, configured: false };
  } catch (error) {
    if (!isAbortError(error)) auth.value = { authenticated: false, configured: false };
  } finally {
    if (appRequest.isCurrent(signal)) authLoading.value = false;
  }
}

function openLogin() {
  clearMessages();
  modal.value = { type: 'login', password: '' };
}

async function submitLogin() {
  await saveModal(async (signal) => {
    auth.value = await apiJson('/api/auth/login', { method: 'POST', body: JSON.stringify({ password: modal.value.password }), signal }) || { authenticated: true, configured: auth.value.configured };
    setNotice('Logged in');
    closeModal();
    await reloadCurrentPage();
    if (route.name === routeNames.inventory) refreshScan();
  });
}

async function logout() {
  clearMessages();
  authLoading.value = true;
  try {
    auth.value = await apiJson('/api/auth/logout', { method: 'POST' }) || { authenticated: false, configured: auth.value.configured };
    setNotice('Logged out');
    await reloadCurrentPage();
  } catch (error) {
    globalError.value = error.message;
  } finally {
    authLoading.value = false;
  }
}

async function refreshScan() {
  if (!isAuthenticated.value) return openLogin();
  if (scanning.value) { refreshQueued.value = true; return; }
  scanning.value = true;
  globalError.value = '';
  try {
    await apiJson('/api/ping/refresh', { method: 'POST' });
    await reloadCurrentPage();
  } catch (error) {
    globalError.value = error.message;
  } finally {
    scanning.value = false;
    if (refreshQueued.value) { refreshQueued.value = false; refreshScan(); }
  }
}

function hostScanKey(host) { return String(host?.ip || host?.id || host?.mac || ''); }
function isHostScanning(host) { const key = hostScanKey(host); return key !== '' && scanningHosts.value.has(key); }
function scanIsActiveState(state) { return state === 'queued' || state === 'running'; }
function setHostScanning(host, value) {
  const key = hostScanKey(host); if (!key) return;
  const next = new Set(scanningHosts.value); value ? next.add(key) : next.delete(key); scanningHosts.value = next;
}

async function quickScanHost(host) {
  if (!isAuthenticated.value) return openLogin();
  if (!host?.ip || isHostScanning(host)) return;
  clearMessages();
  setHostScanning(host, true);
  try {
    const result = await apiJson(`/api/scans/${encodeURIComponent(host.ip)}/quick`, { method: 'POST' });
    setNotice(result?.created === false ? 'Scan already queued or running' : 'Scan queued');
    await reloadCurrentPage();
    const metadata = await pollScanStatus(host, result?.metadata?.id);
    if (metadata && ['failed', 'timeout', 'cancelled'].includes(metadata.state)) throw new Error(metadata.error || `Scan ${metadata.state}`);
    setNotice(metadata?.result_changed ? 'Scan changes saved' : 'Scan complete, no changes');
    await reloadCurrentPage();
  } catch (error) {
    globalError.value = error.message;
  } finally {
    setHostScanning(host, false);
  }
}

async function pollScanStatus(host, scanId = null) {
  const key = hostScanKey(host);
  let delay = 300;
  while (scanningHosts.value.has(key)) {
    await wait(delay); delay = 1000;
    try {
      const suffix = scanId ? `?id=${encodeURIComponent(scanId)}` : '';
      const metadata = await apiJson(`/api/scans/${encodeURIComponent(host.ip)}/status${suffix}`);
      if (metadata && metadata.state !== 'none' && !scanIsActiveState(metadata.state)) return metadata;
    } catch {
      // A transient status failure should not lose the running job.
    }
  }
  return null;
}

function wait(delay) {
  return new Promise((resolve) => {
    const timer = window.setTimeout(() => { pollingTimers.delete(timer); resolve(); }, delay);
    pollingTimers.add(timer);
  });
}

function openAddCategory() {
  if (!isAuthenticated.value) return openLogin();
  clearMessages(); modal.value = { type: 'category', form: { ip: '', name: '' } };
}
function openRenameCategory(row) {
  if (!isAuthenticated.value) return openLogin();
  clearMessages(); modal.value = { type: 'renameCategory', name: row.name, ip: row.categoryIp, form: { name: row.name || '' } };
}
function openCreate(host) {
  if (!isAuthenticated.value) return openLogin();
  clearMessages(); modal.value = { type: 'create', form: { mac: formatMac(host.mac), ip: toShortIp(host.ip || '') } };
}

async function openEdit(host) {
  if (!isAuthenticated.value) return openLogin();
  if (!host?.id) return;
  clearMessages(); modal.value = { type: 'loading' };
  const signal = modalRequest.nextSignal();
  try {
    const [data, images] = await Promise.all([
      apiJson(`/api/hosts/${encodeURIComponent(host.id)}`, { signal }),
      apiJson('/api/netboot/images', { signal })
    ]);
    if (!modalRequest.isCurrent(signal)) return;
    if (!data) throw new Error('Host not found');
    netbootImages.value = images?.images || [];
    modal.value = { type: 'edit', form: hostForm({ ...host, ...data }) };
  } catch (error) {
    if (!isAbortError(error)) { modal.value = null; globalError.value = error.message; }
  }
}

function openDeleteHost(form) {
  if (!isAuthenticated.value) return openLogin();
  clearMessages(); modal.value = { type: 'deleteHost', id: form.id, name: form.name, mac: form.mac };
}
function openDeleteCategory(row) {
  if (!isAuthenticated.value) return openLogin();
  clearMessages(); modal.value = { type: 'deleteCategory', name: row.name, ip: row.categoryIp };
}

async function openHistory(ip) {
  if (!ip) return;
  clearMessages(); modal.value = { type: 'history', ip, rows: null, summary: null };
  const signal = modalRequest.nextSignal();
  try {
    const payload = await apiJson(`/api/history/${encodeURIComponent(ip)}`, { signal });
    if (modalRequest.isCurrent(signal) && modal.value?.type === 'history') {
      modal.value.rows = Array.isArray(payload) ? payload : payload?.rows || [];
      modal.value.summary = Array.isArray(payload) ? null : payload?.summary || null;
    }
  } catch (error) { if (!isAbortError(error)) modalError.value = error.message; }
}

function scanJsonUrl(ip, scanId = null) { return scanId ? `/api/scans/${encodeURIComponent(ip)}/history/${encodeURIComponent(scanId)}` : `/api/scans/${encodeURIComponent(ip)}`; }
function scanXmlUrl(ip, scanId = null) { return scanId ? `/api/scans/${encodeURIComponent(ip)}/history/${encodeURIComponent(scanId)}/xml` : `/api/scans/${encodeURIComponent(ip)}/xml`; }

async function openScan(ip, scanId = null) {
  if (!ip) return;
  clearMessages(); modal.value = { type: 'scan', ip, loading: true, scan: null, raw: '', showRaw: false, history: null, selectedScanId: scanId };
  const signal = modalRequest.nextSignal();
  try {
    const [scan, history] = await Promise.all([apiJson(scanJsonUrl(ip, scanId), { signal }), apiJson(`/api/scans/${encodeURIComponent(ip)}/history`, { signal })]);
    if (modalRequest.isCurrent(signal) && modal.value?.type === 'scan') {
      modal.value.loading = false; modal.value.scan = scan; modal.value.history = history || []; modal.value.selectedScanId = scan.metadata?.id || scanId || null;
    }
  } catch (error) {
    if (!isAbortError(error) && modal.value?.type === 'scan') { modal.value.loading = false; modalError.value = error.message; }
  }
}

async function selectScanHistory(scanId) {
  if (modal.value?.type !== 'scan') return;
  const id = Number(scanId || 0); if (!id) return;
  const ip = modal.value.ip; modal.value.loading = true; modal.value.raw = ''; modal.value.showRaw = false; modalError.value = '';
  const signal = modalRequest.nextSignal();
  try {
    const scan = await apiJson(scanJsonUrl(ip, id), { signal });
    if (modalRequest.isCurrent(signal) && modal.value?.type === 'scan') { modal.value.scan = scan; modal.value.selectedScanId = scan.metadata?.id || id; }
  } catch (error) { if (!isAbortError(error)) modalError.value = error.message; }
  finally { if (modalRequest.isCurrent(signal) && modal.value?.type === 'scan') modal.value.loading = false; }
}

async function toggleScanRaw() {
  if (modal.value?.type !== 'scan') return;
  if (modal.value.showRaw) { modal.value.showRaw = false; return; }
  if (modal.value.raw === '') {
    const ip = modal.value.ip; const id = modal.value.selectedScanId; const signal = modalRequest.nextSignal();
    try {
      const raw = await apiText(scanXmlUrl(ip, id), { signal });
      if (modalRequest.isCurrent(signal) && modal.value?.type === 'scan') modal.value.raw = raw;
    } catch (error) { if (!isAbortError(error)) modalError.value = error.message; return; }
  }
  if (modal.value?.type === 'scan') modal.value.showRaw = true;
}

async function submitCreate() {
  await saveModal(async (signal) => {
    const form = modal.value.form;
    const result = await apiJson('/api/hosts', { method: 'POST', body: JSON.stringify({ mac: form.mac, ip: form.ip }), signal });
    setNotice('Created'); await reloadCurrentPage();
    if (result?.id) await openEdit({ id: result.id }); else closeModal();
  });
}
async function submitEdit() {
  await saveModal(async (signal) => {
    const form = modal.value.form;
    await apiJson(`/api/hosts/${encodeURIComponent(form.id)}`, { method: 'PUT', signal, body: JSON.stringify({ ip: form.ip, router: form.router, mac: form.mac, name: form.name, important: form.important ? 1 : null, repeater: form.repeater ? 1 : null, dns: form.dns, web: form.web ? 1 : null, netboot_image_id: form.netboot_image_id || null }) });
    setNotice('Saved'); closeModal(); await reloadCurrentPage();
  });
}
async function submitDeleteHost() {
  await saveModal(async (signal) => {
    await apiJson(`/api/hosts/${encodeURIComponent(modal.value.id)}`, { method: 'DELETE', signal });
    setNotice('Deleted'); closeModal();
    if (route.name === routeNames.host) await router.push('/'); else await reloadCurrentPage();
  });
}
async function submitCategory() {
  await saveModal(async (signal) => { const form = modal.value.form; await apiJson('/api/categories', { method: 'POST', signal, body: JSON.stringify({ ip: form.ip, name: form.name }) }); setNotice('Category added'); closeModal(); await reloadCurrentPage(); });
}
async function submitRenameCategory() {
  await saveModal(async (signal) => { await apiJson('/api/categories', { method: 'PUT', signal, body: JSON.stringify({ ip: modal.value.ip, name: modal.value.form.name }) }); setNotice('Category renamed'); closeModal(); await reloadCurrentPage(); });
}
async function submitDeleteCategory() {
  await saveModal(async (signal) => { await apiJson('/api/categories', { method: 'DELETE', signal, body: JSON.stringify({ ip: modal.value.ip }) }); setNotice('Category deleted'); closeModal(); await reloadCurrentPage(); });
}

async function saveModal(action) {
  saving.value = true; modalError.value = '';
  const signal = modalRequest.nextSignal();
  try { await action(signal); }
  catch (error) { if (!isAbortError(error)) modalError.value = error.message; }
  finally { saving.value = false; }
}

function closeModal() { modalRequest.abort(); modal.value = null; modalError.value = ''; saving.value = false; }
function clearMessages() { modalError.value = ''; globalError.value = ''; notice.value = ''; }
function toShortIp(ip) { const value = String(ip || ''); const prefix = `${network.value}.`; return value.startsWith(prefix) ? value.slice(prefix.length) : value; }
function hostForm(data) { return { id: data.id, ip: toShortIp(data.ip || ''), router: data.router || '', mac: formatMac(data.mac), name: data.name || '', important: toFlag(data.important), repeater: toFlag(data.repeater), dns: data.dns || '', web: toFlag(data.web), netboot_image_id: data.netboot_image_id ? String(data.netboot_image_id) : '' }; }

function toggleDarkMode() { darkMode.value = !darkMode.value; writeCookie('fenping_theme', darkMode.value ? 'dark' : 'light'); applyTheme(); }
function applyTheme() { const theme = darkMode.value ? 'dark' : 'light'; document.documentElement.dataset.bsTheme = theme; document.documentElement.style.colorScheme = theme; }
function readCookie(name) { const prefix = `${name}=`; const match = document.cookie.split(';').map((part) => part.trim()).find((part) => part.startsWith(prefix)); return match ? match.slice(prefix.length) : ''; }
function writeCookie(name, value) { document.cookie = `${name}=${value}; Max-Age=${60 * 60 * 24 * 365}; Path=/; SameSite=Lax`; }
</script>
