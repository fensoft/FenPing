<template>
  <Teleport to="body">
    <div ref="modalRoot" class="modal modal-blur show d-block" tabindex="-1" role="dialog" aria-modal="true" aria-labelledby="inventory-bulk-title" @mousedown.self="requestClose">
      <div class="modal-dialog modal-dialog-centered" role="document">
        <form class="modal-content" @submit.prevent="submit">
          <div class="modal-header">
            <h2 id="inventory-bulk-title" class="modal-title">{{ title }}</h2>
            <button class="btn-close" type="button" :aria-label="t('Close')" :disabled="saving" @click="requestClose"></button>
          </div>
          <div class="modal-body">
            <div v-if="error" class="alert alert-danger" role="alert">{{ error }}</div>
            <div class="alert alert-info py-2" role="status">
              {{ t('{eligible} eligible · {skipped} skipped', { eligible: eligibleCount, skipped: skippedCount }) }}
            </div>

            <template v-if="action === 'tags'">
              <label class="form-label">{{ t('Tags to add') }}</label>
              <HostTagsInput v-model="addTags" />
              <label class="form-label mt-3">{{ t('Tags to remove') }}</label>
              <HostTagsInput v-model="removeTags" />
              <div v-if="tagConflict" class="text-danger small mt-2">{{ t('The same tag cannot be added and removed.') }}</div>
            </template>

            <label v-else-if="action === 'scan_profile'" class="form-label">
              {{ t('Scheduled scan profile') }}
              <select v-model="scanProfile" class="form-select" autofocus>
                <option v-for="profile in scanProfiles" :key="profile.id" :value="profile.id">{{ t(profile.name) }}</option>
              </select>
            </label>

            <p v-else-if="action === 'approve'" class="mb-0">
              {{ t('Approve {count} selected devices?', { count: eligibleCount }) }}
              <span class="d-block text-secondary small mt-2">{{ t('Approval acknowledges a device without creating a DHCP reservation.') }}</span>
            </p>

            <p v-else class="mb-0">
              {{ t('Delete {count} selected DHCP reservations?', { count: eligibleCount }) }}
              <span class="d-block text-danger small mt-2">{{ t('This removes the reservations and regenerates dnsmasq configuration.') }}</span>
            </p>
          </div>
          <div class="modal-footer">
            <button class="btn btn-link" type="button" :disabled="saving" @click="requestClose">{{ t('Cancel') }}</button>
            <button class="btn" :class="action === 'delete' ? 'btn-danger' : 'btn-primary'" type="submit" :disabled="submitDisabled">
              <AppIcon :name="submitIcon" class="me-1" />{{ submitLabel }}
            </button>
          </div>
        </form>
      </div>
    </div>
  </Teleport>
</template>

<script setup>
import { computed, ref } from 'vue';
import HostTagsInput from './HostTagsInput.vue';
import { useAccessibleModal } from '../composables/useAccessibleModal.js';
import { t } from '../lib/i18n.js';
import { scanProfiles } from '../lib/scanProfiles.js';

const props = defineProps({
  action: { type: String, required: true },
  eligibleCount: { type: Number, required: true },
  skippedCount: { type: Number, required: true },
  saving: Boolean,
  error: { type: String, default: '' }
});
const emit = defineEmits(['close', 'submit']);
const addTags = ref([]);
const removeTags = ref([]);
const scanProfile = ref('standard');
const modalRoot = useAccessibleModal(() => props.action, requestClose);

const titles = {
  tags: 'Edit tags',
  scan_profile: 'Change scan profile',
  approve: 'Approve devices',
  delete: 'Delete reservations'
};
const title = computed(() => t(titles[props.action] || 'Bulk actions'));
const submitLabel = computed(() => t(props.action === 'delete' ? 'Delete' : props.action === 'approve' ? 'Approve' : 'Apply'));
const submitIcon = computed(() => props.action === 'delete' ? 'trash' : props.action === 'approve' ? 'check' : 'device-floppy');
const tagConflict = computed(() => {
  const removed = new Set(removeTags.value.map((tag) => tag.toLowerCase()));
  return addTags.value.some((tag) => removed.has(tag.toLowerCase()));
});
const submitDisabled = computed(() => props.saving || props.eligibleCount < 1
  || (props.action === 'tags' && (tagConflict.value || (addTags.value.length === 0 && removeTags.value.length === 0))));

function requestClose() {
  if (!props.saving) emit('close');
}

function submit() {
  if (submitDisabled.value) return;
  if (props.action === 'tags') {
    emit('submit', { add_tags: addTags.value, remove_tags: removeTags.value });
  } else if (props.action === 'scan_profile') {
    emit('submit', { scan_profile: scanProfile.value });
  } else {
    emit('submit', {});
  }
}
</script>
