<template>
  <div class="app-shell">
    <div class="app-layout" :inert="modal ? true : undefined" :aria-hidden="modal ? 'true' : undefined">
      <aside class="app-sidebar">
        <div class="app-brand">
          <img class="app-brand-icon" :src="'/icon.webp'" alt="FenPing" />
          <img class="app-brand-favicon" :src="'/favicon-32x32.png'" alt="FenPing" />
        </div>
        <nav class="app-nav" :aria-label="t('Primary navigation')">
          <div class="app-nav-group">
            <button class="app-nav-link" :class="{ active: isInventoryRoute }" type="button" :title="t('Inventory')" :aria-current="isInventoryRoute ? 'page' : undefined" @click="go('/')"><AppIcon name="list-details" /><span>{{ t('Inventory') }}</span></button>
            <button class="app-nav-link" :class="{ active: route.name === routeNames.services }" type="button" :title="t('Services')" :aria-current="route.name === routeNames.services ? 'page' : undefined" @click="go('/services')"><AppIcon name="world-www" /><span>{{ t('Services') }}</span></button>
            <button class="app-nav-link" :class="{ active: route.name === routeNames.notify }" type="button" :title="t('Notify')" :aria-current="route.name === routeNames.notify ? 'page' : undefined" @click="go('/notify')"><AppIcon name="bell" /><span>{{ t('Notify') }}</span></button>
          </div>
          <div class="app-nav-group app-nav-bottom">
            <button class="app-nav-link" :class="{ active: route.name === routeNames.topology }" type="button" :title="t('Observed topology')" :aria-current="route.name === routeNames.topology ? 'page' : undefined" @click="go('/topology')"><AppIcon name="topology" /><span>{{ t('Topology') }}</span></button>
            <button class="app-nav-link" :class="{ active: route.name === routeNames.ipam }" type="button" title="IPAM" :aria-current="route.name === routeNames.ipam ? 'page' : undefined" @click="go('/ipam')"><AppIcon name="address-book" /><span>IPAM</span></button>
            <button class="app-nav-link" :class="{ active: route.name === routeNames.scans }" type="button" :title="t('Scans')" :aria-current="route.name === routeNames.scans ? 'page' : undefined" @click="go('/scans')"><AppIcon name="radar" /><span>{{ t('Scans') }}</span></button>
            <button class="app-nav-link" :class="{ active: route.name === routeNames.netboot }" type="button" :title="t('Netboot images')" :aria-current="route.name === routeNames.netboot ? 'page' : undefined" @click="go('/netboot-images')"><AppIcon name="server" /><span>{{ t('Netboot') }}</span></button>
            <button v-if="isAuthenticated" class="app-nav-link" :class="{ active: route.name === routeNames.backups }" type="button" :title="t('Backups')" :aria-current="route.name === routeNames.backups ? 'page' : undefined" @click="go('/backups')"><AppIcon name="archive" /><span>{{ t('Backups') }}</span></button>
            <button v-if="isAuthenticated" class="app-nav-link" :class="{ active: route.name === routeNames.doctor }" type="button" :title="t('Operations')" :aria-current="route.name === routeNames.doctor ? 'page' : undefined" @click="go('/doctor')"><AppIcon name="activity-heartbeat" /><span>{{ t('Operations') }}</span></button>
          </div>
          <div class="app-sidebar-tools" role="group" :aria-label="t('Application controls')">
            <button class="app-nav-link app-sidebar-tool refresh-btn" :class="{ 'is-spinning': pageLoading || scanning, 'is-pulsing': refreshPulsing }" type="button" :title="refreshTitle" :aria-label="refreshTitle" :disabled="refreshDisabled" @click="requestRefresh"><AppIcon name="refresh" /><span class="app-sidebar-tool-label">{{ t('Refresh') }}</span><small class="app-sidebar-refresh-state" aria-live="polite">{{ refreshLabel }}</small></button>
            <label class="app-nav-link app-sidebar-tool app-language-tool" :class="{ 'app-language-tool-red': ['ru', 'zh-CN'].includes(locale) }" :title="t('Change language')"><AppIcon name="language" /><select class="form-select form-select-sm app-language-select" :value="localePreference" :aria-label="t('Language')" @change="selectLocale"><option value="auto">{{ t('Auto') }} (auto)</option><option v-for="item in supportedLocales" :key="item.id" :value="item.id">{{ item.label }}</option></select></label>
            <button class="app-nav-link app-sidebar-tool" type="button" :title="t(darkMode ? 'Light mode' : 'Dark mode')" :aria-label="t(darkMode ? 'Light mode' : 'Dark mode')" @click="toggleDarkMode"><AppIcon :name="darkMode ? 'sun' : 'moon'" /><span class="app-sidebar-tool-label">{{ t(darkMode ? 'Light mode' : 'Dark mode') }}</span></button>
            <button class="app-nav-link app-sidebar-tool" type="button" :disabled="authLoading" :title="t(isAuthenticated ? 'Logout' : 'Login')" :aria-label="t(isAuthenticated ? 'Logout' : 'Login')" @click="isAuthenticated ? logout() : openLogin()"><AppIcon :name="isAuthenticated ? 'logout' : 'login'" /><span class="app-sidebar-tool-label">{{ t(isAuthenticated ? 'Logout' : 'Login') }}</span><span class="badge app-sidebar-auth-state" :class="isAuthenticated ? 'bg-green-lt text-green' : 'bg-secondary-lt text-secondary'">{{ t(isAuthenticated ? 'Admin' : 'Guest') }}</span></button>
          </div>
        </nav>
      </aside>

      <div class="app-main">
        <main class="app-content container-xl py-3">
        <div v-if="activeConflicts.length" class="alert alert-danger d-flex align-items-center gap-3 mb-3" role="alert">
          <AppIcon name="alert-triangle" />
          <div class="flex-fill">
            <strong>{{ t('{count} active IP conflicts', { count: activeConflicts.length }) }}</strong>
            <div class="small">{{ t('Multiple devices are answering for the same IPv4 address.') }}</div>
          </div>
          <button class="btn btn-danger btn-sm" type="button" @click="go('/ipam')">{{ t('Review conflicts') }}</button>
        </div>
        <div v-if="globalError" class="alert alert-danger mb-3" role="alert">{{ globalError }}</div>
        <div v-if="notice" class="alert alert-success mb-3" role="status">{{ notice }}</div>
        <RouterView v-slot="{ Component }">
          <component
            :is="Component"
            :is-authenticated="isAuthenticated"
            :scanning="scanning"
            :refresh-queued="refreshQueued"
            :scanning-hosts="scanningHosts"
            :cancelling-scans="cancellingScans"
            @network="network = $event"
            @selected-network="selectedNetwork = $event"
            @login="openLogin"
            @notice="setNotice"
            @ping-refresh="refreshScan"
            @scan-host="openScanProfile"
            @cancel-scan="cancelScan"
            @host-detail="openHostDetail"
            @open-history="openHistory"
            @open-scan="openScan"
            @open-edit="openEdit"
            @open-metadata="openMetadata"
            @open-scan-error="openScanError"
            @open-create="openCreate"
            @reserve-device="openReserve"
            @add-category="openAddCategory"
            @rename-category="openRenameCategory"
            @delete-category="openDeleteCategory"
          />
        </RouterView>
        </main>
      </div>
    </div>

    <AppModal
      v-if="modal"
      :modal="modal"
      :error="modalError"
      :saving="saving"
      :network="network"
      :netboot-images="netbootImages"
      :is-authenticated="isAuthenticated"
      :scanning-hosts="scanningHosts"
      :cancelling-scans="cancellingScans"
      @close="closeModal"
      @submit-login="submitLogin"
      @submit-edit="submitEdit"
      @submit-metadata="submitMetadata"
      @delete-host="openDeleteHost(modal.form)"
      @cancel-scan="cancelScan"
      @submit-create="submitCreate"
      @submit-category="submitCategory"
      @submit-rename-category="submitRenameCategory"
      @submit-delete-host="submitDeleteHost"
      @submit-delete-category="submitDeleteCategory"
      @submit-scan-profile="submitScanProfile"
      @select-scan="selectScanHistory"
      @open-scan="openScan"
      @scan-host="openScanProfile"
      @open-edit="openEdit"
      @open-metadata="openMetadata"
    />
  </div>
</template>

<script setup>
import { computed, onMounted, onUnmounted, ref, unref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import AppModal from './components/AppModal.vue';
import { apiJson, isAbortError } from './lib/api.js';
import { useAbortableTask } from './composables/useAbortableTask.js';
import { provideLiveUpdates } from './composables/useLiveUpdates.js';
import { providePageController } from './composables/usePageController.js';
import { formatMac, toFlag } from './lib/formatters.js';
import { locale, localePreference, setLocale, supportedLocales, t } from './lib/i18n.js';
import { editableHostTags } from './lib/inventoryFilters.js';
import { scanProfileLabel } from './lib/scanProfiles.js';
import { routeNames } from './router.js';

const router = useRouter();
const route = useRoute();
const pageController = providePageController();
const liveUpdates = provideLiveUpdates();
const network = ref('');
const selectedNetwork = ref(readSelectedNetwork());
const notice = ref('');
const globalError = ref('');
const auth = ref({ authenticated: false, configured: false });
const authLoading = ref(false);
const cancellingScans = ref(new Set());
const operatorCancelledScans = new Set();
const darkMode = ref(readCookie('fenping_theme') === 'dark');
const scanning = ref(false);
const scanningHosts = ref(new Set());
const refreshQueued = ref(false);
const refreshPulsing = ref(false);
const networkRefreshing = ref(false);
const modal = ref(null);
const modalError = ref('');
const saving = ref(false);
const netbootImages = ref([]);
const conflictState = ref({ status: 'pending', conflicts: [] });
const appRequest = useAbortableTask();
const modalRequest = useAbortableTask();
const liveModalRequest = useAbortableTask();

const isAuthenticated = computed(() => Boolean(auth.value?.authenticated));
const isInventoryRoute = computed(() => [routeNames.inventory, routeNames.host, routeNames.hostByIp].includes(route.name));
const pageLoading = computed(() => networkRefreshing.value || Boolean(controllerValue('loading', false)));
const refreshLabel = computed(() => controllerValue('label', t(isAuthenticated.value ? 'Ready' : 'Read only')));
const refreshTitle = computed(() => controllerValue('title', t(isAuthenticated.value ? 'Refresh' : 'Login to refresh')));
const refreshDisabled = computed(() => networkRefreshing.value || Boolean(controllerValue('disabled', false)));
const activeConflicts = computed(() => conflictState.value?.conflicts || []);
let conflictTimer = null;
let unsubscribeConflictLive = null;
let unsubscribeModalLive = null;

onMounted(async () => {
  applyTheme();
  unsubscribeConflictLive = liveUpdates.subscribe(['hosts', 'status', 'conflicts', 'leases', 'vendors'], loadIpConflicts);
  unsubscribeModalLive = liveUpdates.subscribe(['hosts', 'status', 'scans', 'netboot'], refreshLiveModal);
  await Promise.all([loadSession(), loadIpConflicts()]);
  conflictTimer = window.setInterval(loadIpConflicts, 60000);
});

onUnmounted(() => {
  if (conflictTimer !== null) window.clearInterval(conflictTimer);
  unsubscribeConflictLive?.();
  unsubscribeModalLive?.();
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
function selectLocale(event) { setLocale(event.target.value); }
function openHostDetail(host) {
  if (!host) return;
  clearMessages();
  modal.value = { type: 'hostDetail', host: typeof host === 'object' ? host : { id: host } };
}
function setNotice(message) { globalError.value = ''; notice.value = message || ''; }

async function reloadCurrentPage() {
  const action = pageController.value?.reload || pageController.value?.refresh;
  if (typeof action === 'function') await action();
}

async function requestRefresh() {
  pulseRefresh();
  if (networkRefreshing.value) return;
  networkRefreshing.value = true;
  try {
    await apiJson('/api/networks/refresh', { method: 'POST' });
  } catch (error) {
    globalError.value = error.message;
  } finally {
    networkRefreshing.value = false;
  }

  if (route.name === routeNames.inventory) {
    const reload = pageController.value?.reload;
    if (typeof reload === 'function') await reload();
    if (!isAuthenticated.value) return;
  }

  const action = pageController.value?.refresh;
  if (typeof action === 'function') await action();
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

async function loadIpConflicts() {
  try {
    const data = await apiJson('/api/ipam/conflicts');
    if (data && Array.isArray(data.conflicts)) conflictState.value = data;
  } catch {
    // Preserve the last known warning when polling is temporarily unavailable.
  }
}

function openLogin() {
  clearMessages();
  modal.value = { type: 'login', password: '' };
}

async function submitLogin() {
  await saveModal(async (signal) => {
    auth.value = await apiJson('/api/auth/login', { method: 'POST', body: JSON.stringify({ password: modal.value.password }), signal }) || { authenticated: true, configured: auth.value.configured };
    setNotice(t('Logged in'));
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
    setNotice(t('Logged out'));
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
    await apiJson('/api/ping/refresh', {
      method: 'POST',
      body: JSON.stringify({ network: selectedNetwork.value || undefined })
    });
    await loadIpConflicts();
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

function openScanProfile(host) {
  if (!isAuthenticated.value) return openLogin();
  if (!host?.ip || isHostScanning(host)) return;
  clearMessages();
  modal.value = { type: 'scanProfile', host };
}

function submitScanProfile(profile) {
  const host = modal.value?.host;
  closeModal();
  if (host) scanHost(host, profile);
}

async function scanHost(host, profile) {
  if (!host?.ip || isHostScanning(host)) return;
  clearMessages();
  setHostScanning(host, true);
  try {
    const result = await apiJson(`/api/scans/${encodeURIComponent(host.ip)}`, { method: 'POST', body: JSON.stringify({ profile }) });
    const queuedProfile = scanProfileLabel(result?.metadata?.mode || profile);
    setNotice(t(result?.created === false ? '{profile} scan already queued or running' : '{profile} scan queued', { profile: queuedProfile }));
    await reloadCurrentPage();
    const metadata = await pollScanStatus(host, result?.metadata?.id);
    if (metadata?.state === 'cancelled' && operatorCancelledScans.has(Number(metadata.id))) {
      operatorCancelledScans.delete(Number(metadata.id));
      setNotice(t('Scan cancelled'));
      await reloadCurrentPage();
      return;
    }
    if (metadata && ['failed', 'timeout', 'cancelled'].includes(metadata.state))
      throw new Error(metadata.error || t('Scan {state}', { state: t(metadata.state) }));
    setNotice(t(metadata?.result_changed ? '{profile} scan changes saved' : '{profile} scan complete, no changes', { profile: queuedProfile }));
    await reloadCurrentPage();
  } catch (error) {
    globalError.value = error.message;
  } finally {
    setHostScanning(host, false);
  }
}

async function cancelScan(scan) {
  const id = Number(scan?.id || 0);
  if (!scan?.ip || !id || cancellingScans.value.has(id)) return;
  clearMessages();
  cancellingScans.value = new Set(cancellingScans.value).add(id);
  operatorCancelledScans.add(id);
  try {
    const result = await apiJson(`/api/scans/${encodeURIComponent(scan.ip)}/${encodeURIComponent(id)}/cancel`, { method: 'POST' });
    setNotice(t(result?.cancelled ? 'Scan cancelled' : 'Scan cancellation requested'));
    await reloadCurrentPage();
    if (!isHostScanning(scan)) operatorCancelledScans.delete(id);
  } catch (error) {
    operatorCancelledScans.delete(id);
    globalError.value = error.message;
  } finally {
    const next = new Set(cancellingScans.value);
    next.delete(id);
    cancellingScans.value = next;
  }
}

async function pollScanStatus(host, scanId = null) {
  const key = hostScanKey(host);
  while (scanningHosts.value.has(key)) {
    try {
      const suffix = scanId ? `?id=${encodeURIComponent(scanId)}` : '';
      const metadata = await apiJson(`/api/scans/${encodeURIComponent(host.ip)}/status${suffix}`);
      if (metadata && metadata.state !== 'none' && !scanIsActiveState(metadata.state)) return metadata;
    } catch {
      // A transient status failure should not lose the running job.
    }
    if (await liveUpdates.waitFor(['scans'], 15000) === 'stopped') return null;
  }
  return null;
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
  clearMessages(); modal.value = { type: 'create', source_device: host.device_identity || null, form: { mac: formatMac(host.mac), ip: toShortIp(host.ip || '') } };
}
function openReserve(host) {
  if (!isAuthenticated.value) return openLogin();
  clearMessages(); modal.value = { type: 'create', purpose: 'reserve', form: { mac: formatMac(host.mac), ip: '' } };
}

async function openEdit(host) {
  if (!isAuthenticated.value) return openLogin();
  if (!host?.id || !toFlag(host.network_is_dhcp)) return;
  clearMessages(); modal.value = { type: 'loading' };
  const signal = modalRequest.nextSignal();
  try {
    const [data, images] = await Promise.all([
      apiJson(`/api/hosts/${encodeURIComponent(host.id)}`, { signal }),
      apiJson('/api/netboot/images', { signal })
    ]);
    if (!modalRequest.isCurrent(signal)) return;
    if (!data) throw new Error(t('Host not found'));
    netbootImages.value = images?.images || [];
    modal.value = { type: 'edit', form: hostForm({ ...host, ...data }) };
  } catch (error) {
    if (!isAbortError(error)) { modal.value = null; globalError.value = error.message; }
  }
}

function openMetadata(host) {
  if (!isAuthenticated.value) return openLogin();
  if (!host?.metadata_editable || !host?.device_identity?.network || !host?.device_identity?.container) return;
  clearMessages();
  modal.value = { type: 'metadataEdit', form: metadataForm(host) };
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
  await loadHistoryModal(ip);
}

async function loadHistoryModal(ip, request = modalRequest) {
  const signal = request.nextSignal();
  try {
    const payload = await apiJson(`/api/history/${encodeURIComponent(ip)}`, { signal });
    if (request.isCurrent(signal) && modal.value?.type === 'history' && modal.value.ip === ip) {
      modal.value.rows = Array.isArray(payload) ? payload : payload?.rows || [];
      modal.value.summary = Array.isArray(payload) ? null : payload?.summary || null;
    }
  } catch (error) { if (!isAbortError(error)) modalError.value = error.message; }
}

function scanJsonUrl(ip, scanId = null) { return scanId ? `/api/scans/${encodeURIComponent(ip)}/history/${encodeURIComponent(scanId)}` : `/api/scans/${encodeURIComponent(ip)}`; }
function openScanError(scan) {
  if (!scan?.error) return;
  clearMessages();
  modal.value = { type: 'scanError', ip: scan.ip || '', error: String(scan.error) };
}

async function openScan(ip, scanId = null) {
  if (!ip) return;
  clearMessages(); modal.value = { type: 'scan', ip, loading: true, scan: null, history: null, selectedScanId: scanId };
  await loadScanModal(ip, scanId);
}

async function loadScanModal(ip, scanId = null, request = modalRequest) {
  if (modal.value?.type !== 'scan' || modal.value.ip !== ip) return;
  modal.value.loading = true;
  const signal = request.nextSignal();
  try {
    const [scan, history] = await Promise.all([apiJson(scanJsonUrl(ip, scanId), { signal }), apiJson(`/api/scans/${encodeURIComponent(ip)}/history`, { signal })]);
    if (request.isCurrent(signal)
      && modal.value?.type === 'scan'
      && modal.value.ip === ip
      && (scanId === null || String(modal.value.selectedScanId || '') === String(scanId))) {
      modal.value.loading = false; modal.value.scan = scan; modal.value.history = history || []; modal.value.selectedScanId = scan.metadata?.id || scanId || null;
    }
  } catch (error) {
    if (!isAbortError(error) && modal.value?.type === 'scan') { modal.value.loading = false; modalError.value = error.message; }
  }
}

async function refreshLiveModal(scopes) {
  const current = modal.value;
  if (!current) return;
  const all = scopes.has('all');
  if (current.type === 'history' && (all || scopes.has('status') || scopes.has('hosts'))) {
    await loadHistoryModal(current.ip, liveModalRequest);
  } else if (current.type === 'scan' && (all || scopes.has('scans'))) {
    await loadScanModal(current.ip, current.selectedScanId, liveModalRequest);
  } else if (current.type === 'edit' && (all || scopes.has('netboot'))) {
    const signal = liveModalRequest.nextSignal();
    try {
      const images = await apiJson('/api/netboot/images', { signal });
      if (liveModalRequest.isCurrent(signal) && modal.value?.type === 'edit') netbootImages.value = images?.images || [];
    } catch (error) {
      if (!isAbortError(error)) modalError.value = error.message;
    }
  }
}

async function selectScanHistory(scanId) {
  if (modal.value?.type !== 'scan') return;
  const id = Number(scanId || 0); if (!id) return;
  const ip = modal.value.ip; modal.value.loading = true; modal.value.selectedScanId = id; modalError.value = '';
  const signal = modalRequest.nextSignal();
  try {
    const scan = await apiJson(scanJsonUrl(ip, id), { signal });
    if (modalRequest.isCurrent(signal) && modal.value?.type === 'scan') { modal.value.scan = scan; modal.value.selectedScanId = scan.metadata?.id || id; }
  } catch (error) { if (!isAbortError(error)) modalError.value = error.message; }
  finally { if (modalRequest.isCurrent(signal) && modal.value?.type === 'scan') modal.value.loading = false; }
}

async function submitCreate() {
  await saveModal(async (signal) => {
    const purpose = modal.value.purpose;
    const form = modal.value.form;
    const source_device = modal.value.source_device || undefined;
    const result = await apiJson('/api/hosts', { method: 'POST', body: JSON.stringify({ mac: form.mac, ip: form.ip, source_device }), signal });
    setNotice(t(purpose === 'reserve' ? 'Reservation created' : 'Created')); await reloadCurrentPage();
    if (result?.id) await openEdit({ id: result.id, network_is_dhcp: 1 }); else closeModal();
  });
}
async function submitEdit() {
  await saveModal(async (signal) => {
    const form = modal.value.form;
    await apiJson(`/api/hosts/${encodeURIComponent(form.id)}`, { method: 'PUT', signal, body: JSON.stringify({ ip: form.ip, router: form.router, mac: form.mac, name: form.name, display_name: form.display_name, important: form.important ? 1 : null, repeater: form.repeater ? 1 : null, dns: form.dns, web: form.web ? 1 : null, netboot_image_id: form.netboot_image_id || null, scan_profile: form.scan_profile, scan_interval_hours: form.scan_interval_hours, notes: form.notes, location: form.location, owner: form.owner, model: form.model, icon: form.icon || null, tags: form.tags }) });
    setNotice(t('Saved')); closeModal(); await reloadCurrentPage();
  });
}
async function submitMetadata() {
  await saveModal(async (signal) => {
    const form = modal.value.form;
    const metadata = { display_name: form.display_name, important: form.important ? 1 : null, web: form.web ? 1 : null, scan_profile: form.scan_profile, scan_interval_hours: form.scan_interval_hours, notes: form.notes, location: form.location, owner: form.owner, model: form.model, icon: form.icon || null, tags: form.tags };
    const endpoint = form.host_id
      ? '/api/hosts/' + encodeURIComponent(form.host_id) + '/metadata'
      : '/api/inventory/device-metadata';
    const body = form.host_id ? metadata : { network: form.network, container: form.container, ...metadata };
    await apiJson(endpoint, { method: 'PUT', signal, body: JSON.stringify(body) });
    setNotice(t('Saved')); closeModal(); await reloadCurrentPage();
  });
}
async function submitDeleteHost() {
  await saveModal(async (signal) => {
    await apiJson(`/api/hosts/${encodeURIComponent(modal.value.id)}`, { method: 'DELETE', signal });
    setNotice(t('Deleted')); closeModal();
    if ([routeNames.host, routeNames.hostByIp].includes(route.name)) await router.push('/'); else await reloadCurrentPage();
  });
}
async function submitCategory() {
  await saveModal(async (signal) => { const form = modal.value.form; await apiJson('/api/categories', { method: 'POST', signal, body: JSON.stringify({ ip: form.ip, name: form.name }) }); setNotice(t('Category added')); closeModal(); await reloadCurrentPage(); });
}
async function submitRenameCategory() {
  await saveModal(async (signal) => { await apiJson('/api/categories', { method: 'PUT', signal, body: JSON.stringify({ ip: modal.value.ip, name: modal.value.form.name }) }); setNotice(t('Category renamed')); closeModal(); await reloadCurrentPage(); });
}
async function submitDeleteCategory() {
  await saveModal(async (signal) => { await apiJson('/api/categories', { method: 'DELETE', signal, body: JSON.stringify({ ip: modal.value.ip }) }); setNotice(t('Category deleted')); closeModal(); await reloadCurrentPage(); });
}

async function saveModal(action) {
  saving.value = true; modalError.value = '';
  const signal = modalRequest.nextSignal();
  try { await action(signal); }
  catch (error) { if (!isAbortError(error)) modalError.value = error.message; }
  finally { saving.value = false; }
}

function closeModal() { modalRequest.abort(); liveModalRequest.abort(); modal.value = null; modalError.value = ''; saving.value = false; }
function clearMessages() { modalError.value = ''; globalError.value = ''; notice.value = ''; }
function toShortIp(ip) { const value = String(ip || ''); const prefix = `${network.value}.`; return value.startsWith(prefix) ? value.slice(prefix.length) : value; }
function hostForm(data) {
  return {
    id: data.id,
    ip: toShortIp(data.ip || ''),
    router: data.router || '',
    mac: formatMac(data.mac),
    name: data.name || '',
    display_name: data.display_name || '',
    important: toFlag(data.important),
    repeater: toFlag(data.repeater),
    dns: data.dns || '',
    web: toFlag(data.web),
    netboot_image_id: data.netboot_image_id ? String(data.netboot_image_id) : '',
    scan_profile: data.scan_profile || 'standard',
    scan_interval_hours: Number(data.scan_interval_hours ?? 24),
    notes: data.notes || '',
    location: data.location || '',
    owner: data.owner || '',
    model: data.model || '',
    icon: data.icon || '',
    tags: editableHostTags(data)
  };
}
function metadataForm(data) {
  return {
    host_id: data.id || null,
    show_identity: !data.id,
    network: data.device_identity?.network || '',
    container: data.device_identity?.container || '',
    ip: data.ip || '',
    display_name: data.display_name || (data.id && !toFlag(data.network_is_dhcp) ? data.name || '' : ''),
    important: toFlag(data.important),
    web: toFlag(data.web),
    scan_profile: data.scan_profile || 'lightweight',
    scan_interval_hours: Number(data.scan_interval_hours ?? 24),
    notes: data.notes || '',
    location: data.location || '',
    owner: data.owner || '',
    model: data.model || '',
    icon: data.icon || '',
    tags: editableHostTags(data)
  };
}


function toggleDarkMode() { darkMode.value = !darkMode.value; writeCookie('fenping_theme', darkMode.value ? 'dark' : 'light'); applyTheme(); }
function applyTheme() { const theme = darkMode.value ? 'dark' : 'light'; document.documentElement.dataset.bsTheme = theme; document.documentElement.style.colorScheme = theme; }
function readCookie(name) { const prefix = `${name}=`; const match = document.cookie.split(';').map((part) => part.trim()).find((part) => part.startsWith(prefix)); return match ? match.slice(prefix.length) : ''; }
function writeCookie(name, value) { document.cookie = `${name}=${value}; Max-Age=${60 * 60 * 24 * 365}; Path=/; SameSite=Lax`; }
function readSelectedNetwork() { try { return JSON.parse(localStorage.getItem('fenping_selected_network') || '""') || ''; } catch { return ''; } }
</script>
