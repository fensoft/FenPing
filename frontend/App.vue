<template>
  <div class="app-shell">
    <header class="app-header py-2">
      <div class="container-xl d-flex align-items-center justify-content-between gap-2">
        <div>
          <h1 class="app-title">FenPing</h1>
          <div class="text-secondary small">{{ network || 'Network' }}</div>
        </div>
        <div class="toolbar">
          <button
            class="btn btn-outline-secondary icon-btn"
            :class="{ active: isInventoryPage || isHostPage }"
            type="button"
            title="Inventory"
            @click="navigate(appRoutes.inventory)"
          >
            <i class="ti ti-list-details"></i>
          </button>
          <button
            class="btn btn-outline-secondary icon-btn"
            :class="{ active: isNotifyPage }"
            type="button"
            title="Notify"
            @click="navigate(appRoutes.notify)"
          >
            <i class="ti ti-bell"></i>
          </button>
          <button
            class="btn btn-outline-secondary icon-btn"
            :class="{ active: isScansPage }"
            type="button"
            title="Scans"
            @click="navigate(appRoutes.scans)"
          >
            <i class="ti ti-radar"></i>
          </button>
          <button
            class="btn btn-outline-secondary icon-btn"
            :class="{ active: isNetbootPage }"
            type="button"
            title="Netboot images"
            @click="navigate(appRoutes.netboot)"
          >
            <i class="ti ti-server"></i>
          </button>
          <span class="badge auth-badge" :class="isAuthenticated ? 'bg-green-lt text-green' : 'bg-secondary-lt text-secondary'">
            {{ isAuthenticated ? 'Admin' : 'Guest' }}
          </span>
          <button
            class="btn btn-outline-primary auth-button"
            type="button"
            :disabled="authLoading"
            :title="isAuthenticated ? 'Logout' : 'Login'"
            @click="isAuthenticated ? logout() : openLogin()"
          >
            <i :class="isAuthenticated ? 'ti ti-logout' : 'ti ti-login'"></i>
            <span class="d-none d-sm-inline ms-1">{{ isAuthenticated ? 'Logout' : 'Login' }}</span>
          </button>
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
            :class="{ 'is-spinning': scanning || notifyLoading || scanQueueLoading || hostDetailLoading || netbootLoading, 'is-pulsing': refreshPulsing }"
            type="button"
            :title="refreshTitle"
            :disabled="refreshDisabled"
            @click="requestRefresh"
          >
            <i class="ti ti-refresh"></i>
          </button>
          <span class="text-secondary small">{{ refreshLabel }}</span>
        </div>
      </div>
    </header>

    <main class="container-xl py-3">
      <div v-if="inventoryError" class="alert alert-danger mb-3" role="alert">{{ inventoryError }}</div>
      <div v-if="notice" class="alert alert-success mb-3" role="alert">{{ notice }}</div>

      <template v-if="isNotifyPage">
        <div v-if="notifyError" class="alert alert-danger mb-3" role="alert">{{ notifyError }}</div>

        <div class="notify-header">
          <div>
            <h2>Notify</h2>
            <div class="text-secondary small">Last {{ notify.hours || 24 }}h of status changes</div>
          </div>
          <button class="btn btn-outline-secondary btn-sm" type="button" :disabled="notifyLoading" @click="loadNotify">
            <i class="ti ti-refresh me-1" :class="{ 'is-spinning': notifyLoading }"></i>
            Refresh
          </button>
        </div>

        <div class="notify-summary">
          <div class="notify-summary-item">
            <span>Total</span>
            <strong>{{ notifySummary.total || 0 }}</strong>
          </div>
          <div class="notify-summary-item">
            <span>Hosts</span>
            <strong>{{ notifySummary.hosts || 0 }}</strong>
          </div>
          <div v-for="item in notifyStatusCounts" :key="item.status" class="notify-summary-item">
            <span>{{ item.status || 'Unknown' }}</span>
            <strong>{{ item.count }}</strong>
          </div>
        </div>

        <div class="table-wrap">
          <table class="table table-sm notify-table">
            <thead>
              <tr>
                <th>Time</th>
                <th>Host</th>
                <th>Change</th>
                <th>Duration</th>
              </tr>
            </thead>
            <tbody>
              <tr v-if="notifyLoading && notifyChanges.length === 0">
                <td class="text-secondary text-center py-4" colspan="4">Loading</td>
              </tr>
              <tr v-else-if="!notifyLoading && notifyChanges.length === 0">
                <td class="text-secondary text-center py-4" colspan="4">No changes in the last {{ notify.hours || 24 }}h</td>
              </tr>
              <tr v-for="change in notifyChanges" :key="change.id" :class="{ 'important-down': change.important == 1 && change.status !== 'Up' }">
                <td class="notify-time">
                  <span>{{ formatNotifyDate(change.date_begin) }}</span>
                  <small>{{ formatRelativeAge(change.begin) }}</small>
                </td>
                <td class="notify-host">
                  <button class="btn btn-link btn-sm p-0 notify-host-name" type="button" @click="openHistory(change.ip)">
                    {{ notifyHostName(change) }}
                  </button>
                  <span class="font-monospace">{{ change.ip }}</span>
                  <span class="font-monospace text-secondary">{{ formatMac(change.mac) }}</span>
                  <span v-if="change.vendor" class="notify-vendor" :title="change.vendor">{{ change.vendor }}</span>
                </td>
                <td>
                  <div class="notify-change">
                    <span v-if="change.previous_status" :class="statusClass(change.previous_status)" :title="statusTitle(change.previous_status)">
                      <i :class="statusIcon(change.previous_status)"></i>
                    </span>
                    <span v-else class="status-pill status-unknown" title="new">
                      <i class="ti ti-point"></i>
                    </span>
                    <i class="ti ti-arrow-right text-secondary"></i>
                    <span :class="statusClass(change.status)" :title="statusTitle(change.status)">
                      <i :class="statusIcon(change.status)"></i>
                    </span>
                    <strong>{{ change.status || 'Unknown' }}</strong>
                  </div>
                </td>
                <td class="notify-duration">
                  {{ formatDuration(change.duration) }}
                  <span v-if="change.current == 1" class="badge bg-blue-lt text-blue ms-1">current</span>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </template>

      <template v-else-if="isScansPage">
        <div v-if="scanQueueError" class="alert alert-danger mb-3" role="alert">{{ scanQueueError }}</div>

        <div class="page-header">
          <div>
            <h2>Scans</h2>
            <div class="text-secondary small">Running and recent inventory scans</div>
          </div>
          <button class="btn btn-outline-secondary btn-sm" type="button" :disabled="scanQueueLoading" @click="loadScanQueue">
            <i class="ti ti-refresh me-1" :class="{ 'is-spinning': scanQueueLoading }"></i>
            Refresh
          </button>
        </div>

        <div class="table-wrap">
          <table class="table table-sm scan-queue-table">
            <colgroup>
              <col class="scan-col-state" />
              <col class="scan-col-host" />
              <col class="scan-col-mode" />
              <col class="scan-col-status" />
              <col class="scan-col-ports" />
              <col class="scan-col-started" />
              <col class="scan-col-duration" />
              <col class="scan-col-error" />
              <col class="scan-col-actions" />
            </colgroup>
            <thead>
              <tr>
                <th>State</th>
                <th>Host</th>
                <th>Mode</th>
                <th>Status</th>
                <th>Ports</th>
                <th>Started</th>
                <th>Duration</th>
                <th>Error</th>
                <th class="text-end">Actions</th>
              </tr>
            </thead>
            <tbody>
              <tr v-if="scanQueueLoading && scanQueue.length === 0">
                <td class="text-secondary text-center py-4" colspan="9">Loading</td>
              </tr>
              <tr v-else-if="!scanQueueLoading && scanQueue.length === 0">
                <td class="text-secondary text-center py-4" colspan="9">No scans</td>
              </tr>
              <tr v-for="scan in scanQueue" :key="scan.id" :class="scanQueueRowClass(scan)">
                <td>
                  <span :class="scanRunStateClass(scan.state)">
                    <i :class="scan.state === 'running' ? 'ti ti-loader-2 is-spinning' : scanRunStateIcon(scan.state)"></i>
                    {{ scan.state || '-' }}
                  </span>
                </td>
                <td class="scan-queue-host">
                  <button
                    v-if="scan.host_id"
                    class="btn btn-link btn-sm p-0 scan-queue-host-name"
                    type="button"
                    @click="navigateHostDetail(scan.host_id)"
                  >
                    {{ scanDisplayName(scan) }}
                  </button>
                  <strong v-else>{{ scanDisplayName(scan) }}</strong>
                  <small class="font-monospace">{{ scan.ip }}</small>
                </td>
                <td>{{ scan.mode || '-' }}</td>
                <td>{{ scan.status || '-' }}</td>
                <td>{{ Number(scan.ports_count || 0) }}</td>
                <td class="text-nowrap">{{ formatScanDate(scan.date_begin) }}</td>
                <td class="text-nowrap">{{ formatScanDuration(activeScanDuration(scan)) }}</td>
                <td class="text-truncate-cell" :title="scan.error || ''">{{ scan.error || '-' }}</td>
                <td class="text-end action-cell">
                  <button
                    v-if="isAuthenticated && scan.ip"
                    class="btn btn-outline-secondary btn-sm icon-btn"
                    :class="{ 'is-spinning': isHostScanning(scan) || scan.state === 'running' }"
                    type="button"
                    :disabled="isHostScanning(scan) || scan.state === 'running'"
                    title="Quick scan"
                    @click="quickScanHost(scan)"
                  >
                    <i :class="isHostScanning(scan) || scan.state === 'running' ? 'ti ti-loader-2' : 'ti ti-search'"></i>
                  </button>
                  <button
                    class="btn btn-outline-secondary btn-sm icon-btn"
                    type="button"
                    title="View scan"
                    :disabled="!scan.xml_usable"
                    @click="openScan(scan.ip, scan.id)"
                  >
                    <i class="ti ti-file-search"></i>
                  </button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </template>

      <template v-else-if="isHostPage">
        <div v-if="hostDetailError" class="alert alert-danger mb-3" role="alert">{{ hostDetailError }}</div>

        <div class="page-header">
          <div>
            <button class="btn btn-link btn-sm p-0 mb-1" type="button" @click="navigate(appRoutes.inventory)">
              <i class="ti ti-arrow-left me-1"></i>
              Inventory
            </button>
            <h2>{{ hostDetailTitle }}</h2>
            <div class="text-secondary small font-monospace">{{ hostDetailHost.ip || 'No IP' }}</div>
          </div>
          <div class="page-actions">
            <button
              v-if="hostDetailHost.ip"
              class="btn btn-outline-secondary btn-sm"
              type="button"
              :disabled="!hostDetailLatestScan || !scanHasXml(hostDetailLatestScan)"
              @click="openScan(hostDetailHost.ip, hostDetailLatestScan?.id)"
            >
              <i class="ti ti-file-search me-1"></i>
              View scan
            </button>
            <button
              v-if="isAuthenticated && hostDetailHost.ip"
              class="btn btn-outline-primary btn-sm"
              type="button"
              :disabled="isHostScanning(hostDetailHost)"
              @click="quickScanHost(hostDetailHost)"
            >
              <i :class="isHostScanning(hostDetailHost) ? 'ti ti-loader-2 is-spinning me-1' : 'ti ti-search me-1'"></i>
              Quick scan
            </button>
            <button
              v-if="isAuthenticated && hostDetailHost.id"
              class="btn btn-primary btn-sm"
              type="button"
              @click="openEdit(hostDetailHost)"
            >
              <i class="ti ti-edit me-1"></i>
              Edit
            </button>
          </div>
        </div>

        <div v-if="hostDetailLoading" class="table-wrap detail-empty">
          <div class="text-secondary text-center py-4">Loading</div>
        </div>

        <template v-else-if="hostDetail">
          <div class="detail-summary">
            <div class="detail-fact">
              <span>Status</span>
              <strong>
                <span :class="statusClass(hostDetailHost.status)" class="status-pill">
                  <i :class="statusIcon(hostDetailHost.status)"></i>
                </span>
                {{ hostDetailHost.status || '-' }}
              </strong>
            </div>
            <div class="detail-fact">
              <span>MAC</span>
              <strong class="font-monospace">{{ formatMac(hostDetailHost.mac) || '-' }}</strong>
            </div>
            <div class="detail-fact">
              <span>Vendor</span>
              <strong>{{ hostDetailHost.vendor || '-' }}</strong>
            </div>
            <div class="detail-fact">
              <span>Netboot</span>
              <strong>{{ hostDetailNetbootName }}</strong>
            </div>
          </div>

          <div class="detail-grid">
            <section class="detail-panel">
              <h3>Configuration</h3>
              <dl class="detail-list">
                <div>
                  <dt>Name</dt>
                  <dd>{{ hostDetailHost.name || '-' }}</dd>
                </div>
                <div>
                  <dt>IP</dt>
                  <dd class="font-monospace">{{ hostDetailHost.ip || '-' }}</dd>
                </div>
                <div>
                  <dt>Router</dt>
                  <dd>{{ hostDetailHost.router || '-' }}</dd>
                </div>
                <div>
                  <dt>DNS</dt>
                  <dd>{{ hostDetailHost.dns || '-' }}</dd>
                </div>
                <div>
                  <dt>Flags</dt>
                  <dd>
                    <span v-if="toFlag(hostDetailHost.important)" class="badge bg-red-lt text-red me-1">Important</span>
                    <span v-if="toFlag(hostDetailHost.repeater)" class="badge bg-blue-lt text-blue me-1">Router/repeater</span>
                    <span v-if="toFlag(hostDetailHost.web)" class="badge bg-green-lt text-green me-1">Web</span>
                    <span v-if="!toFlag(hostDetailHost.important) && !toFlag(hostDetailHost.repeater) && !toFlag(hostDetailHost.web)">-</span>
                  </dd>
                </div>
              </dl>
            </section>

            <section class="detail-panel">
              <h3>Latest Scan</h3>
              <dl class="detail-list">
                <div>
                  <dt>State</dt>
                  <dd>{{ hostDetailLatestScan?.state || '-' }}</dd>
                </div>
                <div>
                  <dt>Status</dt>
                  <dd>{{ hostDetailLatestScan?.status || '-' }}</dd>
                </div>
                <div>
                  <dt>Mode</dt>
                  <dd>{{ hostDetailLatestScan?.mode || '-' }}</dd>
                </div>
                <div>
                  <dt>Ports</dt>
                  <dd>{{ hostDetailLatestScan?.ports_count ?? 0 }}</dd>
                </div>
                <div>
                  <dt>Last</dt>
                  <dd>{{ formatScanDate(hostDetailLatestScan?.date_end || hostDetailLatestScan?.date_begin) }}</dd>
                </div>
              </dl>
            </section>
          </div>

          <div class="detail-section">
            <div class="detail-section-heading">
              <h3>History</h3>
              <span class="text-secondary small">{{ hostDetailHistoryRows.length }} rows</span>
            </div>
            <div class="table-wrap">
              <table class="table table-sm detail-table">
                <thead>
                  <tr>
                    <th>Status</th>
                    <th>MAC</th>
                    <th>Started</th>
                    <th>Duration</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-if="hostDetailHistoryRows.length === 0">
                    <td class="text-secondary text-center py-4" colspan="4">No history</td>
                  </tr>
                  <tr v-for="item in hostDetailHistoryRows" :key="item.id" :class="historyRowClass(item)">
                    <td>
                      <span :class="statusClass(item.status)" :title="statusTitle(item.status)" class="status-pill">
                        <i :class="statusIcon(item.status)"></i>
                      </span>
                      {{ item.status || '-' }}
                    </td>
                    <td class="font-monospace">{{ formatMac(item.mac) }}</td>
                    <td>{{ formatServerDate(item.date_begin) }}</td>
                    <td>{{ formatDuration(item.duration) }}</td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>

          <div class="detail-section">
            <div class="detail-section-heading">
              <h3>Scan History</h3>
              <span class="text-secondary small">{{ hostDetailScans.length }} scans</span>
            </div>
            <div class="table-wrap">
              <table class="table table-sm detail-table">
                <thead>
                  <tr>
                    <th>State</th>
                    <th>Mode</th>
                    <th>Status</th>
                    <th>Ports</th>
                    <th>Ended</th>
                    <th class="text-end">Actions</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-if="hostDetailScans.length === 0">
                    <td class="text-secondary text-center py-4" colspan="6">No scans</td>
                  </tr>
                  <tr v-for="scan in hostDetailScans" :key="scan.id">
                    <td>
                      <span :class="scanRunStateClass(scan.state)">
                        <i :class="scan.state === 'running' ? 'ti ti-loader-2 is-spinning' : scanRunStateIcon(scan.state)"></i>
                        {{ scan.state || '-' }}
                      </span>
                    </td>
                    <td>{{ scan.mode || '-' }}</td>
                    <td>{{ scan.status || '-' }}</td>
                    <td>{{ Number(scan.ports_count || 0) }}</td>
                    <td>{{ formatScanDate(scan.date_end || scan.date_begin) }}</td>
                    <td class="text-end">
                      <button
                        class="btn btn-outline-secondary btn-sm icon-btn"
                        type="button"
                        title="View scan"
                        :disabled="!scanHasXml(scan)"
                        @click="openScan(hostDetailHost.ip, scan.id)"
                      >
                        <i class="ti ti-file-search"></i>
                      </button>
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>
        </template>
      </template>

      <template v-else-if="isNetbootPage">
        <div v-if="netbootError" class="alert alert-danger mb-3" role="alert">{{ netbootError }}</div>

        <div class="netboot-header">
          <div>
            <h2>Netboot Images</h2>
            <div class="text-secondary small">Boot files served from /netboot</div>
          </div>
          <button class="btn btn-outline-secondary btn-sm" type="button" :disabled="netbootLoading" @click="loadNetbootImages">
            <i class="ti ti-refresh me-1" :class="{ 'is-spinning': netbootLoading }"></i>
            Refresh
          </button>
        </div>

        <div v-if="!isAuthenticated" class="alert alert-info" role="alert">
          Guest mode is read only. You can browse and download images.
        </div>

        <form v-if="isAuthenticated" class="netboot-upload" @submit.prevent="uploadNetbootImage">
          <label class="form-label netboot-name-field">
            Name
            <input v-model.trim="netbootUpload.name" class="form-control form-control-sm" type="text" placeholder="Optional display name" />
          </label>
          <label class="form-label netboot-file-field">
            File
            <input ref="netbootFileInput" class="form-control form-control-sm" type="file" @change="onNetbootFile" />
          </label>
          <button class="btn btn-primary btn-sm" type="submit" :disabled="netbootUploading">
            <i class="ti ti-upload me-1" :class="{ 'is-spinning': netbootUploading }"></i>
            Upload
          </button>
        </form>

        <div class="table-wrap">
          <table class="table table-sm netboot-table">
            <thead>
              <tr>
                <th>Name</th>
                <th>File</th>
                <th>Size</th>
                <th>Hosts</th>
                <th>Created</th>
                <th v-if="isAuthenticated" class="text-end">Actions</th>
              </tr>
            </thead>
            <tbody>
              <tr v-if="netbootLoading && netbootImages.length === 0">
                <td class="text-secondary text-center py-4" :colspan="isAuthenticated ? 6 : 5">Loading</td>
              </tr>
              <tr v-else-if="!netbootLoading && netbootImages.length === 0">
                <td class="text-secondary text-center py-4" :colspan="isAuthenticated ? 6 : 5">No images</td>
              </tr>
              <tr v-for="image in netbootImages" :key="image.id">
                <td class="text-truncate-cell" :title="image.name">
                  <strong>{{ image.name }}</strong>
                  <small v-if="image.original_name && image.original_name !== image.name" class="text-secondary">{{ image.original_name }}</small>
                </td>
                <td class="text-truncate-cell font-monospace" :title="image.filename">
                  <a :href="image.url" target="_blank" rel="noopener noreferrer">{{ image.filename }}</a>
                </td>
                <td>{{ formatBytes(image.size) }}</td>
                <td>{{ image.hosts || 0 }}</td>
                <td class="text-nowrap">{{ formatServerDate(image.created_at) }}</td>
                <td v-if="isAuthenticated" class="text-end">
                  <button class="btn btn-outline-danger btn-sm icon-btn" type="button" title="Delete image" :disabled="netbootLoading" @click="deleteNetbootImage(image)">
                    <i class="ti ti-trash"></i>
                  </button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </template>

      <div v-else class="table-wrap">
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
          <colgroup>
            <col class="col-status" />
            <col class="col-name" />
            <col class="col-mac" />
            <col class="col-vendor" />
            <col class="col-ip" />
            <col class="col-actions" />
          </colgroup>
          <thead>
            <tr>
              <th scope="col">
                <button
                  class="btn btn-outline-secondary btn-sm icon-btn"
                  type="button"
                  title="Close all categories"
                  @click="closeAllCategories"
                >
                  <i class="ti ti-minus"></i>
                </button>
              </th>
              <th scope="col">Name</th>
              <th scope="col">MAC</th>
              <th scope="col">Vendor</th>
              <th scope="col">IP</th>
              <th scope="col" class="text-end">
                <button
                  v-if="isAuthenticated"
                  class="btn btn-outline-primary btn-sm icon-btn"
                  type="button"
                  title="Add category"
                  @click="openAddCategory"
                >
                  <i class="ti ti-folder-plus"></i>
                </button>
              </th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="inventoryLoading && tableRows.length === 0">
              <td class="text-secondary text-center py-4" colspan="6">Loading</td>
            </tr>
            <tr v-else-if="!inventoryLoading && tableRows.length === 0">
              <td class="text-secondary text-center py-4" colspan="6">No hosts</td>
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
                <td class="category-name" colspan="4">{{ row.name }}</td>
                <td class="text-end category-actions">
                  <button
                    v-if="isAuthenticated && row.categoryIp"
                    class="btn btn-outline-secondary btn-sm icon-btn"
                    type="button"
                    title="Rename category"
                    @click="openRenameCategory(row)"
                  >
                    <i class="ti ti-pencil"></i>
                  </button>
                  <button
                    v-if="isAuthenticated && row.categoryIp"
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
                    <button
                      v-if="showStability(row.host)"
                      :class="stabilityClass(row.host.stability)"
                      type="button"
                      :title="stabilityTitle(row.host.stability)"
                      @click="openHistory(row.host.ip)"
                    >
                      {{ stabilityLabel(row.host.stability) }}
                    </button>
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
                <td class="text-truncate-cell font-monospace" :title="row.host.ip || ''">
                  {{ row.host.ip }}
                </td>
                <td class="text-end action-cell">
                  <button
                    v-if="row.host.id"
                    class="btn btn-outline-secondary btn-sm icon-btn"
                    type="button"
                    title="Host detail"
                    @click="navigateHostDetail(row.host.id)"
                  >
                    <i class="ti ti-info-circle"></i>
                  </button>
                  <button
                    v-if="isAuthenticated && row.host.ip"
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
                    v-if="row.host.xml"
                    class="btn btn-outline-secondary btn-sm icon-btn"
                    type="button"
                    title="View scan"
                    @click="openScan(row.host.ip)"
                  >
                    <i class="ti ti-file-search"></i>
                  </button>
                  <button
                    v-if="isAuthenticated && row.host.id"
                    class="btn btn-outline-secondary btn-sm icon-btn"
                    type="button"
                    title="Edit host"
                    @click="openEdit(row.host)"
                  >
                    <i class="ti ti-edit"></i>
                  </button>
                  <button
                    v-else-if="isAuthenticated && row.host.mac"
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

          <form v-if="modal.type === 'login'" @submit.prevent="submitLogin">
            <div class="modal-body">
              <div v-if="modalError" class="alert alert-danger">{{ modalError }}</div>
              <label class="form-label">
                Password
                <input v-model="modal.password" class="form-control" type="password" autocomplete="current-password" autofocus />
              </label>
            </div>
            <div class="modal-footer">
              <button class="btn btn-link" type="button" @click="closeModal">Cancel</button>
              <button class="btn btn-primary" type="submit" :disabled="saving">
                <i class="ti ti-login me-1"></i>
                Login
              </button>
            </div>
          </form>

          <form v-else-if="modal.type === 'edit'" @submit.prevent="submitEdit">
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
                <label class="form-label field-wide">
                  DNS
                  <input v-model.trim="modal.form.dns" class="form-control" name="dns" type="text" />
                </label>
                <label class="form-label field-wide">
                  Netboot image
                  <select v-model="modal.form.netboot_image_id" class="form-select" name="netboot_image_id">
                    <option value="">None</option>
                    <option v-for="image in netbootImages" :key="image.id" :value="String(image.id)">
                      {{ image.name }} ({{ image.filename }})
                    </option>
                  </select>
                </label>
                <div class="modal-switch-grid field-wide">
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

          <form v-else-if="modal.type === 'renameCategory'" @submit.prevent="submitRenameCategory">
            <div class="modal-body">
              <div v-if="modalError" class="alert alert-danger">{{ modalError }}</div>
              <div class="modal-body-grid">
                <label class="form-label">
                  Start IP
                  <input :value="modal.ip" class="form-control font-monospace" name="ip" type="text" disabled />
                </label>
                <label class="form-label">
                  Name
                  <input v-model.trim="modal.form.name" class="form-control" name="name" type="text" />
                </label>
              </div>
            </div>
            <div class="modal-footer">
              <button class="btn btn-link" type="button" @click="closeModal">Cancel</button>
              <button class="btn btn-primary" type="submit" :disabled="saving">
                <i class="ti ti-device-floppy me-1"></i>
                Save
              </button>
            </div>
          </form>

          <form v-else-if="modal.type === 'deleteHost'" @submit.prevent="submitDeleteHost">
            <div class="modal-body">
              <div v-if="modalError" class="alert alert-danger">{{ modalError }}</div>
              <p class="mb-3">{{ modal.name || modal.mac || modal.id }}</p>
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
              <div v-if="modal.summary" class="history-summary">
                <div class="history-summary-item">
                  <span>Uptime</span>
                  <strong>{{ formatPercent(modal.summary.uptime_percent) }}</strong>
                </div>
                <div class="history-summary-item">
                  <span>Changes</span>
                  <strong>{{ modal.summary.transitions }}x</strong>
                </div>
                <div class="history-summary-item">
                  <span>Longest Down</span>
                  <strong>{{ formatDuration(modal.summary.longest_down_seconds) }}</strong>
                </div>
                <div class="history-summary-item">
                  <span>Current</span>
                  <strong>{{ modal.summary.current_status || '-' }} {{ formatDuration(modal.summary.current_seconds) }}</strong>
                </div>
              </div>
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
                    <td>{{ formatServerDate(item.date_begin) }} for {{ formatDuration(item.duration) }}</td>
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
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';

const network = ref('');
const hosts = ref([]);
const inventoryLoading = ref(false);
const inventoryError = ref('');
const notify = ref({ hours: 24, summary: {}, changes: [] });
const notifyLoading = ref(false);
const notifyError = ref('');
const netbootImages = ref([]);
const netbootLoading = ref(false);
const netbootUploading = ref(false);
const netbootError = ref('');
const netbootUpload = ref({ name: '', file: null });
const netbootFileInput = ref(null);
const scanQueue = ref([]);
const scanQueueLoading = ref(false);
const scanQueueError = ref('');
const hostDetail = ref(null);
const hostDetailLoading = ref(false);
const hostDetailError = ref('');
const notice = ref('');
const scanning = ref(false);
const scanningHosts = ref(new Set());
const refreshQueued = ref(false);
const refreshPulsing = ref(false);
const modal = ref(null);
const modalError = ref('');
const saving = ref(false);
const auth = ref({ authenticated: false, configured: false });
const authLoading = ref(false);
const darkMode = ref(readCookie('fenping_theme') === 'dark');
const appRoutes = {
  inventory: '/',
  notify: '/notify',
  scans: '/scans',
  netboot: '/netboot-images'
};
const appRoutePaths = new Set(Object.values(appRoutes));
const hostRoutePattern = /^\/hosts\/(\d+)$/;
const routePath = ref(currentRoutePath());
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

const isAuthenticated = computed(() => Boolean(auth.value?.authenticated));
const isNotifyPage = computed(() => routePath.value === '/notify');
const isScansPage = computed(() => routePath.value === '/scans');
const isNetbootPage = computed(() => routePath.value === '/netboot-images');
const routeHostId = computed(() => {
  const match = routePath.value.match(hostRoutePattern);
  return match ? Number(match[1]) : null;
});
const isHostPage = computed(() => routeHostId.value !== null);
const isInventoryPage = computed(() => routePath.value === appRoutes.inventory);
const notifyChanges = computed(() => notify.value?.changes || []);
const notifySummary = computed(() => notify.value?.summary || {});
const notifyStatusCounts = computed(() => {
  const counts = notifySummary.value.status_counts || {};
  return Object.keys(counts)
    .sort((a, b) => String(a).localeCompare(String(b)))
    .map((status) => ({ status, count: counts[status] }));
});
const hostDetailHost = computed(() => hostDetail.value?.host || {});
const hostDetailLatestScan = computed(() => hostDetail.value?.latest_scan || null);
const hostDetailScans = computed(() => hostDetail.value?.scans || []);
const hostDetailHistoryRows = computed(() => hostDetail.value?.history?.rows || []);
const hostDetailTitle = computed(() => hostDetailHost.value.name || hostDetailHost.value.ip || 'Host detail');
const hostDetailNetbootName = computed(() => {
  const image = hostDetail.value?.netboot_image;
  if (!image)
    return '-';
  return image.name || image.filename || '-';
});

const refreshLabel = computed(() => {
  if (isNotifyPage.value) return notifyLoading.value ? 'Loading' : 'Notify';
  if (isScansPage.value) return scanQueueLoading.value ? 'Loading' : 'Scans';
  if (isHostPage.value) return hostDetailLoading.value ? 'Loading' : 'Device';
  if (isNetbootPage.value) return netbootLoading.value ? 'Loading' : 'Netboot';
  if (!isAuthenticated.value) return 'Read only';
  if (scanning.value && refreshQueued.value) return 'Queued';
  if (scanning.value) return 'Scanning';
  return 'Ready';
});

const refreshTitle = computed(() => {
  if (isNotifyPage.value) return 'Refresh notifications';
  if (isScansPage.value) return 'Refresh scans';
  if (isHostPage.value) return 'Refresh host';
  if (isNetbootPage.value) return 'Refresh netboot images';
  return isAuthenticated.value ? 'Refresh' : 'Login to refresh';
});

const refreshDisabled = computed(() => {
  if (isNotifyPage.value) return false;
  if (isScansPage.value) return false;
  if (isHostPage.value) return false;
  if (isNetbootPage.value) return false;
  return !isAuthenticated.value;
});

const modalTitle = computed(() => {
  if (!modal.value) return '';
  const titles = {
    login: 'Login',
    edit: 'Edit host',
    create: 'Create host',
    category: 'Add category',
    renameCategory: 'Rename category',
    deleteHost: 'Delete host',
    deleteCategory: 'Delete category',
    history: `History ${modal.value.ip || ''}`,
    scan: `Scan ${modal.value.ip || ''}`,
    loading: 'Loading'
  };
  return titles[modal.value.type] || '';
});

const modalDialogClass = computed(() => {
  if (modal.value?.type === 'login') return 'modal-sm';
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

onMounted(async () => {
  applyTheme();
  window.addEventListener('popstate', handleRouteChange);
  await loadSession();
  await loadCurrentView({ scan: true });
});

onUnmounted(() => {
  window.removeEventListener('popstate', handleRouteChange);
});

function currentRoutePath() {
  return normalizeRoutePath(window.location.pathname);
}

function navigate(path) {
  const next = normalizeRoutePath(path);
  if (routePath.value === next)
    return;

  window.history.pushState({}, '', next);
  routePath.value = next;
  loadCurrentView();
}

function navigateHostDetail(id) {
  if (!id)
    return;
  navigate(`/hosts/${encodeURIComponent(id)}`);
}

function handleRouteChange() {
  routePath.value = currentRoutePath();
  loadCurrentView();
}

function normalizeRoutePath(path) {
  return appRoutePaths.has(path) || hostRoutePattern.test(path) ? path : appRoutes.inventory;
}

async function loadCurrentView(options = {}) {
  if (isNotifyPage.value) {
    await loadNotify();
    return;
  }

  if (isScansPage.value) {
    await loadScanQueue();
    return;
  }

  if (isHostPage.value) {
    await loadHostDetail(routeHostId.value);
    return;
  }

  if (isNetbootPage.value) {
    await loadNetbootImages();
    return;
  }

  await loadInventory();
  if (options.scan && isAuthenticated.value)
    refreshScan();
}

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

  if (options.body && !(options.body instanceof FormData) && !headers['Content-Type']) {
    headers['Content-Type'] = 'application/json';
  }

  const response = await fetch(path, {
    ...options,
    credentials: 'same-origin',
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

async function loadSession() {
  authLoading.value = true;
  try {
    auth.value = await apiJson('/api/auth/session') || { authenticated: false, configured: false };
  } catch {
    auth.value = { authenticated: false, configured: false };
  } finally {
    authLoading.value = false;
  }
}

function openLogin() {
  clearMessages();
  modal.value = {
    type: 'login',
    password: ''
  };
}

async function submitLogin() {
  await saveModal(async () => {
    auth.value = await apiJson('/api/auth/login', {
      method: 'POST',
      body: JSON.stringify({ password: modal.value.password })
    }) || { authenticated: true, configured: auth.value.configured };
    notice.value = 'Logged in';
    closeModal();
    await loadCurrentView({ scan: true });
  });
}

async function logout() {
  clearMessages();
  authLoading.value = true;
  try {
    auth.value = await apiJson('/api/auth/logout', { method: 'POST' }) || { authenticated: false, configured: auth.value.configured };
    notice.value = 'Logged out';
  } catch (error) {
    inventoryError.value = error.message;
  } finally {
    authLoading.value = false;
  }
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

async function loadNotify() {
  notifyLoading.value = true;
  notifyError.value = '';
  inventoryError.value = '';

  try {
    const data = await apiJson('/api/notify');
    network.value = data.network || network.value || '';
    notify.value = {
      hours: data.hours || 24,
      summary: data.summary || {},
      changes: data.changes || []
    };
  } catch (error) {
    notifyError.value = error.message;
  } finally {
    notifyLoading.value = false;
  }
}

async function loadScanQueue() {
  scanQueueLoading.value = true;
  scanQueueError.value = '';
  inventoryError.value = '';

  try {
    const data = await apiJson('/api/scans');
    scanQueue.value = data?.scans || [];
  } catch (error) {
    scanQueueError.value = error.message;
  } finally {
    scanQueueLoading.value = false;
  }
}

async function loadHostDetail(id) {
  if (!id)
    return;

  hostDetailLoading.value = true;
  hostDetailError.value = '';
  inventoryError.value = '';

  try {
    hostDetail.value = await apiJson(`/api/hosts/${encodeURIComponent(id)}/detail`);
  } catch (error) {
    hostDetail.value = null;
    hostDetailError.value = error.message;
  } finally {
    hostDetailLoading.value = false;
  }
}

async function loadNetbootImages() {
  netbootLoading.value = true;
  netbootError.value = '';
  inventoryError.value = '';

  try {
    const data = await apiJson('/api/netboot/images');
    netbootImages.value = data?.images || [];
  } catch (error) {
    netbootError.value = error.message;
  } finally {
    netbootLoading.value = false;
  }
}

function onNetbootFile(event) {
  netbootUpload.value.file = event.target.files?.[0] || null;
}

async function uploadNetbootImage() {
  if (!isAuthenticated.value) {
    openLogin();
    return;
  }

  if (!netbootUpload.value.file) {
    netbootError.value = 'Choose a file to upload';
    return;
  }

  netbootUploading.value = true;
  netbootError.value = '';
  clearMessages();

  try {
    const body = new FormData();
    body.append('file', netbootUpload.value.file);
    body.append('name', netbootUpload.value.name || '');
    await apiJson('/api/netboot/images', {
      method: 'POST',
      body
    });
    notice.value = 'Image uploaded';
    netbootUpload.value = { name: '', file: null };
    if (netbootFileInput.value)
      netbootFileInput.value.value = '';
    await loadNetbootImages();
  } catch (error) {
    netbootError.value = error.message;
  } finally {
    netbootUploading.value = false;
  }
}

async function deleteNetbootImage(image) {
  if (!isAuthenticated.value) {
    openLogin();
    return;
  }

  const label = image?.name || image?.filename || 'this image';
  if (!window.confirm(`Delete ${label}?`))
    return;

  netbootLoading.value = true;
  netbootError.value = '';
  clearMessages();

  try {
    await apiJson(`/api/netboot/images/${encodeURIComponent(image.id)}`, {
      method: 'DELETE'
    });
    notice.value = 'Image deleted';
    await loadNetbootImages();
  } catch (error) {
    netbootError.value = error.message;
  } finally {
    netbootLoading.value = false;
  }
}

function requestRefresh() {
  pulseRefresh();

  if (isNotifyPage.value) {
    loadNotify();
    return;
  }

  if (isScansPage.value) {
    loadScanQueue();
    return;
  }

  if (isHostPage.value) {
    loadHostDetail(routeHostId.value);
    return;
  }

  if (isNetbootPage.value) {
    loadNetbootImages();
    return;
  }

  if (!isAuthenticated.value) {
    openLogin();
    return;
  }

  if (scanning.value) {
    refreshQueued.value = true;
    return;
  }
  refreshScan();
}

async function refreshScan() {
  if (!isAuthenticated.value)
    return;

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
    return `/api/scans/${encodeURIComponent(ip)}/history/${encodeURIComponent(scanId)}/xml`;
  return `/api/scans/${encodeURIComponent(ip)}/xml`;
}

function scanJsonUrl(ip, scanId = null) {
  if (scanId)
    return `/api/scans/${encodeURIComponent(ip)}/history/${encodeURIComponent(scanId)}`;
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
    'btn-outline-warning': host?.scan?.state === 'timeout',
    'btn-outline-secondary': !isScanRunning(host) && !['failed', 'timeout'].includes(host?.scan?.state),
    'is-spinning': isScanRunning(host)
  };
}

function scanButtonTitle(host) {
  if (isScanRunning(host))
    return 'Scanning';
  if (host?.scan?.state === 'failed')
    return `Scan failed${host.scan.error ? `: ${host.scan.error}` : ''}`;
  if (host?.scan?.state === 'timeout')
    return `Scan timed out${host.scan.error ? `: ${host.scan.error}` : ''}`;
  if (host?.scan?.date_end)
    return `Quick scan, last ${formatServerDate(host.scan.date_end)}`;
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
  if (!isAuthenticated.value) {
    openLogin();
    return;
  }

  if (!host?.ip || isHostScanning(host)) return;
  clearMessages();
  setHostScanning(host, true);
  pollScanStatus(host);

  try {
    const result = await apiJson(`/api/scans/${encodeURIComponent(host.ip)}/quick`, { method: 'POST' });
    if (result?.metadata)
      updateHostScan(host.ip, result.metadata, result.saved);
    notice.value = result && result.saved ? 'Scan saved' : 'Scan complete';
    if (isScansPage.value)
      await loadScanQueue();
    else if (isHostPage.value)
      await loadHostDetail(routeHostId.value);
    else
      await loadInventory();
  } catch (error) {
    inventoryError.value = error.message;
    if (isScansPage.value)
      scanQueueError.value = error.message;
    if (isHostPage.value)
      hostDetailError.value = error.message;
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

function scanHasXml(scan) {
  if (!scan)
    return false;
  if (Object.prototype.hasOwnProperty.call(scan, 'xml_usable'))
    return Boolean(scan.xml_usable);
  return Boolean(scan.xml || scan.xml_url);
}

function scanDisplayName(scan) {
  return scan?.name || scan?.ip || 'Unknown';
}

function scanRunStateClass(state) {
  if (state === 'running') return 'scan-run-state scan-run-running';
  if (state === 'complete') return 'scan-run-state scan-run-complete';
  if (state === 'failed') return 'scan-run-state scan-run-failed';
  if (state === 'timeout') return 'scan-run-state scan-run-timeout';
  if (state === 'cancelled') return 'scan-run-state scan-run-cancelled';
  return 'scan-run-state';
}

function scanRunStateIcon(state) {
  if (state === 'complete') return 'ti ti-check';
  if (state === 'failed') return 'ti ti-alert-triangle';
  if (state === 'timeout') return 'ti ti-clock-exclamation';
  if (state === 'cancelled') return 'ti ti-ban';
  return 'ti ti-point';
}

function scanQueueRowClass(scan) {
  if (scan?.state === 'running') return 'scan-row-running';
  if (scan?.state === 'failed') return 'scan-row-failed';
  if (scan?.state === 'timeout') return 'scan-row-timeout';
  if (scan?.important == 1 && scan?.status !== 'up') return 'important-down';
  return '';
}

function activeScanDuration(scan) {
  if (!scan)
    return null;
  if (scan.duration !== null && scan.duration !== undefined)
    return scan.duration;
  if (!scan.date_begin)
    return null;

  const started = parseServerDate(scan.date_begin);
  if (Number.isNaN(started))
    return null;
  return Math.max(0, Math.floor((Date.now() - started) / 1000));
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

function showStability(host) {
  return Boolean(host?.stability && !host.stability.stable);
}

function stabilityLabel(stability) {
  if (!stability)
    return '';
  return stability.label || formatPercent(stability.uptime_percent);
}

function stabilityClass(stability) {
  const level = stability?.level || 'warn';
  return `stability-badge stability-${level}`;
}

function stabilityTitle(stability) {
  if (!stability)
    return '';
  return [
    `Uptime ${formatPercent(stability.uptime_percent)}`,
    `${Number(stability.transitions || 0)} changes`,
    `Longest down ${formatDuration(stability.longest_down_seconds)}`,
    `Current ${stability.current_status || '-'} ${formatDuration(stability.current_seconds)}`
  ].join(' | ');
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
  if (seconds < 60) return `${seconds}s`;

  const minutes = Math.floor(seconds / 60);
  const hours = Math.floor(minutes / 60);
  const days = Math.floor(hours / 24);
  const parts = [];

  if (days > 0) parts.push(`${days}d`);
  if (hours % 24 > 0) parts.push(`${hours % 24}h`);
  if (minutes % 60 > 0 || parts.length === 0) parts.push(`${minutes % 60}m`);

  return parts.slice(0, 2).join(' ');
}

function formatBytes(value) {
  const bytes = Number(value || 0);
  if (bytes < 1024)
    return `${bytes} B`;

  const units = ['KB', 'MB', 'GB'];
  let size = bytes / 1024;
  let unit = units[0];
  for (let i = 1; i < units.length && size >= 1024; i++) {
    size = size / 1024;
    unit = units[i];
  }

  return `${size >= 10 ? Math.round(size) : size.toFixed(1)} ${unit}`;
}

function formatScanDuration(value) {
  if (value === null || value === undefined || value === '')
    return '-';

  let remaining = Math.max(0, Math.floor(Number(value) || 0));
  const parts = [];
  const units = [
    [86400, 'd'],
    [3600, 'h'],
    [60, 'm'],
    [1, 's']
  ];

  for (const [size, suffix] of units) {
    const amount = Math.floor(remaining / size);
    if (amount > 0 || (size === 1 && parts.length === 0))
      parts.push(`${amount}${suffix}`);
    remaining %= size;
    if (parts.length === 2)
      break;
  }

  return parts.join('');
}

function formatScanDate(value) {
  return formatServerDate(value);
}

function formatNotifyDate(value) {
  return formatServerDate(value);
}

function parseServerDate(value) {
  const text = String(value || '').trim();
  if (text === '')
    return NaN;

  const withoutTimezone = /^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}$/;
  const normalized = withoutTimezone.test(text)
    ? `${text.replace(' ', 'T')}Z`
    : text;
  return Date.parse(normalized);
}

function formatServerDate(value) {
  const text = String(value || '').trim();
  if (text === '')
    return '-';

  const timestamp = parseServerDate(text);
  if (Number.isNaN(timestamp))
    return text;

  const date = new Date(timestamp);
  const pad = (part) => String(part).padStart(2, '0');
  return [
    date.getFullYear(),
    pad(date.getMonth() + 1),
    pad(date.getDate())
  ].join('-') + ' ' + [
    pad(date.getHours()),
    pad(date.getMinutes()),
    pad(date.getSeconds())
  ].join(':');
}

function formatRelativeAge(value) {
  const timestamp = Number(value || 0);
  if (!timestamp)
    return '';
  const seconds = Math.max(0, Math.floor(Date.now() / 1000) - timestamp);
  return `${formatDuration(seconds)} ago`;
}

function formatPercent(value) {
  return `${Math.round(Number(value || 0))}%`;
}

function notifyHostName(change) {
  return change?.name || change?.ip || formatMac(change?.mac) || 'Unknown';
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
    netboot_image_id: data.netboot_image_id ? String(data.netboot_image_id) : ''
  };
}

function openAddCategory() {
  if (!isAuthenticated.value) {
    openLogin();
    return;
  }

  clearMessages();
  modal.value = {
    type: 'category',
    form: {
      ip: '',
      name: ''
    }
  };
}

function openRenameCategory(row) {
  if (!isAuthenticated.value) {
    openLogin();
    return;
  }

  clearMessages();
  modal.value = {
    type: 'renameCategory',
    name: row.name,
    ip: row.categoryIp,
    form: {
      name: row.name || ''
    }
  };
}

function openCreate(host) {
  if (!isAuthenticated.value) {
    openLogin();
    return;
  }

  clearMessages();
  modal.value = {
    type: 'create',
    form: {
      mac: formatMac(host.mac),
      ip: toShortIp(host.ip || '')
    }
  };
}

async function openEdit(host) {
  if (!isAuthenticated.value) {
    openLogin();
    return;
  }

  if (!host.id) return;
  clearMessages();
  modal.value = { type: 'loading' };

  try {
    const [data] = await Promise.all([
      apiJson(`/api/hosts/${encodeURIComponent(host.id)}`),
      loadNetbootImages()
    ]);
    if (!data) throw new Error('Host not found');
    modal.value = {
      type: 'edit',
      form: hostForm({ ...host, ...data })
    };
  } catch (error) {
    modal.value = null;
    if (isHostPage.value)
      hostDetailError.value = error.message;
    else
      inventoryError.value = error.message;
  }
}

function openDeleteHost(form) {
  if (!isAuthenticated.value) {
    openLogin();
    return;
  }

  clearMessages();
  modal.value = {
    type: 'deleteHost',
    id: form.id,
    name: form.name,
    mac: form.mac
  };
}

function openDeleteCategory(row) {
  if (!isAuthenticated.value) {
    openLogin();
    return;
  }

  clearMessages();
  modal.value = {
    type: 'deleteCategory',
    name: row.name,
    ip: row.categoryIp
  };
}

async function openHistory(ip) {
  if (!ip) return;
  clearMessages();
  modal.value = {
    type: 'history',
    ip,
    rows: null,
    summary: null
  };

  try {
    const payload = await apiJson(`/api/history/${encodeURIComponent(ip)}`);
    if (modal.value && modal.value.type === 'history' && modal.value.ip === ip) {
      modal.value.rows = Array.isArray(payload) ? payload : payload?.rows || [];
      modal.value.summary = Array.isArray(payload) ? null : payload?.summary || null;
    }
  } catch (error) {
    modalError.value = error.message;
  }
}

async function openScan(ip, scanId = null) {
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
    selectedScanId: scanId
  };

  try {
    const [scan, history] = await Promise.all([
      apiJson(scanJsonUrl(ip, scanId)),
      apiJson(scanHistoryUrl(ip))
    ]);
    if (modal.value && modal.value.type === 'scan' && modal.value.ip === ip) {
      modal.value.loading = false;
      modal.value.raw = '';
      modal.value.scan = scan;
      modal.value.history = history || [];
      modal.value.selectedScanId = scan.metadata?.id || scanId || null;
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
    credentials: 'same-origin',
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
  const date = formatServerDate(scan.date_end || scan.date_begin || '');
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
        ip: form.ip
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
        netboot_image_id: form.netboot_image_id || null
      })
    });
    notice.value = 'Saved';
    closeModal();
    if (isHostPage.value)
      await loadHostDetail(routeHostId.value);
    else
      await loadInventory();
  });
}

async function submitDeleteHost() {
  await saveModal(async () => {
    await apiJson(`/api/hosts/${encodeURIComponent(modal.value.id)}`, {
      method: 'DELETE'
    });
    notice.value = 'Deleted';
    closeModal();
    if (isHostPage.value)
      navigate(appRoutes.inventory);
    else
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
        name: form.name
      })
    });
    notice.value = 'Category added';
    closeModal();
    await loadInventory();
  });
}

async function submitRenameCategory() {
  await saveModal(async () => {
    await apiJson('/api/categories', {
      method: 'PUT',
      body: JSON.stringify({
        ip: modal.value.ip,
        name: modal.value.form.name
      })
    });
    notice.value = 'Category renamed';
    closeModal();
    await loadInventory();
  });
}

async function submitDeleteCategory() {
  await saveModal(async () => {
    await apiJson('/api/categories', {
      method: 'DELETE',
      body: JSON.stringify({
        ip: modal.value.ip
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
  notifyError.value = '';
  scanQueueError.value = '';
  hostDetailError.value = '';
  netbootError.value = '';
  notice.value = '';
}
</script>
