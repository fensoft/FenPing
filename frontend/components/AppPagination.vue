<template>
  <div v-if="pagination.total" class="table-pagination">
    <span class="text-secondary small">{{ t('Showing {first}–{last} of {total}', pagination) }}</span>
    <div class="btn-group" role="group" :aria-label="t('Pagination')">
      <button class="btn btn-outline-secondary btn-sm icon-btn" type="button" :disabled="pagination.page <= 1" :title="t('Previous page')" :aria-label="t('Previous page')" @click="$emit('update:page', pagination.page - 1)"><AppIcon name="arrow-left" /></button>
      <span class="btn btn-outline-secondary btn-sm disabled pagination-position">{{ t('Page {page} of {pages}', pagination) }}</span>
      <button class="btn btn-outline-secondary btn-sm icon-btn" type="button" :disabled="pagination.page >= pagination.pages" :title="t('Next page')" :aria-label="t('Next page')" @click="$emit('update:page', pagination.page + 1)"><AppIcon name="arrow-right" /></button>
    </div>
  </div>
</template>

<script setup>
import AppIcon from './AppIcon.vue';
import { t } from '../lib/i18n.js';

defineProps({ pagination: { type: Object, required: true } });
defineEmits(['update:page']);
</script>
