import assert from 'node:assert/strict';
import test from 'node:test';
import { inventoryNetworkFallback, inventoryNetworkIsDhcp, inventoryNetworkUrl } from '../frontend/lib/inventoryNetworks.js';

const networks = [
  { cidr: '10.68.69.0/24', dhcp: true, selectable: true },
  { cidr: '192.168.0.0/24', dhcp: false, routed: true, selectable: true },
  { cidr: '172.16.0.0/24', dhcp: false, routed: false, selectable: true }
];

test('builds a network-scoped inventory URL', () => {
  assert.equal(inventoryNetworkUrl(), '/api/inventory');
  assert.equal(inventoryNetworkUrl('192.168.0.0/24'), '/api/inventory?network=192.168.0.0%2F24');
});

test('keeps routed and unrouted configured preferences selectable', () => {
  assert.equal(inventoryNetworkFallback(networks, '192.168.0.0/24', '10.68.69.0/24'), '192.168.0.0/24');
  assert.equal(inventoryNetworkFallback(networks, '172.16.0.0/24', '10.68.69.0/24'), '172.16.0.0/24');
  assert.equal(inventoryNetworkFallback(networks, 'missing', 'missing'), '10.68.69.0/24');
});

test('identifies when DHCP-only actions are available', () => {
  assert.equal(inventoryNetworkIsDhcp('10.68.69.0/24', '10.68.69.0/24'), true);
  assert.equal(inventoryNetworkIsDhcp('192.168.0.0/24', '10.68.69.0/24'), false);
});
