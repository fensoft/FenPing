export const liveUpdateScopes = Object.freeze([
  'hosts',
  'status',
  'scans',
  'conflicts',
  'leases',
  'netboot',
  'backups',
  'operations',
  'networks',
  'vendors',
  'all'
]);

const allowedScopes = new Set(liveUpdateScopes);

export function parseLiveUpdate(value) {
  let payload;
  try {
    payload = typeof value === 'string' ? JSON.parse(value) : value;
  } catch {
    return null;
  }
  if (!payload || payload.version !== 1 || !Array.isArray(payload.scopes) || payload.scopes.length === 0)
    return null;
  const scopes = new Set();
  for (const scope of payload.scopes) {
    if (typeof scope !== 'string' || !allowedScopes.has(scope)) return null;
    scopes.add(scope);
  }
  return scopes.has('all') ? new Set(['all']) : scopes;
}

export function liveScopesIntersect(left, right) {
  if (left.has('all') || right.has('all')) return true;
  for (const scope of left) {
    if (right.has(scope)) return true;
  }
  return false;
}

export class LiveUpdateClient {
  constructor({
    url = '/api/events',
    eventSourceFactory = (sourceUrl) => new EventSource(sourceUrl),
    documentRef = typeof document === 'undefined' ? null : document,
    setTimeoutFn = (callback, delay) => globalThis.setTimeout(callback, delay),
    clearTimeoutFn = (timer) => globalThis.clearTimeout(timer),
    debounceMs = 250
  } = {}) {
    this.url = url;
    this.eventSourceFactory = eventSourceFactory;
    this.documentRef = documentRef;
    this.setTimeoutFn = setTimeoutFn;
    this.clearTimeoutFn = clearTimeoutFn;
    this.debounceMs = debounceMs;
    this.source = null;
    this.started = false;
    this.hasOpened = false;
    this.disconnected = false;
    this.pending = new Set();
    this.flushTimer = null;
    this.subscribers = new Set();
    this.waiters = new Set();
    this.onMessage = (event) => {
      const scopes = parseLiveUpdate(event?.data);
      if (scopes) this.queue(scopes);
    };
    this.onOpen = () => {
      if (this.hasOpened || this.disconnected) this.queue(new Set(['all']));
      this.hasOpened = true;
      this.disconnected = false;
    };
    this.onError = () => { this.disconnected = true; };
    this.onVisibilityChange = () => {
      if (this.documentRef?.visibilityState === 'visible') this.queue(new Set(['all']));
    };
  }

  start() {
    if (this.started) return;
    this.started = true;
    this.source = this.eventSourceFactory(this.url);
    this.source.addEventListener('fenping-update', this.onMessage);
    this.source.addEventListener('open', this.onOpen);
    this.source.addEventListener('error', this.onError);
    this.documentRef?.addEventListener('visibilitychange', this.onVisibilityChange);
  }

  stop() {
    if (!this.started) return;
    this.started = false;
    this.documentRef?.removeEventListener('visibilitychange', this.onVisibilityChange);
    this.source?.removeEventListener('fenping-update', this.onMessage);
    this.source?.removeEventListener('open', this.onOpen);
    this.source?.removeEventListener('error', this.onError);
    this.source?.close();
    this.source = null;
    if (this.flushTimer !== null) this.clearTimeoutFn(this.flushTimer);
    this.flushTimer = null;
    this.pending.clear();
    for (const finish of [...this.waiters]) finish('stopped');
  }

  subscribe(scopes, callback) {
    const subscription = { scopes: normalizeScopes(scopes), callback };
    this.subscribers.add(subscription);
    return () => { this.subscribers.delete(subscription); };
  }

  waitFor(scopes, timeoutMs = 15000) {
    if (!this.started) return Promise.resolve('stopped');
    return new Promise((resolve) => {
      let timer = null;
      let done = false;
      let unsubscribe = () => {};
      const finish = (reason) => {
        if (done) return;
        done = true;
        this.waiters.delete(finish);
        unsubscribe();
        if (timer !== null) this.clearTimeoutFn(timer);
        resolve(reason);
      };
      this.waiters.add(finish);
      unsubscribe = this.subscribe(scopes, () => finish('event'));
      timer = this.setTimeoutFn(() => finish('timeout'), timeoutMs);
    });
  }

  queue(scopes) {
    const normalized = normalizeScopes(scopes);
    if (normalized.has('all')) {
      this.pending = new Set(['all']);
    } else if (!this.pending.has('all')) {
      for (const scope of normalized) this.pending.add(scope);
    }
    if (this.flushTimer === null) {
      this.flushTimer = this.setTimeoutFn(() => this.flush(), this.debounceMs);
    }
  }

  flush() {
    this.flushTimer = null;
    if (this.pending.size === 0) return;
    const scopes = this.pending;
    this.pending = new Set();
    for (const subscriber of [...this.subscribers]) {
      if (!liveScopesIntersect(subscriber.scopes, scopes)) continue;
      try {
        Promise.resolve(subscriber.callback(scopes)).catch(() => {});
      } catch {
        // One failed refresh must not prevent other mounted views from updating.
      }
    }
  }
}

function normalizeScopes(scopes) {
  const normalized = scopes instanceof Set ? new Set(scopes) : new Set(scopes || []);
  for (const scope of normalized) {
    if (!allowedScopes.has(scope)) throw new TypeError(`unknown live update scope: ${scope}`);
  }
  return normalized.has('all') ? new Set(['all']) : normalized;
}
