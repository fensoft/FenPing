import assert from 'node:assert/strict';
import test from 'node:test';
import {
  clampTopologyZoom,
  filterTopology,
  fitTopologyViewport,
  layoutTopology,
  readableTopologyViewport,
  zoomTopologyViewport
} from '../frontend/lib/topology.js';

const topology = {
  networks: [{ cidr: '192.0.2.0/24' }, { cidr: '198.51.100.0/24' }],
  nodes: [
    { id: 'ip:192.0.2.100', type: 'appliance', ip: '192.0.2.100', network: '192.0.2.0/24' },
    { id: 'ip:192.0.2.1', type: 'router', ip: '192.0.2.1', network: '192.0.2.0/24' },
    { id: 'ip:198.51.100.10', type: 'host', ip: '198.51.100.10', network: '198.51.100.0/24' },
    { id: 'network:198.51.100.0/24', type: 'network', network: '198.51.100.0/24' }
  ],
  connections: [
    { id: 'a', from: 'ip:192.0.2.100', to: 'ip:192.0.2.1', networks: ['198.51.100.0/24'] },
    { id: 'b', from: 'ip:192.0.2.1', to: 'ip:198.51.100.10', networks: ['198.51.100.0/24'] }
  ],
  paths: [{ network: '198.51.100.0/24', node_ids: ['ip:192.0.2.100', 'ip:192.0.2.1', 'ip:198.51.100.10'], target_node_id: 'ip:198.51.100.10' }]
};

test('filters a subnet while retaining its shared upstream path', () => {
  const filtered = filterTopology(topology, '198.51.100.0/24');
  assert.deepEqual(filtered.nodes.map((node) => node.id).sort(), [
    'ip:192.0.2.1', 'ip:192.0.2.100', 'ip:198.51.100.10', 'network:198.51.100.0/24'
  ]);
  assert.equal(filtered.connections.length, 2);
});

test('focuses one trace target while retaining its upstream and route evidence', () => {
  const filtered = filterTopology(topology, '', '198.51.100.10');
  assert.deepEqual(filtered.paths.map((path) => path.target_node_id), ['ip:198.51.100.10']);
  assert.deepEqual(filtered.nodes.map((node) => node.id).sort(), [
    'ip:192.0.2.1', 'ip:192.0.2.100', 'ip:198.51.100.10', 'network:198.51.100.0/24'
  ]);
});

test('lays nodes out deterministically by observed depth', () => {
  const first = layoutTopology(topology.nodes, topology.connections, topology.paths);
  const second = layoutTopology(topology.nodes, topology.connections, topology.paths);
  assert.deepEqual(first, second);
  const positions = new Map(first.nodes.map((node) => [node.id, node]));
  assert.ok(positions.get('ip:192.0.2.100').x < positions.get('ip:192.0.2.1').x);
  assert.ok(positions.get('ip:192.0.2.1').x < positions.get('ip:198.51.100.10').x);
  assert.match(first.connections[0].path, /^M /);
});

test('clamps invalid and out-of-range zoom values', () => {
  assert.equal(clampTopologyZoom(0.001), 0.02);
  assert.equal(clampTopologyZoom(8), 4);
  assert.equal(clampTopologyZoom('invalid'), 1);
  assert.equal(clampTopologyZoom(1.25), 1.25);
});

test('keeps a dense graph readable by default and reserves full fit for overview', () => {
  const denseNodes = [topology.nodes[0], topology.nodes[1]];
  const denseConnections = [topology.connections[0]];
  const densePaths = [];
  for (let octet = 1; octet <= 100; octet++) {
    const id = `ip:198.51.100.${octet}`;
    denseNodes.push({ id, type: 'host', ip: `198.51.100.${octet}`, network: '198.51.100.0/24' });
    denseConnections.push({ id: `target-${octet}`, from: 'ip:192.0.2.1', to: id, networks: ['198.51.100.0/24'] });
    densePaths.push({ target_node_id: id, node_ids: ['ip:192.0.2.100', 'ip:192.0.2.1', id] });
  }
  const graph = layoutTopology(denseNodes, denseConnections, densePaths);
  const readable = readableTopologyViewport(graph, 1000, 560);
  const appliance = graph.nodes.find((node) => node.type === 'appliance');
  assert.ok(graph.height > 9000);
  assert.ok(readable.height < graph.height / 10);
  assert.ok(appliance.y >= readable.y && appliance.y + appliance.height <= readable.y + readable.height);

  const fit = fitTopologyViewport(graph, readable.baseWidth, readable.baseHeight);
  assert.ok(fit.scale < 0.1);
  const zoomed = zoomTopologyViewport(fit, 1, graph);
  assert.equal(zoomed.scale, 1);
  assert.equal(zoomed.width, readable.baseWidth);
});
