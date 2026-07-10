import { onMounted, onUnmounted, ref } from 'vue';

export function useNow(interval = 1000) {
  const now = ref(Date.now());
  let timer = null;

  onMounted(() => {
    timer = window.setInterval(() => {
      now.value = Date.now();
    }, interval);
  });

  onUnmounted(() => {
    if (timer !== null)
      window.clearInterval(timer);
  });

  return now;
}
