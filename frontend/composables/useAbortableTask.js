import { onScopeDispose } from 'vue';

export function useAbortableTask() {
  let controller = null;

  function nextSignal() {
    controller?.abort();
    controller = new AbortController();
    return controller.signal;
  }

  function abort() {
    controller?.abort();
    controller = null;
  }

  function isCurrent(signal) {
    return controller?.signal === signal && !signal.aborted;
  }

  onScopeDispose(abort);
  return { abort, isCurrent, nextSignal };
}
