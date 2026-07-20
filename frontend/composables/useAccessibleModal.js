import { nextTick, onMounted, onUnmounted, ref, unref, watch } from 'vue';

const FOCUSABLE = [
  'a[href]',
  'button:not([disabled])',
  'input:not([disabled]):not([type="hidden"])',
  'select:not([disabled])',
  'textarea:not([disabled])',
  '[tabindex]:not([tabindex="-1"])'
].join(',');

export function useAccessibleModal(modalType, close) {
  const root = ref(null);
  let returnFocus = null;
  let active = false;
  let concealed = [];

  function concealBackground() {
    let node = root.value;
    while (node?.parentElement && node.parentElement !== document.body) {
      for (const sibling of node.parentElement.children) {
        if (sibling === node || sibling.hasAttribute('inert')) continue;
        concealed.push({ element: sibling, ariaHidden: sibling.getAttribute('aria-hidden') });
        sibling.setAttribute('inert', '');
        sibling.setAttribute('aria-hidden', 'true');
      }
      node = node.parentElement;
    }
  }

  function revealBackground() {
    for (const { element, ariaHidden } of concealed) {
      element.removeAttribute('inert');
      if (ariaHidden === null) element.removeAttribute('aria-hidden');
      else element.setAttribute('aria-hidden', ariaHidden);
    }
    concealed = [];
  }

  function focusFirst() {
    const target = root.value?.querySelector('[autofocus], [data-autofocus]')
      || root.value?.querySelector(FOCUSABLE)
      || root.value;
    target?.focus({ preventScroll: true });
  }

  function onKeydown(event) {
    if (!root.value)
      return;
    if (event.key === 'Escape') {
      event.preventDefault();
      close();
      return;
    }
    if (event.key !== 'Tab')
      return;

    const focusable = Array.from(root.value.querySelectorAll(FOCUSABLE))
      .filter((element) => element.offsetParent !== null);
    if (focusable.length === 0) {
      event.preventDefault();
      root.value.focus();
      return;
    }

    const first = focusable[0];
    const last = focusable[focusable.length - 1];
    if (event.shiftKey && document.activeElement === first) {
      event.preventDefault();
      last.focus();
    } else if (!event.shiftKey && document.activeElement === last) {
      event.preventDefault();
      first.focus();
    }
  }

  function value() {
    return unref(typeof modalType === 'function' ? modalType() : modalType);
  }

  async function activate() {
    const newlyActive = !active;
    if (!active) {
      active = true;
      returnFocus = document.activeElement;
      document.body.classList.add('modal-open');
      document.addEventListener('keydown', onKeydown, true);
    }
    await nextTick();
    if (newlyActive) concealBackground();
    focusFirst();
  }

  function deactivate() {
    if (!active) return;
    active = false;
    document.body.classList.remove('modal-open');
    document.removeEventListener('keydown', onKeydown, true);
    revealBackground();
    if (returnFocus instanceof HTMLElement && document.contains(returnFocus))
      returnFocus.focus({ preventScroll: true });
    returnFocus = null;
  }

  watch(modalType, (next) => { if (next) activate(); else deactivate(); });

  onMounted(async () => {
    if (value()) await activate();
  });

  onUnmounted(deactivate);

  return root;
}
