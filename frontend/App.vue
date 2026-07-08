<template>
  <div class="app-shell">
    <header class="app-header py-2">
      <div class="container-xl d-flex justify-content-between">
        <div>
          <h1 class="app-title">FenPing</h1>
          <div class="text-secondary small">{{ network || 'Network' }}</div>
        </div>
        <div class="toolbar">
          <button
            class="btn btn-outline-secondary icon-btn"
            type="button"
            :title="darkMode ? 'Light mode' : 'Dark mode'"
            @click="toggleDarkMode"
          >
            <i :class="darkMode ? 'ti ti-sun' : 'ti ti-moon'"></i>
          </button>
          <button
            class="btn btn-outline-primary icon-btn refresh-btn"
            :class="{ 'is-spinning': scanning, 'is-pulsing': refreshPulsing }"
            type="button"
            title="Refresh"
            @click="requestRefresh"
          >
            <i class="ti ti-refresh"></i>
          </button>
          <span class="text-secondary small">{{ refreshLabel }}</span>
          <button class="btn btn-primary icon-btn" type="button" title="Add category" @click="openAddCategory">
            <i class="ti ti-folder-plus"></i>
          </button>
        </div>
      </div>
    </header>

    <main class="container-xl py-3">
      <div v-if="inventoryError" class="alert alert-danger mb-3" role="alert">{{ inventoryError }}</div>
      <div v-if="notice" class="alert alert-success mb-3" role="alert">{{ notice }}</div>

      <div class="table-wrap">
        <div class="table-toolbar">
          <div class="input-icon filter-search">
            <span class="input-icon-addon">
              <i class="ti ti-search"></i>
            </span>
            <input v-model="filters.search" class="form-control form-control-sm" type="search" placeholder="Search" />
          </div>
          <label class="form-check form-switch toolbar-switch">
            <input v-model="filters.onlyDown" class="form-check-input" type="checkbox" />
            <span class="form-check-label">Down</span>
          </label>
          <label class="form-check form-switch toolbar-switch">
            <input v-model="filters.onlyImportant" class="form-check-input" type="checkbox" />
            <span class="form-check-label">Important</span>
          </label>
          <label class="form-check form-switch toolbar-switch">
            <input v-model="filters.hideUnknown" class="form-check-input" type="checkbox" />
            <span class="form-check-label">Hide new</span>
          </label>
          <button
            v-if="hasActiveFilters"
            class="btn btn-outline-secondary btn-sm icon-btn"
            type="button"
            title="Clear filters"
            @click="resetFilters"
          >
            <i class="ti ti-filter-x"></i>
          </button>
          <div class="text-secondary small filter-count">{{ visibleHosts.length }}/{{ hosts.length }}</div>
        </div>
        <table class="table table-sm inventory-table">
          <thead>
            <tr>
              <th class="col-status" scope="col">
                <button
                  class="btn btn-outline-secondary btn-sm icon-btn"
                  type="button"
                  title="Close all categories"
                  @click="closeAllCategories"
                >
                  <i class="ti ti-minus"></i>
                </button>
              </th>
              <th class="col-name" scope="col">Name</th>
              <th class="col-mac" scope="col">MAC</th>
              <th class="col-vendor" scope="col">Vendor</th>
              <th class="col-stability" scope="col">Stability</th>
              <th class="col-ip" scope="col">IP</th>
              <th class="col-actions" scope="col">&nbsp;</th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="inventoryLoading && tableRows.length === 0">
              <td class="text-secondary text-center py-4" colspan="7">Loading</td>
            </tr>
            <tr v-else-if="!inventoryLoading && tableRows.length === 0">
              <td class="text-secondary text-center py-4" colspan="7">No hosts</td>
            </tr>
            <tr v-for="row in tableRows" :key="row.key" :class="rowClass(row)">
              <template v-if="row.type === 'category'">
                <td>
                  <button
                    class="btn btn-outline-secondary btn-sm icon-btn"
                    type="button"
                    :title="row.collapsed ? 'Open category' : 'Close category'"
                    @click="toggleCategory(row.categoryKey)"
                  >
                    <i :class="row.collapsed ? 'ti ti-plus' : 'ti ti-minus'"></i>
                  </button>
                </td>
                <td class="category-name" colspan="5">{{ row.name }}</td>
                <td class="text-end">
                  <button
                    v-if="row.categoryIp"
                    class="btn btn-outline-danger btn-sm icon-btn"
                    type="button"
                    title="Delete category"
                    @click="openDeleteCategory(row)"
                  >
                    <i class="ti ti-trash"></i>
                  </button>
                </td>
              </template>

              <template v-else>
                <td class="status-cell">
                  <div class="status-icons">
                    <span :class="statusClass(row.host.status)" :title="statusTitle(row.host.status)" class="status-pill">
                      <i :class="statusIcon(row.host.status)"></i>
                    </span>
                    <i
                      v-if="isRouterRepeater(row.host)"
                      class="ti ti-wifi text-secondary host-role-icon"
                      title="Router/repeater"
                    ></i>
                  </div>
                </td>
                <td class="text-truncate-cell" :title="row.host.name || ''">
                  <a
                    v-if="row.host.web == 1 && row.host.ip"
                    class="host-name-value"
                    :href="`http://${row.host.ip}`"
                    target="_blank"
                    rel="noopener noreferrer"
                  >
                    {{ row.host.name }}
                  </a>
                  <span v-else class="host-name-value">{{ row.host.name }}</span>
                </td>
                <td class="text-truncate-cell font-monospace" :title="formatMac(row.host.mac)">
                  <span class="mac-value">{{ formatMac(row.host.mac) }}</span>
                  <i v-if="row.host.via" class="ti ti-antenna-bars-5 ms-1 text-secondary" :title="row.host.via"></i>
                </td>
                <td class="text-truncate-cell" :title="row.host.vendor || ''">{{ row.host.vendor }}</td>
                <td>
                  <button
                    v-if="Number(row.host.stats2 || 0) > 1"
                    class="btn btn-link btn-sm p-0"
                    type="button"
                    :title="row.host.stats || ''"
                    @click="openHistory(row.host.ip)"
                  >
                    {{ row.host.stats2 }}
                  </button>
                </td>
                <td class="text-truncate-cell font-monospace" :title="row.host.ip || ''">
                  {{ row.host.ip }}
                  <button
                    v-if="row.host.xml"
                    class="btn btn-outline-secondary btn-sm icon-btn ms-1"
                    type="button"
                    title="View scan XML"
                    @click="openScan(row.host.ip)"
                  >
                    <i class="ti ti-file-search"></i>
                  </button>
                </td>
                <td class="text-end action-cell">
                  <button
                    v-if="row.host.ip"
                    class="btn btn-sm icon-btn"
                    :class="scanActionClass(row.host)"
                    type="button"
                    :title="scanButtonTitle(row.host)"
                    :disabled="isHostScanning(row.host)"
                    @click="quickScanHost(row.host)"
                  >
                    <i :class="isScanRunning(row.host) ? 'ti ti-loader-2' : 'ti ti-search'"></i>
                  </button>
                  <button
                    v-if="row.host.id"
                    class="btn btn-outline-secondary btn-sm icon-btn"
                    type="button"
                    title="Edit host"
                    @click="openEdit(row.host)"
                  >
                    <i class="ti ti-edit"></i>
                  </button>
                  <button
                    v-else-if="row.host.mac"
                    class="btn btn-outline-primary btn-sm icon-btn"
                    type="button"
                    title="Create host"
                    @click="openCreate(row.host)"
                  >
                    <i class="ti ti-plus"></i>
                  </button>
                </td>
              </template>
            </tr>
          </tbody>
        </table>
      </div>
    </main>

    <div v-if="modal" class="modal modal-blur show d-block" tabindex="-1" role="dialog" @click.self="closeModal">
      <div class="modal-dialog modal-dialog-centered" :class="modalDialogClass" role="document">
        <div class="modal-content">
          <div class="modal-header">
            <h2 class="modal-title">{{ modalTitle }}</h2>
            <button class="btn-close" type="button" aria-label="Close" @click="closeModal"></button>
          </div>

          <form v-if="modal.type === 'edit'" @submit.prevent="submitEdit">
            <div class="modal-body">
              <div v-if="modalError" class="alert alert-danger">{{ modalError }}</div>
              <div class="modal-body-grid">
                <label class="form-label">
                  IP
                  <div class="input-group">
                    <span class="input-group-text">{{ network }}.</span>
                    <input v-model.trim="modal.form.ip" class="form-control" name="ip" type="text" />
                  </div>
                </label>
                <label class="form-label">
                  Router
                  <div class="input-group">
                    <span class="input-group-text">{{ network }}.</span>
                    <input v-model.trim="modal.form.router" class="form-control" name="router" type="text" />
                  </div>
                </label>
                <label class="form-label">
                  MAC
                  <input v-model.trim="modal.form.mac" class="form-control font-monospace" name="mac" type="text" />
                </label>
                <label class="form-label">
                  Name
                  <input v-model.trim="modal.form.name" class="form-control" name="name" type="text" />
                </label>
                <label class="form-label">
                  DNS
                  <input v-model.trim="modal.form.dns" class="form-control" name="dns" type="text" />
                </label>
                <label class="form-label">
                  Password
                  <input v-model="modal.form.password" class="form-control" name="password" type="password" />
                </label>
                <label class="form-check form-switch">
                  <input v-model="modal.form.important" class="form-check-input" type="checkbox" />
                  <span class="form-check-label">Important</span>
                </label>
                <label class="form-check form-switch">
                  <input v-model="modal.form.repeater" class="form-check-input" type="checkbox" />
                  <span class="form-check-label">Router/repeater</span>
                </label>
                <label class="form-check form-switch">
                  <input v-model="modal.form.web" class="form-check-input" type="checkbox" />
                  <span class="form-check-label">Web</span>
                </label>
              </div>
            </div>
            <div class="modal-footer justify-content-between">
              <button class="btn btn-outline-danger" type="button" @click="openDeleteHost(modal.form)">
                <i class="ti ti-trash me-1"></i>
                Delete
              </button>
              <div>
                <button class="btn btn-link" type="button" @click="closeModal">Cancel</button>
                <button class="btn btn-primary" type="submit" :disabled="saving">
                  <i class="ti ti-device-floppy me-1"></i>
                  Save
                </button>
              </div>
            </div>
          </form>

          <form v-else-if="modal.type === 'create'" @submit.prevent="submitCreate">
            <div class="modal-body">
              <div v-if="modalError" class="alert alert-danger">{{ modalError }}</div>
              <div class="modal-body-grid">
                <label class="form-label">
                  MAC
                  <input v-model.trim="modal.form.mac" class="form-control font-monospace" name="mac" type="text" />
                </label>
                <label class="form-label">
                  IP
                  <div class="input-group">
                    <span class="input-group-text">{{ network }}.</span>
                    <input v-model.trim="modal.form.ip" class="form-control" name="ip" type="text" />
                  </div>
                </label>
                <label class="form-label field-wide">
                  Password
                  <input v-model="modal.form.password" class="form-control" name="password" type="password" />
                </label>
              </div>
            </div>
            <div class="modal-footer">
              <button class="btn btn-link" type="button" @click="closeModal">Cancel</button>
              <button class="btn btn-primary" type="submit" :disabled="saving">
                <i class="ti ti-plus me-1"></i>
                Create
              </button>
            </div>
          </form>

          <form v-else-if="modal.type === 'category'" @submit.prevent="submitCategory">
            <div class="modal-body">
              <div v-if="modalError" class="alert alert-danger">{{ modalError }}</div>
              <div class="modal-body-grid">
                <label class="form-label">
                  Start IP
                  <div class="input-group">
                    <span class="input-group-text">{{ network }}.</span>
                    <input v-model.trim="modal.form.ip" class="form-control" name="ip" type="text" />
                  </div>
                </label>
                <label class="form-label">
                  Name
                  <input v-model.trim="modal.form.name" class="form-control" name="name" type="text" />
                </label>
                <label class="form-label field-wide">
                  Password
                  <input v-model="modal.form.password" class="form-control" name="password" type="password" />
                </label>
              </div>
            </div>
            <div class="modal-footer">
              <button class="btn btn-link" type="button" @click="closeModal">Cancel</button>
              <button class="btn btn-primary" type="submit" :disabled="saving">
                <i class="ti ti-folder-plus me-1"></i>
                Add
              </button>
            </div>
          </form>

          <form v-else-if="modal.type === 'deleteHost'" @submit.prevent="submitDeleteHost">
            <div class="modal-body">
              <div v-if="modalError" class="alert alert-danger">{{ modalError }}</div>
              <p class="mb-3">{{ modal.name || modal.mac || modal.id }}</p>
              <label class="form-label">
                Password
                <input v-model="modal.password" class="form-control" type="password" />
              </label>
            </div>
            <div class="modal-footer">
              <button class="btn btn-link" type="button" @click="closeModal">Cancel</button>
              <button class="btn btn-danger" type="submit" :disabled="saving">
                <i class="ti ti-trash me-1"></i>
                Delete
              </button>
            </div>
          </form>

          <form v-else-if="modal.type === 'deleteCategory'" @submit.prevent="submitDeleteCategory">
            <div class="modal-body">
              <div v-if="modalError" class="alert alert-danger">{{ modalError }}</div>
              <p class="mb-3">{{ modal.name || modal.ip }}</p>
              <label class="form-label">
                Password
                <input v-model="modal.password" class="form-control" type="password" />
              </label>
            </div>
            <div class="modal-footer">
              <button class="btn btn-link" type="button" @click="closeModal">Cancel</button>
              <button class="btn btn-danger" type="submit" :disabled="saving">
                <i class="ti ti-trash me-1"></i>
                Delete
              </button>
            </div>
          </form>

          <div v-else-if="modal.type === 'history'">
            <div class="modal-body p-0">
              <div v-if="modalError" class="alert alert-danger m-3">{{ modalError }}</div>
              <table class="table table-sm history-table">
                <thead>
                  <tr>
                    <th>MAC</th>
                    <th>Status</th>
                    <th>Date</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-if="modal.rows === null">
                    <td class="text-secondary text-center py-4" colspan="3">Loading</td>
                  </tr>
                  <tr v-for="item in modal.rows || []" :key="item.id" :class="historyRowClass(item)">
                    <td class="font-monospace">{{ formatMac(item.mac) }}</td>
                    <td>
                      <span :class="statusClass(item.status)" :title="statusTitle(item.status)" class="status-pill">
                        <i :class="statusIcon(item.status)"></i>
                      </span>
                    </td>
                    <td>{{ item.date_begin }} for {{ formatDuration(item.duration) }}</td>
                  </tr>
                </tbody>
              </table>
            </div>
            <div class="modal-footer">
              <button class="btn btn-primary" type="button" @click="closeModal">Close</button>
            </div>
          </div>

          <div v-else-if="modal.type === 'scan'">
            <div class="modal-body scan-body">
              <div v-if="modalError" class="alert alert-danger">{{ modalError }}</div>
              <div v-if="modal.loading" class="text-secondary py-4 text-center">Loading</div>

              <template v-else-if="modal.scan">
                <div class="scan-topline">
                  <div>
                    <div class="font-monospace scan-ip">{{ modal.ip }}</div>
                    <div class="text-secondary small">{{ modal.scan.started || modal.scan.args }}</div>
                  </div>
                  <div class="scan-actions">
                    <select
                      v-if="modal.history && modal.history.length > 1"
                      class="form-select form-select-sm scan-history-select"
                      :value="modal.selectedScanId || ''"
                      @change="selectScanHistory($event.target.value)"
                    >
                      <option v-for="scan in modal.history" :key="scan.id" :value="scan.id">
                        {{ scanHistoryLabel(scan) }}
                      </option>
                    </select>
                    <button class="btn btn-outline-secondary btn-sm" type="button" @click="toggleScanRaw">
                      <i class="ti ti-code me-1"></i>
                      XML
                    </button>
                  </div>
                </div>

                <div class="scan-summary">
                  <div class="scan-fact">
                    <span>Status</span>
                    <strong>{{ modal.scan.status || '-' }}</strong>
                  </div>
                  <div class="scan-fact">
                    <span>State</span>
                    <strong>{{ modal.scan.metadata?.state || '-' }}</strong>
                  </div>
                  <div class="scan-fact">
                    <span>Mode</span>
                    <strong>{{ modal.scan.metadata?.mode || '-' }}</strong>
                  </div>
                  <div class="scan-fact">
                    <span>Ports</span>
                    <strong>{{ modal.scan.metadata?.ports_count ?? modal.scan.ports.length }}</strong>
                  </div>
                  <div class="scan-fact">
                    <span>Duration</span>
                    <strong>{{ formatScanDuration(modal.scan.metadata?.duration ?? modal.scan.duration) }}</strong>
                  </div>
                  <div class="scan-fact">
                    <span>Last</span>
                    <strong>{{ formatScanDate(modal.scan.metadata?.date_end || modal.scan.metadata?.date_begin || modal.scan.started) }}</strong>
                  </div>
                </div>

                <div class="scan-section" v-if="modal.scan.addresses.length">
                  <h3>Addresses</h3>
                  <table class="table table-sm scan-table">
                    <tbody>
                      <tr v-for="address in modal.scan.addresses" :key="`${address.type}-${address.addr}`">
                        <td class="scan-type">{{ address.type }}</td>
                        <td class="font-monospace">{{ address.addr }}</td>
                        <td class="text-truncate-cell">{{ address.vendor }}</td>
                      </tr>
                    </tbody>
                  </table>
                </div>

                <div class="scan-section" v-if="modal.scan.hostnames.length">
                  <h3>Hostnames</h3>
                  <table class="table table-sm scan-table">
                    <tbody>
                      <tr v-for="hostname in modal.scan.hostnames" :key="`${hostname.name}-${hostname.type}`">
                        <td>{{ hostname.name }}</td>
                        <td class="scan-type">{{ hostname.type }}</td>
                      </tr>
                    </tbody>
                  </table>
                </div>

                <div class="scan-section" v-if="modal.scan.os.length">
                  <h3>OS</h3>
                  <table class="table table-sm scan-table">
                    <tbody>
                      <tr v-for="os in modal.scan.os" :key="os.name">
                        <td>{{ os.name }}</td>
                        <td class="scan-type">{{ os.accuracy }}%</td>
                      </tr>
                    </tbody>
                  </table>
                </div>

                <div class="scan-section">
                  <h3>Ports</h3>
                  <table v-if="modal.scan.ports.length" class="table table-sm scan-table">
                    <thead>
                      <tr>
                        <th>Port</th>
                        <th>State</th>
                        <th>Service</th>
                        <th>Details</th>
                      </tr>
                    </thead>
                    <tbody>
                      <tr v-for="port in modal.scan.ports" :key="`${port.protocol}-${port.port}`">
                        <td class="font-monospace">{{ port.port }}/{{ port.protocol }}</td>
                        <td>
                          <span :class="scanStateClass(port.state)">{{ port.state || '-' }}</span>
                        </td>
                        <td>{{ port.service || '-' }}</td>
                        <td class="text-truncate-cell" :title="port.details">{{ port.details }}</td>
                      </tr>
                    </tbody>
                  </table>
                  <div v-else class="text-secondary small">No ports found</div>
                </div>

                <pre v-if="modal.showRaw" class="scan-raw">{{ modal.raw }}</pre>
              </template>
            </div>
            <div class="modal-footer">
              <button class="btn btn-primary" type="button" @click="closeModal">Close</button>
            </div>
          </div>

          <div v-else class="modal-body">
            <div class="text-secondary">Loading</div>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed, onMounted, ref, watch } from 'vue';

const network = ref('');
const hosts = ref([]);
const inventoryLoading = ref(false);
const inventoryError = ref('');
const notice = ref('');
const scanning = ref(false);
const scanningHosts = ref(new Set());
const refreshQueued = ref(false);
const refreshPulsing = ref(false);
const modal = ref(null);
const modalError = ref('');
const saving = ref(false);
const darkMode = ref(readCookie('fenping_theme') === 'dark');
const filterDefaults = {
  search: '',
  onlyDown: false,
  onlyImportant: false,
  hideUnknown: false
};
const filters = ref({
  ...filterDefaults,
  ...readJsonStorage('fenping_filters', {})
});
const collapsedCategories = ref(new Set(readJsonStorage('fenping_collapsed_categories', [])));

const refreshLabel = computed(() => {
  if (scanning.value && refreshQueued.value) return 'Queued';
  if (scanning.value) return 'Scanning';
  return 'Ready';
});

const modalTitle = computed(() => {
  if (!modal.value) return '';
  const titles = {
    edit: 'Edit host',
    create: 'Create host',
    category: 'Add category',
    deleteHost: 'Delete host',
    deleteCategory: 'Delete category',
    history: `History ${modal.value.ip || ''}`,
    scan: `Scan ${modal.value.ip || ''}`,
    loading: 'Loading'
  };
  return titles[modal.value.type] || '';
});

const modalDialogClass = computed(() => {
  return modal.value?.type === 'scan' ? 'modal-xl scan-modal-dialog' : 'modal-lg';
});

const hasActiveFilters = computed(() => {
  return filters.value.search.trim() !== ''
    || filters.value.onlyDown
    || filters.value.onlyImportant
    || filters.value.hideUnknown;
});

const categorizedHosts = computed(() => {
  const rows = [];
  let category = null;

  for (const host of hosts.value) {
    if (host.category) {
      category = {
        key: categoryKey(host),
        name: host.category,
        ip: host.category_ip
      };
    }
    rows.push({
      ...host,
      categoryContext: category
    });
  }

  return rows;
});

const visibleHosts = computed(() => categorizedHosts.value.filter(hostMatchesFilters));

const tableRows = computed(() => {
  const rows = [];
  let currentCategoryKey = '';

  for (const host of visibleHosts.value) {
    const category = host.categoryContext;

    if (category && category.key !== currentCategoryKey) {
      currentCategoryKey = category.key;
      rows.push({
        type: 'category',
        key: currentCategoryKey,
        categoryKey: currentCategoryKey,
        name: category.name,
        categoryIp: category.ip,
        collapsed: collapsedCategories.value.has(currentCategoryKey)
      });
    }

    if (currentCategoryKey && collapsedCategories.value.has(currentCategoryKey))
      continue;

    rows.push({
      type: 'host',
      key: `host-${host.id || host.ip || host.mac}`,
      host
    });
  }

  return rows;
});

watch(filters, (value) => {
  writeJsonStorage('fenping_filters', value);
}, { deep: true });

watch(collapsedCategories, (value) => {
  writeJsonStorage('fenping_collapsed_categories', Array.from(value));
});

function categoryKey(host) {
  return `category-${host.category_ip || host.category}`;
}

function toggleCategory(key) {
  const next = new Set(collapsedCategories.value);
  if (next.has(key))
    next.delete(key);
  else
    next.add(key);
  collapsedCategories.value = next;
}

function closeAllCategories() {
  const next = new Set();
  for (const host of hosts.value) {
    if (host.category)
      next.add(categoryKey(host));
  }
  collapsedCategories.value = next;
}

onMounted(() => {
  applyTheme();
  loadInventory();
  refreshScan();
});

function toggleDarkMode() {
  darkMode.value = !darkMode.value;
  writeCookie('fenping_theme', darkMode.value ? 'dark' : 'light');
  applyTheme();
}

function applyTheme() {
  const theme = darkMode.value ? 'dark' : 'light';
  document.documentElement.dataset.bsTheme = theme;
  document.documentElement.style.colorScheme = theme;
}

function readCookie(name) {
  const prefix = `${name}=`;
  const match = document.cookie
    .split(';')
    .map((part) => part.trim())
    .find((part) => part.startsWith(prefix));
  return match ? match.slice(prefix.length) : '';
}

function writeCookie(name, value) {
  const maxAge = 60 * 60 * 24 * 365;
  document.cookie = `${name}=${value}; Max-Age=${maxAge}; Path=/; SameSite=Lax`;
}

function readJsonStorage(name, fallback) {
  try {
    const value = window.localStorage.getItem(name);
    return value ? JSON.parse(value) : fallback;
  } catch {
    return fallback;
  }
}

function writeJsonStorage(name, value) {
  try {
    window.localStorage.setItem(name, JSON.stringify(value));
  } catch {
    // Ignore storage failures; filters still work for the current page.
  }
}

function resetFilters() {
  filters.value = { ...filterDefaults };
}

function hostMatchesFilters(host) {
  if (filters.value.onlyDown && host.status === 'Up')
    return false;

  if (filters.value.onlyImportant && !toFlag(host.important))
    return false;

  if (filters.value.hideUnknown && !host.id)
    return false;

  const query = filters.value.search.trim().toLowerCase();
  if (query === '')
    return true;

  return [
    host.name,
    host.ip,
    host.mac,
    host.vendor,
    host.status,
    host.scan?.status,
    host.scan?.state,
    host.scan?.mode
  ].some((value) => String(value || '').toLowerCase().includes(query));
}

async function apiJson(path, options = {}) {
  const headers = {
    Accept: 'application/json',
    ...(options.headers || {})
  };

  if (options.body && !headers['Content-Type']) {
    headers['Content-Type'] = 'application/json';
  }

  const response = await fetch(path, {
    ...options,
    headers
  });
  const text = await response.text();
  let payload = null;

  if (text !== '') {
    try {
      payload = JSON.parse(text);
    } catch {
      payload = text;
    }
  }

  if (!response.ok) {
    const message = payload && typeof payload === 'object' && payload.error ? payload.error : response.statusText;
    throw new Error(message || `HTTP ${response.status}`);
  }

  return payload;
}

async function loadInventory() {
  inventoryLoading.value = true;
  inventoryError.value = '';

  try {
    const data = await apiJson('/api/inventory');
    network.value = data.network || '';
    hosts.value = data.hosts || [];
  } catch (error) {
    inventoryError.value = error.message;
  } finally {
    inventoryLoading.value = false;
  }
}

function requestRefresh() {
  pulseRefresh();
  if (scanning.value) {
    refreshQueued.value = true;
    return;
  }
  refreshScan();
}

async function refreshScan() {
  scanning.value = true;
  inventoryError.value = '';

  try {
    await apiJson('/api/ping/refresh', { method: 'POST' });
    await loadInventory();
  } catch (error) {
    inventoryError.value = error.message;
  } finally {
    scanning.value = false;
    if (refreshQueued.value) {
      refreshQueued.value = false;
      refreshScan();
    }
  }
}

function pulseRefresh() {
  refreshPulsing.value = false;
  requestAnimationFrame(() => {
    refreshPulsing.value = true;
    window.setTimeout(() => {
      refreshPulsing.value = false;
    }, 350);
  });
}

function scanUrl(ip, scanId = null) {
  if (scanId)
    return `/api/scans/${encodeURIComponent(ip)}/${encodeURIComponent(scanId)}.xml`;
  return `/api/scans/${encodeURIComponent(ip)}.xml`;
}

function scanJsonUrl(ip, scanId = null) {
  if (scanId)
    return `/api/scans/${encodeURIComponent(ip)}/${encodeURIComponent(scanId)}`;
  return `/api/scans/${encodeURIComponent(ip)}`;
}

function scanHistoryUrl(ip) {
  return `/api/scans/${encodeURIComponent(ip)}/history`;
}

function scanStatusUrl(ip) {
  return `/api/scans/${encodeURIComponent(ip)}/status`;
}

function hostScanKey(host) {
  return String(host?.ip || host?.id || host?.mac || '');
}

function isHostScanning(host) {
  const key = hostScanKey(host);
  return key !== '' && scanningHosts.value.has(key);
}

function isScanRunning(host) {
  return isHostScanning(host) || host?.scan?.state === 'running';
}

function scanActionClass(host) {
  return {
    'btn-outline-primary': isScanRunning(host),
    'btn-outline-danger': host?.scan?.state === 'failed',
    'btn-outline-secondary': !isScanRunning(host) && host?.scan?.state !== 'failed',
    'is-spinning': isScanRunning(host)
  };
}

function scanButtonTitle(host) {
  if (isScanRunning(host))
    return 'Scanning';
  if (host?.scan?.state === 'failed')
    return `Scan failed${host.scan.error ? `: ${host.scan.error}` : ''}`;
  if (host?.scan?.date_end)
    return `Quick scan, last ${host.scan.date_end}`;
  return 'Quick scan';
}

function setHostScanning(host, value) {
  const key = hostScanKey(host);
  if (key === '') return;

  const next = new Set(scanningHosts.value);
  if (value)
    next.add(key);
  else
    next.delete(key);
  scanningHosts.value = next;
}

async function quickScanHost(host) {
  if (!host?.ip || isHostScanning(host)) return;
  clearMessages();
  setHostScanning(host, true);
  pollScanStatus(host);

  try {
    const result = await apiJson(`/api/scans/${encodeURIComponent(host.ip)}/quick`, { method: 'POST' });
    if (result?.metadata)
      updateHostScan(host.ip, result.metadata, result.saved);
    notice.value = result && result.saved ? 'Scan saved' : 'Scan complete';
    await loadInventory();
  } catch (error) {
    inventoryError.value = error.message;
  } finally {
    setHostScanning(host, false);
  }
}

function pollScanStatus(host) {
  const key = hostScanKey(host);
  const ip = host.ip;

  window.setTimeout(async function poll() {
    if (!scanningHosts.value.has(key))
      return;

    try {
      const metadata = await apiJson(scanStatusUrl(ip));
      if (metadata && metadata.state !== 'none')
        updateHostScan(ip, metadata, false);
    } catch {
      // The POST request will surface the useful error; polling can be quiet.
    }

    window.setTimeout(poll, 1000);
  }, 300);
}

function updateHostScan(ip, metadata, saved) {
  hosts.value = hosts.value.map((host) => {
    if (host.ip !== ip)
      return host;
    return {
      ...host,
      scan: metadata,
      xml: saved || metadata?.xml ? ip : host.xml
    };
  });
}

function rowClass(row) {
  if (row.type === 'category') return 'category-row';
  if (row.host.important == 1 && row.host.status !== 'Up') return 'important-down';
  return '';
}

function statusClass(status) {
  if (status === 'Up') return 'status-pill status-up';
  if (status === 'Down') return 'status-pill status-down';
  if (status === 'arp') return 'status-pill status-arp';
  if (status === 'arp-down') return 'status-pill status-arp-down';
  return 'status-pill status-unknown';
}

function statusIcon(status) {
  if (status === 'Up') return 'ti ti-check';
  if (status === 'Down') return 'ti ti-x';
  if (status === 'arp') return 'ti ti-wifi';
  if (status === 'arp-down') return 'ti ti-alert-triangle';
  return 'ti ti-question-mark';
}

function statusTitle(status) {
  if (status === 'Up') return 'host up';
  if (status === 'Down') return 'host down';
  if (status === 'arp') return 'arp up / ip down';
  if (status === 'arp-down') return 'host down, in arp cache';
  return status || 'unknown';
}

function isRouterRepeater(host) {
  return toFlag(host?.repeater);
}

function historyRowClass(item) {
  if (item.status === 'Up') return '';
  return Number(item.duration || 0) > 180 ? 'history-alert' : 'history-muted';
}

function formatMac(mac) {
  return String(mac || '').toLowerCase();
}

function formatDuration(value) {
  const seconds = Number(value || 0);
  if (seconds < 60) return `${seconds} s`;
  if (seconds < 60 * 60) return `${Math.floor(seconds / 60)} m`;
  if (seconds < 60 * 60 * 24) return `${Math.floor(seconds / (60 * 60))} h`;
  return `${Math.floor(seconds / (60 * 60 * 24))} j`;
}

function formatScanDuration(value) {
  if (value === null || value === undefined || value === '')
    return '-';
  return formatDuration(value);
}

function formatScanDate(value) {
  return value || '-';
}

function toShortIp(ip) {
  const value = String(ip || '');
  const prefix = `${network.value}.`;
  return value.startsWith(prefix) ? value.slice(prefix.length) : value;
}

function toFlag(value) {
  return value === true || value === 1 || value === '1';
}

function hostForm(data) {
  return {
    id: data.id,
    ip: toShortIp(data.ip || ''),
    router: data.router || '',
    mac: formatMac(data.mac),
    name: data.name || '',
    important: toFlag(data.important),
    repeater: toFlag(data.repeater),
    dns: data.dns || '',
    web: toFlag(data.web),
    password: ''
  };
}

function openAddCategory() {
  clearMessages();
  modal.value = {
    type: 'category',
    form: {
      ip: '',
      name: '',
      password: ''
    }
  };
}

function openCreate(host) {
  clearMessages();
  modal.value = {
    type: 'create',
    form: {
      mac: formatMac(host.mac),
      ip: toShortIp(host.ip || ''),
      password: ''
    }
  };
}

async function openEdit(host) {
  if (!host.id) return;
  clearMessages();
  modal.value = { type: 'loading' };

  try {
    const data = await apiJson(`/api/hosts/${encodeURIComponent(host.id)}`);
    if (!data) throw new Error('Host not found');
    modal.value = {
      type: 'edit',
      form: hostForm({ ...host, ...data })
    };
  } catch (error) {
    modal.value = null;
    inventoryError.value = error.message;
  }
}

function openDeleteHost(form) {
  clearMessages();
  modal.value = {
    type: 'deleteHost',
    id: form.id,
    name: form.name,
    mac: form.mac,
    password: ''
  };
}

function openDeleteCategory(row) {
  clearMessages();
  modal.value = {
    type: 'deleteCategory',
    name: row.name,
    ip: row.categoryIp,
    password: ''
  };
}

async function openHistory(ip) {
  if (!ip) return;
  clearMessages();
  modal.value = {
    type: 'history',
    ip,
    rows: null
  };

  try {
    const rows = await apiJson(`/api/history/${encodeURIComponent(ip)}`);
    if (modal.value && modal.value.type === 'history' && modal.value.ip === ip) {
      modal.value.rows = rows || [];
    }
  } catch (error) {
    modalError.value = error.message;
  }
}

async function openScan(ip) {
  if (!ip) return;
  clearMessages();
  modal.value = {
    type: 'scan',
    ip,
    loading: true,
    scan: null,
    raw: '',
    showRaw: false,
    history: null,
    selectedScanId: null
  };

  try {
    const [scan, history] = await Promise.all([
      apiJson(scanJsonUrl(ip)),
      apiJson(scanHistoryUrl(ip))
    ]);
    if (modal.value && modal.value.type === 'scan' && modal.value.ip === ip) {
      modal.value.loading = false;
      modal.value.raw = '';
      modal.value.scan = scan;
      modal.value.history = history || [];
      modal.value.selectedScanId = scan.metadata?.id || null;
    }
  } catch (error) {
    if (modal.value && modal.value.type === 'scan' && modal.value.ip === ip) {
      modal.value.loading = false;
      modalError.value = error.message;
    }
  }
}

async function selectScanHistory(scanId) {
  if (!modal.value || modal.value.type !== 'scan')
    return;

  const id = Number(scanId || 0);
  if (!id)
    return;

  modal.value.loading = true;
  modal.value.raw = '';
  modal.value.showRaw = false;
  modalError.value = '';

  try {
    const scan = await apiJson(scanJsonUrl(modal.value.ip, id));
    if (modal.value && modal.value.type === 'scan') {
      modal.value.scan = scan;
      modal.value.selectedScanId = scan.metadata?.id || id;
    }
  } catch (error) {
    modalError.value = error.message;
  } finally {
    if (modal.value && modal.value.type === 'scan')
      modal.value.loading = false;
  }
}

async function apiText(path, options = {}) {
  const response = await fetch(path, {
    ...options,
    headers: {
      Accept: 'application/xml,text/plain,application/json',
      ...(options.headers || {})
    }
  });
  const text = await response.text();

  if (!response.ok) {
    let message = response.statusText;
    try {
      const payload = JSON.parse(text);
      if (payload && payload.error)
        message = payload.error;
    } catch {
      if (text.trim() !== '')
        message = text.trim();
    }
    throw new Error(message || `HTTP ${response.status}`);
  }

  return text;
}

function scanStateClass(state) {
  if (state === 'open') return 'scan-state scan-state-open';
  if (state === 'closed') return 'scan-state scan-state-closed';
  if (state === 'filtered') return 'scan-state scan-state-filtered';
  return 'scan-state';
}

async function toggleScanRaw() {
  if (!modal.value || modal.value.type !== 'scan')
    return;

  if (modal.value.showRaw) {
    modal.value.showRaw = false;
    return;
  }

  if (modal.value.raw === '') {
    try {
      modal.value.raw = await apiText(scanUrl(modal.value.ip, modal.value.selectedScanId));
    } catch (error) {
      modalError.value = error.message;
      return;
    }
  }

  modal.value.showRaw = true;
}

function scanHistoryLabel(scan) {
  const date = scan.date_end || scan.date_begin || '';
  const status = scan.status || scan.state || '-';
  const ports = Number(scan.ports_count || 0);
  return `${date} ${scan.mode} ${status} ${ports}p`;
}

async function submitCreate() {
  await saveModal(async () => {
    const form = modal.value.form;
    const result = await apiJson('/api/hosts', {
      method: 'POST',
      body: JSON.stringify({
        mac: form.mac,
        ip: form.ip,
        password: form.password
      })
    });
    notice.value = 'Created';
    await loadInventory();
    if (result && result.id) {
      await openEdit({ id: result.id });
    } else {
      closeModal();
    }
  });
}

async function submitEdit() {
  await saveModal(async () => {
    const form = modal.value.form;
    await apiJson(`/api/hosts/${encodeURIComponent(form.id)}`, {
      method: 'PUT',
      body: JSON.stringify({
        ip: form.ip,
        router: form.router,
        mac: form.mac,
        name: form.name,
        important: form.important ? 1 : null,
        repeater: form.repeater ? 1 : null,
        dns: form.dns,
        web: form.web ? 1 : null,
        password: form.password
      })
    });
    notice.value = 'Saved';
    closeModal();
    await loadInventory();
  });
}

async function submitDeleteHost() {
  await saveModal(async () => {
    await apiJson(`/api/hosts/${encodeURIComponent(modal.value.id)}`, {
      method: 'DELETE',
      body: JSON.stringify({ password: modal.value.password })
    });
    notice.value = 'Deleted';
    closeModal();
    await loadInventory();
  });
}

async function submitCategory() {
  await saveModal(async () => {
    const form = modal.value.form;
    await apiJson('/api/categories', {
      method: 'POST',
      body: JSON.stringify({
        ip: form.ip,
        name: form.name,
        password: form.password
      })
    });
    notice.value = 'Category added';
    closeModal();
    await loadInventory();
  });
}

async function submitDeleteCategory() {
  await saveModal(async () => {
    await apiJson('/api/categories', {
      method: 'DELETE',
      body: JSON.stringify({
        ip: modal.value.ip,
        password: modal.value.password
      })
    });
    notice.value = 'Category deleted';
    closeModal();
    await loadInventory();
  });
}

async function saveModal(action) {
  saving.value = true;
  modalError.value = '';

  try {
    await action();
  } catch (error) {
    modalError.value = error.message;
  } finally {
    saving.value = false;
  }
}

function closeModal() {
  modal.value = null;
  modalError.value = '';
}

function clearMessages() {
  modalError.value = '';
  inventoryError.value = '';
  notice.value = '';
}
</script>
