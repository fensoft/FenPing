<template>
  <Teleport to="body">
    <div ref="modalRoot" class="modal modal-blur show d-block" tabindex="-1" role="dialog" aria-modal="true" aria-labelledby="inventory-columns-title" @mousedown.self="requestClose">
      <div class="modal-dialog modal-dialog-centered" role="document">
        <form class="modal-content" @submit.prevent="save">
          <div class="modal-header">
            <h2 id="inventory-columns-title" class="modal-title">{{ t('Inventory columns') }}</h2>
            <button class="btn-close" type="button" :aria-label="t('Close')" @click="requestClose"></button>
          </div>
          <div class="modal-body">
            <p class="text-secondary small">{{ t('Choose which columns appear, their order, and their relative widths. Dragging table headers also changes the saved layout.') }}</p>
            <div class="inventory-column-settings" role="list" :aria-label="t('Inventory columns')">
              <div v-for="(column, index) in draft.columns" :key="column.key" class="inventory-column-setting" role="listitem">
                <label class="form-check mb-0">
                  <input class="form-check-input" type="checkbox" :checked="column.visible" :disabled="column.visible && visibleCount === 1" @change="setVisible(column.key, $event.target.checked)" />
                  <span class="form-check-label">{{ t(columnDefinition(column.key).label) }}</span>
                </label>
                <label class="inventory-column-width-label">
                  <span class="visually-hidden">{{ t('{column} width', { column: t(columnDefinition(column.key).label) }) }}</span>
                  <div class="input-group input-group-sm">
                    <input class="form-control inventory-column-width-input" type="number" min="5" max="60" step="1" :value="column.width" :aria-label="t('{column} width', { column: t(columnDefinition(column.key).label) })" @change="setWidth(column.key, $event.target.value)" />
                    <span class="input-group-text">%</span>
                  </div>
                </label>
                <div class="btn-group btn-group-sm" role="group" :aria-label="t('Reorder {column}', { column: t(columnDefinition(column.key).label) })">
                  <button class="btn btn-outline-secondary inventory-column-move-up" type="button" :title="t('Move up')" :aria-label="t('Move {column} up', { column: t(columnDefinition(column.key).label) })" :disabled="index === 0" @click="move(column.key, -1)">↑</button>
                  <button class="btn btn-outline-secondary inventory-column-move-down" type="button" :title="t('Move down')" :aria-label="t('Move {column} down', { column: t(columnDefinition(column.key).label) })" :disabled="index === draft.columns.length - 1" @click="move(column.key, 1)">↓</button>
                </div>
              </div>
            </div>

            <fieldset class="inventory-down-thresholds mt-4">
              <legend class="form-label mb-2">{{ t('Down color thresholds') }}</legend>
              <div class="modal-body-grid">
                <label class="form-label">{{ t('Light gray under') }}<div class="input-group"><input v-model.number="draft.downRecentDays" class="form-control" name="downRecentDays" type="number" min="1" :max="Math.max(1, draft.downOlderDays - 1)" required /><span class="input-group-text">{{ t('days') }}</span></div></label>
                <label class="form-label">{{ t('Medium gray under') }}<div class="input-group"><input v-model.number="draft.downOlderDays" class="form-control" name="downOlderDays" type="number" :min="draft.downRecentDays + 1" max="3650" required /><span class="input-group-text">{{ t('days') }}</span></div></label>
              </div>
              <small class="form-hint">{{ t('Older Down rows use the darkest gray text. Up rows are unchanged.') }}</small>
            </fieldset>
          </div>
          <div class="modal-footer justify-content-between">
            <button class="btn btn-outline-secondary" type="button" @click="reset">{{ t('Reset defaults') }}</button>
            <div><button class="btn btn-link" type="button" @click="requestClose">{{ t('Cancel') }}</button><button class="btn btn-primary" type="submit"><AppIcon name="device-floppy" class="me-1" />{{ t('Save layout') }}</button></div>
          </div>
        </form>
      </div>
    </div>
  </Teleport>
</template>

<script setup>
import { computed, ref } from 'vue';
import AppIcon from './AppIcon.vue';
import { useAccessibleModal } from '../composables/useAccessibleModal.js';
import { defaultInventoryLayout, inventoryColumnDefinition, moveInventoryColumn, normalizeInventoryLayout, updateInventoryColumn } from '../lib/inventoryColumns.js';
import { t } from '../lib/i18n.js';

const props = defineProps({ layout: { type: Object, required: true } });
const emit = defineEmits(['close', 'save']);
const draft = ref(normalizeInventoryLayout(props.layout));
const visibleCount = computed(() => draft.value.columns.filter((column) => column.visible).length);
const modalRoot = useAccessibleModal(() => 'inventory-columns', requestClose);

function columnDefinition(key) { return inventoryColumnDefinition(key); }
function setVisible(key, visible) { draft.value = updateInventoryColumn(draft.value, key, { visible }); }
function setWidth(key, width) { draft.value = updateInventoryColumn(draft.value, key, { width }); }
function move(key, offset) { draft.value = moveInventoryColumn(draft.value, key, offset); }
function reset() { draft.value = defaultInventoryLayout(); }
function requestClose() { emit('close'); }
function save() { emit('save', normalizeInventoryLayout(draft.value)); }
</script>
