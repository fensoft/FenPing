import { inject, onMounted, onUnmounted, provide } from 'vue';
import { LiveUpdateClient } from '../lib/liveUpdates.js';

const LIVE_UPDATES = Symbol('fenping-live-updates');

export function provideLiveUpdates(options = {}) {
  const client = new LiveUpdateClient(options);
  provide(LIVE_UPDATES, client);
  onMounted(() => client.start());
  onUnmounted(() => client.stop());
  return client;
}

export function useLiveRefresh(scopes, refresh) {
  const client = inject(LIVE_UPDATES);
  if (!client) throw new Error('live update provider is missing');
  let unsubscribe = null;
  onMounted(() => { unsubscribe = client.subscribe(scopes, refresh); });
  onUnmounted(() => { unsubscribe?.(); });
  return client;
}
