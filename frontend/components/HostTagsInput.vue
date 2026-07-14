<template>
  <div class="host-tags-input" @click="input?.focus()">
    <span v-for="tag in modelValue" :key="tag.toLowerCase()" class="badge bg-blue-lt text-blue host-tag-chip">
      {{ tag }}
      <button type="button" :title="t('Remove tag {name}', { name: tag })" :aria-label="t('Remove tag {name}', { name: tag })" @click.stop="remove(tag)">
        <AppIcon name="x" />
      </button>
    </span>
    <input ref="input" v-model="draft" type="text" :placeholder="modelValue.length ? '' : t('Add tags')" @blur="commit" @keydown="onKeydown" />
  </div>
  <small class="form-hint">{{ t('Press Enter or comma to add a tag.') }}</small>
</template>

<script setup>
import { ref } from 'vue';
import { t } from '../lib/i18n.js';

const props = defineProps({ modelValue: { type: Array, default: () => [] } });
const emit = defineEmits(['update:modelValue']);
const draft = ref('');
const input = ref(null);

function normalized(values) {
  const result = [];
  const seen = new Set();
  for (const value of values) {
    const tag = String(value || '').trim();
    const key = tag.toLowerCase();
    if (!tag || seen.has(key)) continue;
    seen.add(key);
    result.push(tag);
  }
  return result.sort((left, right) => left.localeCompare(right, undefined, { sensitivity: 'base' }));
}

function commit() {
  if (!draft.value.trim()) return;
  emit('update:modelValue', normalized([...props.modelValue, ...draft.value.split(/[,\n]/)]));
  draft.value = '';
}

function remove(tag) {
  emit('update:modelValue', props.modelValue.filter((item) => item.toLowerCase() !== tag.toLowerCase()));
}

function onKeydown(event) {
  if (event.key === 'Enter' || event.key === ',') {
    event.preventDefault();
    commit();
    return;
  }
  if (event.key === 'Backspace' && draft.value === '' && props.modelValue.length) {
    event.preventDefault();
    emit('update:modelValue', props.modelValue.slice(0, -1));
  }
}
</script>
