<template>
  <div
    ref="modalRoot"
    class="modal modal-blur show d-block"
    tabindex="-1"
    role="dialog"
    aria-modal="true"
    :aria-labelledby="titleId"
    :aria-busy="saving"
    @mousedown.self="requestClose"
  >
    <div class="modal-dialog modal-dialog-centered" :class="dialogClass" role="document">
      <div class="modal-content">
        <div class="modal-header">
          <h2 :id="titleId" class="modal-title">{{ title }}</h2>
          <button class="btn-close" type="button" aria-label="Close" :disabled="saving" @click="requestClose"></button>
        </div>

        <form v-if="modal.type === 'login'" @submit.prevent="$emit('submit-login')">
          <div class="modal-body"><div v-if="error" class="alert alert-danger">{{ error }}</div><label class="form-label">Password<input v-model="modal.password" class="form-control" type="password" autocomplete="current-password" autofocus /></label></div>
          <ModalFooter :saving="saving" submit-label="Login" submit-icon="ti ti-login" @close="requestClose" />
        </form>

        <form v-else-if="modal.type === 'edit'" @submit.prevent="$emit('submit-edit')">
          <div class="modal-body">
            <div v-if="error" class="alert alert-danger">{{ error }}</div>
            <div class="modal-body-grid">
              <label class="form-label">IP<div class="input-group"><span class="input-group-text">{{ network }}.</span><input v-model.trim="modal.form.ip" class="form-control" name="ip" type="text" /></div></label>
              <label class="form-label">Router<div class="input-group"><span class="input-group-text">{{ network }}.</span><input v-model.trim="modal.form.router" class="form-control" name="router" type="text" /></div></label>
              <label class="form-label">MAC<input v-model.trim="modal.form.mac" class="form-control font-monospace" name="mac" type="text" /></label>
              <label class="form-label">Name<input v-model.trim="modal.form.name" class="form-control" name="name" type="text" /></label>
              <label class="form-label">Scheduled scan profile<select v-model="modal.form.scan_profile" class="form-select" name="scan_profile"><option v-for="profile in scanProfiles" :key="profile.id" :value="profile.id">{{ profile.name }}</option></select></label>
              <label class="form-label">Scan cadence<div class="input-group"><input v-model.number="modal.form.scan_interval_hours" class="form-control" name="scan_interval_hours" type="number" min="0" max="8760" list="scan-cadence-options" required /><span class="input-group-text">hours</span></div><small class="form-hint">Use 0 to disable scheduled scans.</small><datalist id="scan-cadence-options"><option v-for="cadence in scanCadenceOptions" :key="cadence.hours" :value="cadence.hours">{{ cadence.name }}</option></datalist></label>
              <label class="form-label field-wide">DNS<input v-model.trim="modal.form.dns" class="form-control" name="dns" type="text" /></label>
              <label class="form-label field-wide">Netboot image<select v-model="modal.form.netboot_image_id" class="form-select" name="netboot_image_id"><option value="">None</option><option v-for="image in netbootImages" :key="image.id" :value="String(image.id)">{{ image.name }} ({{ image.filename }})</option></select></label>
              <div class="modal-switch-grid field-wide">
                <label class="form-check form-switch"><input v-model="modal.form.important" class="form-check-input" type="checkbox" /><span class="form-check-label">Important</span></label>
                <label class="form-check form-switch"><input v-model="modal.form.repeater" class="form-check-input" type="checkbox" /><span class="form-check-label">Router/repeater</span></label>
                <label class="form-check form-switch"><input v-model="modal.form.web" class="form-check-input" type="checkbox" /><span class="form-check-label">Web</span></label>
              </div>
            </div>
          </div>
          <div class="modal-footer justify-content-between"><button class="btn btn-outline-danger" type="button" :disabled="saving" @click="$emit('delete-host')"><i class="ti ti-trash me-1"></i>Delete</button><div><button class="btn btn-link" type="button" :disabled="saving" @click="requestClose">Cancel</button><button class="btn btn-primary" type="submit" :disabled="saving"><i class="ti ti-device-floppy me-1"></i>Save</button></div></div>
        </form>

        <form v-else-if="modal.type === 'create'" @submit.prevent="$emit('submit-create')">
          <div class="modal-body"><div v-if="error" class="alert alert-danger">{{ error }}</div><div class="modal-body-grid"><label class="form-label">MAC<input v-model.trim="modal.form.mac" class="form-control font-monospace" name="mac" type="text" required /></label><label class="form-label">IP<div class="input-group"><span class="input-group-text">{{ network }}.</span><input v-model.trim="modal.form.ip" class="form-control" name="ip" type="text" inputmode="numeric" required /></div></label></div></div>
          <ModalFooter :saving="saving" :submit-label="modal.purpose === 'reserve' ? 'Reserve' : 'Create'" :submit-icon="modal.purpose === 'reserve' ? 'ti ti-pin' : 'ti ti-plus'" @close="requestClose" />
        </form>

        <form v-else-if="modal.type === 'category'" @submit.prevent="$emit('submit-category')">
          <div class="modal-body"><div v-if="error" class="alert alert-danger">{{ error }}</div><div class="modal-body-grid"><label class="form-label">Start IP<div class="input-group"><span class="input-group-text">{{ network }}.</span><input v-model.trim="modal.form.ip" class="form-control" name="ip" type="text" /></div></label><label class="form-label">Name<input v-model.trim="modal.form.name" class="form-control" name="name" type="text" /></label></div></div>
          <ModalFooter :saving="saving" submit-label="Add" submit-icon="ti ti-folder-plus" @close="requestClose" />
        </form>

        <form v-else-if="modal.type === 'renameCategory'" @submit.prevent="$emit('submit-rename-category')">
          <div class="modal-body"><div v-if="error" class="alert alert-danger">{{ error }}</div><div class="modal-body-grid"><label class="form-label">Start IP<input :value="modal.ip" class="form-control font-monospace" name="ip" type="text" disabled /></label><label class="form-label">Name<input v-model.trim="modal.form.name" class="form-control" name="name" type="text" /></label></div></div>
          <ModalFooter :saving="saving" submit-label="Save" submit-icon="ti ti-device-floppy" @close="requestClose" />
        </form>

        <form v-else-if="modal.type === 'deleteHost' || modal.type === 'deleteCategory'" @submit.prevent="$emit(modal.type === 'deleteHost' ? 'submit-delete-host' : 'submit-delete-category')">
          <div class="modal-body"><div v-if="error" class="alert alert-danger">{{ error }}</div><p class="mb-3">{{ modal.name || modal.mac || modal.ip || modal.id }}</p></div>
          <div class="modal-footer"><button class="btn btn-link" type="button" :disabled="saving" @click="requestClose">Cancel</button><button class="btn btn-danger" type="submit" :disabled="saving"><i class="ti ti-trash me-1"></i>Delete</button></div>
        </form>

        <div v-else-if="modal.type === 'scanProfile'">
          <div class="modal-body">
            <div class="text-secondary mb-3">Choose how thoroughly to scan <strong class="text-body">{{ modal.host?.name || modal.host?.ip }}</strong>.</div>
            <div class="scan-profile-grid">
              <button v-for="(profile, index) in scanProfiles" :key="profile.id" class="scan-profile-option" type="button" :autofocus="index === 0" @click="$emit('submit-scan-profile', profile.id)">
                <i :class="profile.icon"></i>
                <span><strong>{{ profile.name }}</strong><small>{{ profile.description }}</small><small class="text-secondary">{{ profile.timeout }}</small></span>
              </button>
            </div>
          </div>
          <div class="modal-footer"><button class="btn btn-link" type="button" @click="requestClose">Cancel</button></div>
        </div>

        <div v-else-if="modal.type === 'history'">
          <div class="modal-body p-0">
            <div v-if="error" class="alert alert-danger m-3">{{ error }}</div>
            <div v-if="modal.summary" class="history-summary">
              <div class="history-summary-item"><span>Uptime</span><strong>{{ formatPercent(modal.summary.uptime_percent) }}</strong></div>
              <div class="history-summary-item"><span>Changes</span><strong>{{ modal.summary.transitions }}x</strong></div>
              <div class="history-summary-item"><span>Longest Down</span><strong>{{ formatDuration(modal.summary.longest_down_seconds) }}</strong></div>
              <div class="history-summary-item"><span>Current</span><strong>{{ modal.summary.current_status || '-' }} {{ formatDuration(modal.summary.current_seconds) }}</strong></div>
            </div>
            <table class="table table-sm history-table"><thead><tr><th>MAC</th><th>Status</th><th>Date</th></tr></thead><tbody>
              <tr v-if="modal.rows === null"><td class="text-secondary text-center py-4" colspan="3">Loading</td></tr>
              <tr v-for="item in modal.rows || []" :key="item.id" :class="historyRowClass(item)"><td class="font-monospace">{{ formatMac(item.mac) }}</td><td><span :class="statusClass(item.status)" :title="statusTitle(item.status)" class="status-pill"><i :class="statusIcon(item.status)"></i></span></td><td>{{ formatServerDate(item.date_begin) }} for {{ formatDuration(item.duration) }}</td></tr>
            </tbody></table>
          </div>
          <div class="modal-footer"><button class="btn btn-primary" type="button" @click="requestClose">Close</button></div>
        </div>

        <div v-else-if="modal.type === 'hostDetail'">
          <div class="modal-body host-detail-modal-body">
            <HostDetailPage
              embedded
              :device="modal.host"
              :is-authenticated="isAuthenticated"
              :scanning-hosts="scanningHosts"
              @open-scan="forwardOpenScan"
              @scan-host="$emit('scan-host', $event)"
              @open-edit="$emit('open-edit', $event)"
            />
          </div>
          <div class="modal-footer"><button class="btn btn-primary" type="button" @click="requestClose">Close</button></div>
        </div>

        <div v-else-if="modal.type === 'scan'">
          <div class="modal-body scan-body">
            <div v-if="error" class="alert alert-danger">{{ error }}</div><div v-if="modal.loading" class="text-secondary py-4 text-center">Loading</div>
            <template v-else-if="modal.scan">
              <div class="scan-topline"><div><div class="font-monospace scan-ip">{{ modal.ip }}</div><div class="text-secondary small">{{ modal.scan.started || modal.scan.args }}</div><div v-if="modal.scan.merged_with" class="text-secondary small">Merged with full scan from {{ formatScanDate(modal.scan.merged_with.date_end || modal.scan.merged_with.date_begin) }}</div></div>
                <div v-if="modal.history && modal.history.length" class="scan-actions"><label class="scan-history-control"><span>Scan history</span><select class="form-select form-select-sm scan-history-select" :value="modal.selectedScanId || ''" :disabled="modal.history.length < 2" @change="$emit('select-scan', $event.target.value)"><option v-for="scan in modal.history" :key="scan.id" :value="scan.id">{{ scanHistoryLabel(scan) }}</option></select></label></div>
              </div>
              <div class="scan-summary">
                <div class="scan-fact"><span>Status</span><strong>{{ modal.scan.status || '-' }}</strong></div><div class="scan-fact"><span>State</span><strong>{{ modal.scan.metadata?.state || '-' }}</strong></div><div class="scan-fact"><span>Profile</span><strong>{{ scanModeLabel(modal.scan) }}</strong></div><div class="scan-fact"><span>Ports</span><strong>{{ modal.scan.ports_count ?? modal.scan.metadata?.ports_count ?? modal.scan.ports.length }}</strong></div><div class="scan-fact"><span>Duration</span><strong>{{ formatScanDuration(modal.scan.metadata?.duration ?? modal.scan.duration) }}</strong></div><div class="scan-fact"><span>Last</span><strong>{{ formatScanDate(modal.scan.metadata?.date_end || modal.scan.metadata?.date_begin || modal.scan.started) }}</strong></div>
              </div>
              <ScanSimpleSection v-if="modal.scan.addresses.length" title="Addresses"><tr v-for="address in modal.scan.addresses" :key="`${address.type}-${address.addr}`"><td class="scan-type">{{ address.type }}</td><td class="font-monospace">{{ address.addr }}</td><td class="text-truncate-cell">{{ address.vendor }}</td></tr></ScanSimpleSection>
              <ScanSimpleSection v-if="modal.scan.hostnames.length" title="Hostnames"><tr v-for="hostname in modal.scan.hostnames" :key="`${hostname.name}-${hostname.type}`"><td>{{ hostname.name }}</td><td class="scan-type">{{ hostname.type }}</td></tr></ScanSimpleSection>
              <ScanSimpleSection v-if="modal.scan.os.length" title="OS"><tr v-for="os in modal.scan.os" :key="os.name"><td>{{ os.name }}</td><td class="scan-type">{{ os.accuracy }}%</td></tr></ScanSimpleSection>
              <div class="scan-section"><h3>Ports</h3><table v-if="modal.scan.ports.length" class="table table-sm scan-table"><thead><tr><th>Port</th><th>State</th><th>Service</th><th>Details</th><th>Source</th></tr></thead><tbody><tr v-for="port in modal.scan.ports" :key="`${port.protocol}-${port.port}`"><td class="font-monospace">{{ port.port }}/{{ port.protocol }}</td><td><span :class="scanStateClass(port.state)">{{ port.state || '-' }}</span></td><td>{{ port.service || '-' }}</td><td class="text-truncate-cell" :title="port.details">{{ port.details }}</td><td class="scan-type">{{ scanProfileLabel(port.source || modal.scan.metadata?.mode) }}</td></tr></tbody></table><div v-else class="text-secondary small">No ports found</div></div>
            </template>
          </div>
          <div class="modal-footer"><button class="btn btn-primary" type="button" @click="requestClose">Close</button></div>
        </div>

        <div v-else class="modal-body"><div class="text-secondary">Loading</div></div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue';
import HostDetailPage from '../pages/HostDetailPage.vue';
import ModalFooter from './ModalFooter.vue';
import ScanSimpleSection from './ScanSimpleSection.vue';
import { useAccessibleModal } from '../composables/useAccessibleModal.js';
import { formatDuration, formatMac, formatPercent, formatScanDate, formatScanDuration, formatServerDate, historyRowClass, scanStateClass, statusClass, statusIcon, statusTitle } from '../lib/formatters.js';
import { scanCadenceOptions, scanProfileLabel, scanProfiles } from '../lib/scanProfiles.js';

const props = defineProps({ modal: { type: Object, required: true }, error: { type: String, default: '' }, saving: Boolean, network: { type: String, default: '' }, netbootImages: { type: Array, default: () => [] }, isAuthenticated: Boolean, scanningHosts: { type: Object, required: true } });
const emit = defineEmits(['close', 'delete-host', 'open-edit', 'open-scan', 'scan-host', 'select-scan', 'submit-category', 'submit-create', 'submit-delete-category', 'submit-delete-host', 'submit-edit', 'submit-login', 'submit-rename-category', 'submit-scan-profile']);
const titleId = `fenping-modal-title-${Math.random().toString(36).slice(2)}`;
const title = computed(() => props.modal.type === 'create' && props.modal.purpose === 'reserve' ? 'Reserve address' : ({ login: 'Login', edit: 'Edit host', create: 'Create host', category: 'Add category', renameCategory: 'Rename category', deleteHost: 'Delete host', deleteCategory: 'Delete category', scanProfile: 'Start scan', history: `History ${props.modal.ip || ''}`, hostDetail: 'Host details', scan: `Scan ${props.modal.ip || ''}`, loading: 'Loading' })[props.modal.type] || 'Dialog');
const dialogClass = computed(() => props.modal.type === 'login' ? 'modal-sm' : ['scan', 'hostDetail'].includes(props.modal.type) ? 'modal-xl scan-modal-dialog' : 'modal-lg');
const modalRoot = useAccessibleModal(() => props.modal.type, requestClose);

function requestClose() { if (!props.saving) emit('close'); }
function forwardOpenScan(...args) { emit('open-scan', ...args); }
function scanModeLabel(scan) { return scan?.merged_with ? `${scanProfileLabel(scan?.metadata?.mode)} + Deep` : scanProfileLabel(scan?.metadata?.mode); }
function scanHistoryLabel(scan) { return `${formatServerDate(scan.date_end || scan.date_begin || '')} ${scanProfileLabel(scan.mode)} ${scan.status || scan.state || '-'} ${Number(scan.ports_count || 0)}p`; }
</script>
