<template>
  <section class="topology-page">
    <div v-if="error" class="alert alert-danger mb-3" role="alert">{{ error }}</div>
    <div class="page-refresh-header">
      <div><h2>{{ t('Observed topology') }}</h2><div class="text-secondary small">{{ t('Latest retained paths and live route observations') }}</div></div>
      <button class="btn btn-outline-secondary btn-sm" type="button" :disabled="loading" @click="load">
        <AppIcon name="refresh" class="me-1" :class="{ 'is-spinning': loading }" />{{ t('Refresh') }}
      </button>
    </div>

    <div class="alert alert-info topology-disclaimer" role="note">
      <AppIcon name="alert-triangle" />
      <div><strong>{{ t('Observed, not physical') }}</strong><div>{{ t('Connections are traceroute or route-table observations and gateway configurations, not verified physical links.') }}</div></div>
    </div>
    <div v-if="topology.route_observation_status === 'unavailable'" class="alert alert-warning" role="status">
      {{ t('Live route inspection is unavailable. Stored traceroute and configured gateway data are still shown.') }}
    </div>

    <div class="topology-summary" :aria-label="t('Topology summary')">
      <div><span>{{ t('Subnets') }}</span><strong>{{ summary.network_count || 0 }}</strong></div>
      <div><span>{{ t('Trace targets') }}</span><strong>{{ summary.trace_target_count || 0 }}</strong></div>
      <div><span>{{ t('Routers') }}</span><strong>{{ summary.router_count || 0 }}</strong></div>
      <div><span>{{ t('Hosts') }}</span><strong>{{ summary.host_count || 0 }}</strong></div>
      <div><span>{{ t('Without a retained trace') }}</span><strong>{{ summary.untraced_host_count || 0 }}</strong></div>
      <div><span>{{ t('Last observed') }}</span><strong>{{ formatServerDate(summary.last_observed_at) }}</strong></div>
    </div>

    <div class="topology-toolbar">
      <div class="topology-filters">
        <label class="form-label mb-0 topology-filter-label">{{ t('Subnet') }}
          <select v-model="selectedNetwork" class="form-select form-select-sm" :aria-label="t('Topology subnet')">
            <option value="">{{ t('All subnets') }}</option>
            <option v-for="network in topology.networks" :key="network.cidr" :value="network.cidr">{{ network.cidr }}</option>
          </select>
        </label>
        <label class="form-label mb-0 topology-filter-label">{{ t('Trace target') }}
          <select v-model="selectedTarget" class="form-select form-select-sm" :aria-label="t('Topology trace target')">
            <option value="">{{ t('All trace targets') }}</option>
            <option v-for="target in targetOptions" :key="target.ip" :value="target.ip">{{ target.label }}</option>
          </select>
        </label>
      </div>
      <div class="btn-group btn-group-sm" role="group" :aria-label="t('Topology view controls')">
        <button class="btn btn-outline-secondary" type="button" :aria-label="t('Zoom out')" :title="t('Zoom out')" @click="changeZoom(-0.2)"><AppIcon name="minus" /></button>
        <button class="btn btn-outline-secondary topology-zoom-value" type="button" disabled>{{ Math.round(viewport.scale * 100) }}%</button>
        <button class="btn btn-outline-secondary" type="button" :aria-label="t('Zoom in')" :title="t('Zoom in')" @click="changeZoom(0.2)"><AppIcon name="plus" /></button>
        <button class="btn btn-outline-secondary" type="button" @click="fitView">{{ t('Fit') }}</button>
        <button class="btn btn-outline-secondary" type="button" @click="resetView">{{ t('Reset') }}</button>
      </div>
    </div>

    <div class="topology-network-list" :aria-label="t('Configured subnets')">
      <article v-for="network in filtered.networks" :key="network.cidr" class="topology-network-card">
        <div><strong class="font-monospace">{{ network.cidr }}</strong><span v-if="network.dhcp" class="badge bg-blue-lt text-blue">{{ t('DHCP') }}</span><span v-else-if="!network.routed" class="badge bg-yellow-lt text-yellow">{{ t('Not routed') }}</span></div>
        <small v-if="network.docker_network_names?.length" class="text-secondary">Docker: {{ network.docker_network_names.join(' · ') }}</small>
        <small class="text-secondary">{{ t('{count} hosts, {untraced} without a retained trace', { count: network.host_count || 0, untraced: network.untraced_host_count || 0 }) }}</small>
        <small v-if="network.route" class="font-monospace topology-route-summary">{{ routeSummary(network.route) }}</small>
      </article>
    </div>

    <div v-if="!loading && topology.paths.length === 0" class="alert alert-secondary" role="status">
      {{ t('No retained traceroutes are available yet. Standard and deep inventory scans collect path observations.') }}
    </div>
    <div v-if="incompletePaths.length" class="alert alert-warning py-2" role="status">
      {{ t('{count} trace targets were not reached by their latest retained path.', { count: incompletePaths.length }) }}
    </div>
    <div v-if="filtered.paths.length > 24 && !selectedTarget" class="alert alert-secondary py-2 topology-density-hint" role="status">
      <strong>{{ t('Dense topology') }}</strong>
      {{ t('Showing a readable section of {count} paths. Pan to explore, choose a trace target, or use Fit for an overview.', { count: filtered.paths.length }) }}
    </div>

    <div class="topology-workspace">
      <div
        ref="canvas"
        class="topology-canvas"
        :class="{ 'is-dragging': dragging !== null }"
        @pointerdown="startPan"
        @pointermove="movePan"
        @pointerup="endPan"
        @pointercancel="endPan"
        @wheel.prevent="wheelZoom"
      >
        <div v-if="loading && graph.nodes.length === 0" class="topology-loading">{{ t('Loading') }}</div>
        <svg v-else class="topology-svg" :viewBox="viewBox" role="img" :aria-label="t('Observed network topology graph')" preserveAspectRatio="xMinYMin meet">
          <defs><marker id="topology-arrow" markerWidth="8" markerHeight="8" refX="7" refY="4" orient="auto" markerUnits="strokeWidth"><path d="M 0 0 L 8 4 L 0 8 z" /></marker></defs>
          <g class="topology-connections">
            <g v-for="connection in graph.connections" :key="connection.id" :class="['topology-connection', `topology-connection-${connection.kind}`, { selected: selectedId === connection.id }]">
              <path class="topology-connection-hit" :d="connection.path" @pointerdown.stop @click="selectConnection(connection)" />
              <path class="topology-connection-line" :d="connection.path" marker-end="url(#topology-arrow)" />
              <g class="topology-edge-label" :transform="`translate(${connection.labelX} ${connection.labelY})`" @pointerdown.stop @click="selectConnection(connection)">
                <rect x="-34" y="-10" width="68" height="20" rx="10" />
                <text text-anchor="middle" dominant-baseline="central">{{ shortConnectionLabel(connection) }}</text>
              </g>
            </g>
          </g>
          <g class="topology-nodes">
            <g
              v-for="node in graph.nodes"
              :key="node.id"
              :class="['topology-node', `topology-node-${node.type}`, { selected: selectedId === node.id }]"
              :transform="`translate(${node.x} ${node.y})`"
              role="button"
              tabindex="0"
              :aria-label="nodeAriaLabel(node)"
              @pointerdown.stop
              @click="selectNode(node)"
              @keydown.enter.prevent="selectNode(node)"
              @keydown.space.prevent="selectNode(node)"
            >
              <rect :width="node.width" :height="node.height" rx="10" />
              <text class="topology-node-label" x="12" y="24">{{ truncate(node.label, 22) }}</text>
              <text class="topology-node-meta" x="12" y="46">{{ nodeMeta(node) }}</text>
              <circle v-if="node.type === 'host' && node.host?.status" cx="154" cy="13" r="5" :class="statusDotClass(node.host.status)" />
            </g>
          </g>
        </svg>
      </div>

      <aside class="topology-inspector" :aria-label="t('Topology evidence')">
        <template v-if="selectedNode">
          <div class="topology-inspector-heading"><div><span class="text-secondary small">{{ t('Node') }}</span><h3>{{ selectedNode.label }}</h3></div><button class="btn btn-sm btn-ghost-secondary" type="button" :aria-label="t('Close')" @click="selectedId = ''"><AppIcon name="x" /></button></div>
          <dl>
            <div v-if="selectedNode.ip"><dt>IP</dt><dd class="font-monospace">{{ selectedNode.ip }}</dd></div>
            <div v-if="selectedNode.network"><dt>{{ t('Subnet') }}</dt><dd class="font-monospace">{{ selectedNode.network }}</dd></div>
            <div><dt>{{ t('Roles') }}</dt><dd><span v-for="role in selectedNode.roles" :key="role" class="badge bg-secondary-lt text-secondary me-1">{{ roleLabel(role) }}</span></dd></div>
            <div v-if="selectedNode.hostname"><dt>{{ t('Observed hostname') }}</dt><dd>{{ selectedNode.hostname }}</dd></div>
            <div v-if="selectedNode.host?.mac"><dt>MAC</dt><dd class="font-monospace">{{ selectedNode.host.mac }}</dd></div>
            <div v-if="selectedNode.host?.status"><dt>{{ t('Status') }}</dt><dd>{{ t(selectedNode.host.status) }}</dd></div>
            <div v-if="selectedNode.host?.vendor"><dt>{{ t('Vendor') }}</dt><dd>{{ selectedNode.host.vendor }}</dd></div>
            <div v-if="selectedNode.host?.last_seen"><dt>{{ t('Last seen') }}</dt><dd>{{ formatServerDate(selectedNode.host.last_seen) }}</dd></div>
          </dl>
          <button v-if="selectedNode.host?.id" class="btn btn-outline-primary btn-sm" type="button" @click="$emit('host-detail', selectedNode.host.id)">{{ t('Open host details') }}</button>
        </template>
        <template v-else-if="selectedConnection">
          <div class="topology-inspector-heading"><div><span class="text-secondary small">{{ t('Connection evidence') }}</span><h3>{{ t(selectedConnection.label) }}</h3></div><button class="btn btn-sm btn-ghost-secondary" type="button" :aria-label="t('Close')" @click="selectedId = ''"><AppIcon name="x" /></button></div>
          <dl>
            <div><dt>{{ t('From') }}</dt><dd>{{ nodeLabel(selectedConnection.from) }}</dd></div>
            <div><dt>{{ t('To') }}</dt><dd>{{ nodeLabel(selectedConnection.to) }}</dd></div>
            <div><dt>{{ t('Evidence type') }}</dt><dd>{{ connectionKindLabel(selectedConnection.kind) }}</dd></div>
            <div v-if="selectedConnection.observed_at"><dt>{{ t('Observed') }}</dt><dd>{{ formatServerDate(selectedConnection.observed_at) }}</dd></div>
            <div><dt>{{ t('Supporting observations') }}</dt><dd>{{ selectedConnection.observation_count }}</dd></div>
            <div v-if="selectedConnection.missing_hops"><dt>{{ t('Unknown hops') }}</dt><dd>{{ selectedConnection.missing_hops }}</dd></div>
            <div v-if="selectedConnection.targets?.length"><dt>{{ t('Trace targets') }}</dt><dd class="font-monospace">{{ selectedConnection.targets.join(', ') }}</dd></div>
          </dl>
          <div v-for="(evidence, index) in selectedConnection.evidence" :key="index" class="topology-evidence-item">
            <strong>{{ evidenceSourceLabel(evidence.source) }}</strong>
            <span v-if="evidence.destination" class="font-monospace">{{ evidence.destination }}</span>
            <span v-if="evidence.gateway" class="font-monospace">{{ t('via {gateway}', { gateway: evidence.gateway }) }}</span>
            <span v-if="evidence.interface">{{ t('Interface') }}: {{ evidence.interface }}</span>
            <span v-if="evidence.host_name">{{ evidence.host_name }} · {{ evidence.host_ip }}</span>
          </div>
        </template>
        <div v-else class="topology-inspector-empty"><AppIcon name="topology" /><strong>{{ t('Select a node or observation') }}</strong><span>{{ t('Evidence and host details will appear here.') }}</span></div>
      </aside>
    </div>

    <div class="notification-section-heading"><h3>{{ t('Connection evidence') }}</h3><span class="text-secondary small">{{ filtered.connections.length }}</span></div>
    <div class="table-wrap">
      <table class="table table-sm topology-evidence-table">
        <thead><tr><th>{{ t('Type') }}</th><th>{{ t('From') }}</th><th>{{ t('To') }}</th><th>{{ t('Subnet') }}</th><th>{{ t('Observed') }}</th><th>{{ t('Evidence') }}</th></tr></thead>
        <tbody>
          <tr v-if="!loading && filtered.connections.length === 0"><td colspan="6" class="text-secondary text-center py-4">{{ t('No connection observations') }}</td></tr>
          <tr v-for="connection in filtered.connections" :key="connection.id" tabindex="0" @click="selectConnection(connection)" @keydown.enter.prevent="selectConnection(connection)">
            <td><span :class="['topology-evidence-swatch', `topology-evidence-${connection.kind}`]"></span>{{ connectionKindLabel(connection.kind) }}</td>
            <td>{{ nodeLabel(connection.from) }}</td><td>{{ nodeLabel(connection.to) }}</td>
            <td class="font-monospace">{{ connection.networks.join(', ') || '-' }}</td>
            <td class="text-nowrap">{{ formatServerDate(connection.observed_at) }}</td>
            <td>{{ t('{count} supporting observations', { count: connection.observation_count }) }}<span v-if="connection.missing_hops"> · {{ t('{count} unknown hops', { count: connection.missing_hops }) }}</span></td>
          </tr>
        </tbody>
      </table>
    </div>
  </section>
</template>

<script setup>
import { computed, nextTick, onMounted, reactive, ref, watch } from 'vue';
import { apiJson, isAbortError } from '../lib/api.js';
import { formatServerDate } from '../lib/formatters.js';
import { t } from '../lib/i18n.js';
import {
  clampTopologyZoom,
  constrainTopologyViewport,
  filterTopology,
  fitTopologyViewport,
  layoutTopology,
  readableTopologyViewport,
  zoomTopologyViewport
} from '../lib/topology.js';
import { useAbortableTask } from '../composables/useAbortableTask.js';
import { useLiveRefresh } from '../composables/useLiveUpdates.js';
import { usePageController } from '../composables/usePageController.js';

defineOptions({ inheritAttrs: false });
defineEmits(['host-detail']);
const topology = ref({ networks: [], nodes: [], connections: [], paths: [], summary: {}, route_observation_status: 'pending' });
const selectedNetwork = ref('');
const selectedTarget = ref('');
const selectedId = ref('');
const loading = ref(false);
const error = ref('');
const request = useAbortableTask();
const canvas = ref(null);
const viewport = reactive({ x: 0, y: 0, width: 920, height: 560, baseWidth: 920, baseHeight: 560, scale: 1 });
const dragging = ref(null);

const summary = computed(() => topology.value.summary || {});
const filtered = computed(() => filterTopology(topology.value, selectedNetwork.value, selectedTarget.value));
const graph = computed(() => layoutTopology(filtered.value.nodes, filtered.value.connections, filtered.value.paths));
const viewBox = computed(() => `${viewport.x} ${viewport.y} ${viewport.width} ${viewport.height}`);
const selectedNode = computed(() => filtered.value.nodes.find((node) => node.id === selectedId.value) || null);
const selectedConnection = computed(() => filtered.value.connections.find((connection) => connection.id === selectedId.value) || null);
const incompletePaths = computed(() => filtered.value.paths.filter((path) => !path.reached_target));
const nodeMap = computed(() => new Map(topology.value.nodes.map((node) => [node.id, node])));
const targetOptions = computed(() => topology.value.paths
  .filter((path) => !selectedNetwork.value || path.network === selectedNetwork.value)
  .map((path) => {
    const label = nodeMap.value.get(path.target_node_id)?.label || '';
    return { ip: path.target_ip, label: label && label !== path.target_ip ? `${label} · ${path.target_ip}` : path.target_ip };
  })
  .sort((left, right) => left.ip.localeCompare(right.ip, undefined, { numeric: true })));

usePageController({ loading, label: computed(() => t(loading.value ? 'Loading' : 'Observed topology')), title: computed(() => t('Refresh topology')), disabled: false, refresh: load });
useLiveRefresh(['hosts', 'status', 'scans', 'networks'], load);
onMounted(load);
watch(selectedNetwork, () => {
  if (selectedTarget.value && !targetOptions.value.some((target) => target.ip === selectedTarget.value)) selectedTarget.value = '';
  selectedId.value = '';
  nextTick(readableView);
});
watch(selectedTarget, () => { selectedId.value = ''; nextTick(readableView); });
watch(filtered, () => { if (!selectedNode.value && !selectedConnection.value) selectedId.value = ''; });

async function load() {
  const signal = request.nextSignal();
  loading.value = true;
  error.value = '';
  try {
    const data = await apiJson('/api/topology', { signal });
    if (request.isCurrent(signal)) {
      topology.value = data || topology.value;
      await nextTick();
      readableView();
    }
  } catch (loadError) {
    if (!isAbortError(loadError) && request.isCurrent(signal)) error.value = loadError.message;
  } finally {
    if (request.isCurrent(signal)) loading.value = false;
  }
}

function readableView() {
  const rect = canvas.value?.getBoundingClientRect();
  Object.assign(viewport, readableTopologyViewport(graph.value, rect?.width, rect?.height));
}
function fitView() {
  const rect = canvas.value?.getBoundingClientRect();
  const readable = readableTopologyViewport(graph.value, rect?.width, rect?.height);
  Object.assign(viewport, fitTopologyViewport(graph.value, readable.baseWidth, readable.baseHeight));
}
function resetView() {
  const changed = selectedNetwork.value !== '' || selectedTarget.value !== '';
  selectedNetwork.value = '';
  selectedTarget.value = '';
  selectedId.value = '';
  if (!changed) readableView();
}
function changeZoom(delta, clientX = null, clientY = null) {
  const next = clampTopologyZoom(viewport.scale + delta);
  if (next === viewport.scale) return;
  const rect = canvas.value?.getBoundingClientRect();
  const ratioX = rect && clientX !== null ? (clientX - rect.left) / rect.width : 0.5;
  const ratioY = rect && clientY !== null ? (clientY - rect.top) / rect.height : 0.5;
  Object.assign(viewport, zoomTopologyViewport(viewport, next, graph.value, ratioX, ratioY));
}
function wheelZoom(event) { changeZoom(event.deltaY < 0 ? 0.1 : -0.1, event.clientX, event.clientY); }
function startPan(event) {
  if (event.button !== 0) return;
  dragging.value = { id: event.pointerId, x: event.clientX, y: event.clientY, viewX: viewport.x, viewY: viewport.y, width: viewport.width, height: viewport.height };
  event.currentTarget.setPointerCapture(event.pointerId);
}
function movePan(event) {
  if (!dragging.value || dragging.value.id !== event.pointerId) return;
  const rect = canvas.value?.getBoundingClientRect();
  if (!rect) return;
  const next = constrainTopologyViewport({
    ...viewport,
    x: dragging.value.viewX - (event.clientX - dragging.value.x) * (dragging.value.width / rect.width),
    y: dragging.value.viewY - (event.clientY - dragging.value.y) * (dragging.value.height / rect.height)
  }, graph.value);
  Object.assign(viewport, next);
}
function endPan(event) {
  if (dragging.value?.id === event.pointerId) dragging.value = null;
}

function selectNode(node) { selectedId.value = node.id; }
function selectConnection(connection) { selectedId.value = connection.id; }
function nodeLabel(id) { return nodeMap.value.get(id)?.label || id.replace(/^(ip:|network:)/, ''); }
function nodeMeta(node) { return node.type === 'network' ? t('Configured subnet') : node.ip || node.network || ''; }
function nodeAriaLabel(node) { return `${roleLabel(node.type)}: ${node.label}${node.ip ? `, ${node.ip}` : ''}`; }
function truncate(value, length) { const text = String(value || ''); return text.length > length ? `${text.slice(0, length - 1)}…` : text; }
function statusDotClass(status) { return String(status).toLowerCase() === 'up' ? 'is-up' : 'is-down'; }
function roleLabel(role) { return t(({ appliance: 'Appliance', router: 'Router', hop: 'Observed hop', host: 'Host', target: 'Trace target', network: 'Subnet' })[role] || role); }
function connectionKindLabel(kind) { return t(({ traceroute_observation: 'Traceroute observation', route_observation: 'Route-table observation', gateway_configuration: 'Gateway configuration' })[kind] || kind); }
function shortConnectionLabel(connection) { return t(({ traceroute_observation: 'Trace', route_observation: 'Route', gateway_configuration: 'Configured' })[connection.kind] || connection.kind); }
function evidenceSourceLabel(source) { return t(({ traceroute: 'Traceroute', route_table: 'Route table', dhcp_default_router: 'Default DHCP gateway', host_router_override: 'Host gateway override' })[source] || source); }
function routeSummary(route) {
  const via = route.gateway ? t('via {gateway}', { gateway: route.gateway }) : t('direct');
  return `${route.destination} ${via}${route.interface ? ` · ${route.interface}` : ''}`;
}
</script>
