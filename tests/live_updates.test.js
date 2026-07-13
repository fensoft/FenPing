import assert from 'node:assert/strict';
import test from 'node:test';
import { LiveUpdateClient, liveScopesIntersect, parseLiveUpdate } from '../frontend/lib/liveUpdates.js';

class FakeEventSource {
  constructor(url) {
    this.url = url;
    this.listeners = new Map();
    this.closed = false;
  }

  addEventListener(name, callback) {
    const listeners = this.listeners.get(name) || new Set();
    listeners.add(callback);
    this.listeners.set(name, listeners);
  }

  removeEventListener(name, callback) {
    this.listeners.get(name)?.delete(callback);
  }

  dispatch(name, event = {}) {
    for (const callback of this.listeners.get(name) || []) callback(event);
  }

  close() { this.closed = true; }
}

class FakeDocument {
  visibilityState = 'visible';
  listeners = new Set();

  addEventListener(name, callback) {
    if (name === 'visibilitychange') this.listeners.add(callback);
  }

  removeEventListener(name, callback) {
    if (name === 'visibilitychange') this.listeners.delete(callback);
  }

  dispatchVisibility() {
    for (const callback of this.listeners) callback();
  }
}

const settle = () => new Promise((resolve) => setTimeout(resolve, 5));

test('validates live payloads and scope intersections', () => {
  assert.deepEqual([...parseLiveUpdate('{"version":1,"scopes":["status","status"]}')], ['status']);
  assert.deepEqual([...parseLiveUpdate({ version: 1, scopes: ['hosts', 'all'] })], ['all']);
  assert.equal(parseLiveUpdate('{bad'), null);
  assert.equal(parseLiveUpdate({ version: 2, scopes: ['status'] }), null);
  assert.equal(parseLiveUpdate({ version: 1, scopes: ['unknown'] }), null);
  assert.equal(liveScopesIntersect(new Set(['hosts']), new Set(['status'])), false);
  assert.equal(liveScopesIntersect(new Set(['all']), new Set(['status'])), true);
});

test('uses one connection, coalesces scopes, filters subscribers, and cleans up', async () => {
  const sources = [];
  const documentRef = new FakeDocument();
  const client = new LiveUpdateClient({
    eventSourceFactory: (url) => { const source = new FakeEventSource(url); sources.push(source); return source; },
    documentRef,
    debounceMs: 0
  });
  const hostEvents = [];
  const scanEvents = [];
  client.subscribe(['hosts'], (scopes) => hostEvents.push([...scopes].sort()));
  client.subscribe(['scans'], (scopes) => scanEvents.push([...scopes].sort()));

  client.start();
  client.start();
  assert.equal(sources.length, 1);
  assert.equal(sources[0].url, '/api/events');
  sources[0].dispatch('fenping-update', { data: '{"version":1,"scopes":["hosts"]}' });
  sources[0].dispatch('fenping-update', { data: '{"version":1,"scopes":["status","hosts"]}' });
  sources[0].dispatch('fenping-update', { data: '{"version":1,"scopes":["invalid"]}' });
  await settle();
  assert.deepEqual(hostEvents, [['hosts', 'status']]);
  assert.deepEqual(scanEvents, []);

  sources[0].dispatch('fenping-update', { data: '{"version":1,"scopes":["all"]}' });
  await settle();
  assert.deepEqual(hostEvents.at(-1), ['all']);
  assert.deepEqual(scanEvents.at(-1), ['all']);

  client.stop();
  assert.equal(sources[0].closed, true);
  assert.equal(documentRef.listeners.size, 0);
});

test('reconciles after reconnect and when a hidden tab becomes visible', async () => {
  const source = new FakeEventSource('/api/events');
  const documentRef = new FakeDocument();
  const client = new LiveUpdateClient({ eventSourceFactory: () => source, documentRef, debounceMs: 0 });
  const events = [];
  client.subscribe(['hosts'], (scopes) => events.push([...scopes]));
  client.start();

  source.dispatch('open');
  await settle();
  assert.deepEqual(events, []);
  source.dispatch('error');
  source.dispatch('open');
  await settle();
  assert.deepEqual(events, [['all']]);

  documentRef.visibilityState = 'hidden';
  documentRef.dispatchVisibility();
  documentRef.visibilityState = 'visible';
  documentRef.dispatchVisibility();
  await settle();
  assert.deepEqual(events, [['all'], ['all']]);
  client.stop();
});

test('scan waiters resolve on matching events or the fallback timeout', async () => {
  const source = new FakeEventSource('/api/events');
  const client = new LiveUpdateClient({ eventSourceFactory: () => source, documentRef: null, debounceMs: 0 });
  client.start();
  const eventWait = client.waitFor(['scans'], 100);
  source.dispatch('fenping-update', { data: '{"version":1,"scopes":["scans"]}' });
  assert.equal(await eventWait, 'event');
  assert.equal(await client.waitFor(['scans'], 5), 'timeout');
  const stoppedWait = client.waitFor(['scans'], 1000);
  client.stop();
  assert.equal(await stoppedWait, 'stopped');
});
