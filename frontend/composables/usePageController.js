import { inject, onMounted, onUnmounted, provide, shallowRef } from 'vue';

const PAGE_CONTROLLER = Symbol('fenping-page-controller');

export function providePageController() {
  const current = shallowRef(null);

  function register(controller) {
    current.value = controller;
  }

  function unregister(controller) {
    if (current.value === controller)
      current.value = null;
  }

  provide(PAGE_CONTROLLER, { register, unregister });
  return current;
}

export function usePageController(controller) {
  const registry = inject(PAGE_CONTROLLER);
  if (!registry)
    throw new Error('page controller provider is missing');

  onMounted(() => registry.register(controller));
  onUnmounted(() => registry.unregister(controller));
}
