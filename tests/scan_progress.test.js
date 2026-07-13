import assert from 'node:assert/strict';
import test from 'node:test';
import { scanCanCancel, scanProgressLabel, scanProgressPhaseLabel } from '../frontend/lib/formatters.js';
import { setLocale } from '../frontend/lib/i18n.js';

test('formats running and queued progress consistently', () => {
  setLocale('en');
  assert.equal(
    scanProgressLabel({ state: 'running', progress_phase: 'port_scan', progress_percent: 42 }),
    'Port scan · 42%',
  );
  assert.equal(
    scanProgressLabel({ state: 'queued', progress_phase: 'queued', queue_position: 3 }),
    'Queued · Queue #3',
  );
  assert.equal(
    scanProgressLabel({ state: 'queued', progress_phase: 'waiting_budget', queue_position: null }),
    'Waiting for daily budget',
  );
  assert.equal(scanProgressPhaseLabel('service_detection'), 'Service detection');
  assert.equal(scanProgressPhaseLabel('future_phase'), 'future_phase');
});

test('only cancellable active scans enable the action', () => {
  assert.equal(scanCanCancel({ state: 'queued', cancel_requested: false }), true);
  assert.equal(scanCanCancel({ state: 'running', cancel_requested: false }), true);
  assert.equal(scanCanCancel({ state: 'running', cancel_requested: true }), false);
  assert.equal(scanCanCancel({ state: 'cancelled', cancel_requested: false }), false);
  assert.equal(scanCanCancel({ state: 'complete', cancel_requested: false }), false);
  assert.equal(scanCanCancel(null), false);
});
