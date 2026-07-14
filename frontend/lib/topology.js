const NODE_WIDTH = 168;
const NODE_HEIGHT = 64;
const COLUMN_GAP = 224;
const ROW_GAP = 98;
const PADDING = 48;

export function clampTopologyZoom(value, minimum = 0.02, maximum = 4) {
  const number = Number(value);
  if (!Number.isFinite(number)) return 1;
  return Math.min(maximum, Math.max(minimum, number));
}

export function filterTopology(topology, network = '', target = '') {
  const nodes = Array.isArray(topology?.nodes) ? topology.nodes : [];
  const connections = Array.isArray(topology?.connections) ? topology.connections : [];
  const paths = Array.isArray(topology?.paths) ? topology.paths : [];
  const networks = Array.isArray(topology?.networks) ? topology.networks : [];
  if (!network && !target) return { nodes, connections, paths, networks };

  const filteredPaths = paths.filter((path) =>
    (!network || path.network === network)
    && (!target || path.target_ip === target || path.target_node_id === `ip:${target}`)
  );
  const selectedNetworks = new Set(network ? [network] : filteredPaths.map((path) => path.network).filter(Boolean));
  const filteredConnections = connections.filter((connection) => {
    const connectionNetworks = Array.isArray(connection.networks) ? connection.networks : [];
    if (network && !connectionNetworks.includes(network)) return false;
    if (!target) return true;
    if (connection.kind !== 'traceroute_observation') {
      return connectionNetworks.some((cidr) => selectedNetworks.has(cidr));
    }
    return Array.isArray(connection.targets) && connection.targets.includes(target);
  });
  const included = new Set([...selectedNetworks].map((cidr) => 'network:' + cidr));
  for (const connection of filteredConnections) {
    included.add(connection.from);
    included.add(connection.to);
  }
  for (const path of filteredPaths) {
    for (const id of path.node_ids || []) included.add(id);
    if (path.target_node_id) included.add(path.target_node_id);
  }
  return {
    nodes: nodes.filter((node) => included.has(node.id)),
    connections: filteredConnections,
    paths: filteredPaths,
    networks: networks.filter((item) => !network || item.cidr === network)
  };
}

export function readableTopologyViewport(graph, canvasWidth = 920, canvasHeight = 560) {
  const renderedWidth = positiveNumber(canvasWidth, 920);
  const renderedHeight = positiveNumber(canvasHeight, 560);
  const baseWidth = Math.max(640, renderedWidth);
  const baseHeight = baseWidth * renderedHeight / renderedWidth;
  const focus = graph?.nodes?.find((node) => node.type === 'appliance') || graph?.nodes?.[0];
  const focusY = focus ? focus.y + (focus.height || NODE_HEIGHT) / 2 : (graph?.height || baseHeight) / 2;
  return constrainTopologyViewport({
    x: 0,
    y: focusY - baseHeight / 2,
    width: baseWidth,
    height: baseHeight,
    baseWidth,
    baseHeight,
    scale: 1
  }, graph);
}

export function fitTopologyViewport(graph, baseWidth = 920, baseHeight = 560) {
  const width = positiveNumber(baseWidth, 920);
  const height = positiveNumber(baseHeight, 560);
  const graphWidth = positiveNumber(graph?.width, width);
  const graphHeight = positiveNumber(graph?.height, height);
  const scale = Math.min(1, width / graphWidth, height / graphHeight);
  return constrainTopologyViewport({
    x: (graphWidth - width / scale) / 2,
    y: (graphHeight - height / scale) / 2,
    width: width / scale,
    height: height / scale,
    baseWidth: width,
    baseHeight: height,
    scale
  }, graph);
}

export function zoomTopologyViewport(viewport, requestedScale, graph, anchorX = 0.5, anchorY = 0.5) {
  const scale = clampTopologyZoom(requestedScale);
  const width = viewport.baseWidth / scale;
  const height = viewport.baseHeight / scale;
  return constrainTopologyViewport({
    ...viewport,
    x: viewport.x + (viewport.width - width) * boundedRatio(anchorX),
    y: viewport.y + (viewport.height - height) * boundedRatio(anchorY),
    width,
    height,
    scale
  }, graph);
}

export function constrainTopologyViewport(viewport, graph) {
  const graphWidth = positiveNumber(graph?.width, viewport.width);
  const graphHeight = positiveNumber(graph?.height, viewport.height);
  return {
    ...viewport,
    x: boundedOrigin(viewport.x, viewport.width, graphWidth),
    y: boundedOrigin(viewport.y, viewport.height, graphHeight)
  };
}

export function layoutTopology(nodes = [], connections = [], paths = []) {
  const nodeById = new Map(nodes.map((node) => [node.id, node]));
  const depths = new Map();
  const appliance = nodes.find((node) => node.type === 'appliance');
  if (appliance) depths.set(appliance.id, 0);

  for (const path of paths) {
    for (const [index, id] of (path.node_ids || []).entries()) {
      if (!nodeById.has(id)) continue;
      depths.set(id, Math.min(depths.get(id) ?? Infinity, index));
    }
    if (path.target_node_id && nodeById.has(path.target_node_id)) {
      const targetDepth = Math.max(1, (path.node_ids || []).length);
      depths.set(path.target_node_id, Math.min(depths.get(path.target_node_id) ?? Infinity, targetDepth));
    }
  }

  for (let pass = 0; pass < nodes.length; pass++) {
    let changed = false;
    for (const connection of connections) {
      if (!nodeById.has(connection.from) || !nodeById.has(connection.to)) continue;
      const fromDepth = depths.get(connection.from);
      if (fromDepth === undefined) continue;
      const candidate = fromDepth + 1;
      if (!depths.has(connection.to) || candidate < depths.get(connection.to)) {
        depths.set(connection.to, candidate);
        changed = true;
      }
    }
    if (!changed) break;
  }

  for (const node of nodes) {
    if (depths.has(node.id)) continue;
    depths.set(node.id, node.type === 'network' ? 1 : node.type === 'router' ? 2 : 3);
  }

  const layers = new Map();
  for (const node of nodes) {
    const depth = depths.get(node.id) || 0;
    if (!layers.has(depth)) layers.set(depth, []);
    layers.get(depth).push(node);
  }
  for (const layer of layers.values()) {
    layer.sort((left, right) => nodeSortKey(left).localeCompare(nodeSortKey(right), undefined, { numeric: true }));
  }

  const maxLayerSize = Math.max(1, ...[...layers.values()].map((layer) => layer.length));
  const maxDepth = Math.max(0, ...layers.keys());
  const height = Math.max(430, PADDING * 2 + (maxLayerSize - 1) * ROW_GAP + NODE_HEIGHT);
  const width = Math.max(920, PADDING * 2 + maxDepth * COLUMN_GAP + NODE_WIDTH);
  const positioned = [];
  const positions = new Map();
  for (const [depth, layer] of [...layers.entries()].sort(([left], [right]) => left - right)) {
    const layerHeight = (layer.length - 1) * ROW_GAP + NODE_HEIGHT;
    const startY = Math.max(PADDING, (height - layerHeight) / 2);
    layer.forEach((node, index) => {
      const positionedNode = {
        ...node,
        x: PADDING + depth * COLUMN_GAP,
        y: startY + index * ROW_GAP,
        width: NODE_WIDTH,
        height: NODE_HEIGHT,
        depth
      };
      positioned.push(positionedNode);
      positions.set(node.id, positionedNode);
    });
  }

  const positionedConnections = connections.flatMap((connection) => {
    const from = positions.get(connection.from);
    const to = positions.get(connection.to);
    if (!from || !to) return [];
    const path = topologyConnectionPath(from, to);
    return [{
      ...connection,
      path,
      labelX: (from.x + from.width + to.x) / 2,
      labelY: (from.y + from.height / 2 + to.y + to.height / 2) / 2
    }];
  });
  return { width, height, nodes: positioned, connections: positionedConnections };
}

export function topologyConnectionPath(from, to) {
  if (from.id === to.id) {
    const x = from.x + from.width;
    const y = from.y + from.height / 2;
    return `M ${x} ${y} C ${x + 48} ${y - 54}, ${x + 48} ${y + 54}, ${x} ${y + 2}`;
  }
  const leftToRight = to.x >= from.x;
  const startX = leftToRight ? from.x + from.width : from.x;
  const endX = leftToRight ? to.x : to.x + to.width;
  const startY = from.y + from.height / 2;
  const endY = to.y + to.height / 2;
  const bend = Math.max(36, Math.abs(endX - startX) / 2);
  const first = startX + (leftToRight ? bend : -bend);
  const second = endX - (leftToRight ? bend : -bend);
  return `M ${startX} ${startY} C ${first} ${startY}, ${second} ${endY}, ${endX} ${endY}`;
}

function nodeSortKey(node) {
  const typeOrder = { appliance: '0', router: '1', hop: '2', host: '3', network: '4' };
  return `${node.network || '~'}|${typeOrder[node.type] || '9'}|${node.ip || node.id}`;
}

function positiveNumber(value, fallback) {
  const number = Number(value);
  return Number.isFinite(number) && number > 0 ? number : fallback;
}

function boundedRatio(value) {
  const number = Number(value);
  return Number.isFinite(number) ? Math.min(1, Math.max(0, number)) : 0.5;
}

function boundedOrigin(value, viewportSize, graphSize) {
  if (viewportSize >= graphSize) return (graphSize - viewportSize) / 2;
  return Math.min(graphSize - viewportSize, Math.max(0, Number(value) || 0));
}
