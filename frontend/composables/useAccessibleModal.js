import { nextTick, onMounted, onUnmounted, ref, watch } from 'vue';

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

  watch(modalType, async () => {
    await nextTick();
    focusFirst();
  });

  onMounted(async () => {
    returnFocus = document.activeElement;
    document.body.classList.add('modal-open');
    document.addEventListener('keydown', onKeydown, true);
    await nextTick();
    focusFirst();
  });

  onUnmounted(() => {
    document.body.classList.remove('modal-open');
    document.removeEventListener('keydown', onKeydown, true);
    if (returnFocus instanceof HTMLElement && document.contains(returnFocus))
      returnFocus.focus({ preventScroll: true });
  });

  return root;
}
