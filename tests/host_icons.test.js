import assert from 'node:assert/strict';
import test from 'node:test';
import {
  hostIconLabel,
  hostIconName,
  hostIconOptions,
  normalizeHostIcon
} from '../frontend/lib/hostIcons.js';

test('host icon catalog exposes the server allowlist in stable order', () => {
  assert.deepEqual(
    hostIconOptions.map((option) => option.value),
    [
      'desktop', 'laptop', 'mobile', 'printer', 'camera', 'router',
      'server', 'database', 'lightbulb', 'television', 'game-controller', 'home'
    ]
  );
});

test('host icon normalization rejects unknown and non-string values', () => {
  assert.equal(normalizeHostIcon(' printer '), 'printer');
  assert.equal(normalizeHostIcon('unknown'), '');
  assert.equal(normalizeHostIcon(null), '');
  assert.equal(hostIconName('game-controller'), 'device-gamepad');
  assert.equal(hostIconLabel('television'), 'Television');
  assert.equal(hostIconName('unknown'), '');
});
