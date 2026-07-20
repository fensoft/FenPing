<template>
  <Teleport to="body">
    <div ref="modalRoot" class="modal modal-blur show d-block" tabindex="-1" role="dialog" aria-modal="true" aria-labelledby="inventory-export-title" @mousedown.self="requestClose">
      <div class="modal-dialog modal-dialog-centered" role="document">
        <div class="modal-content">
          <div class="modal-header">
            <h2 id="inventory-export-title" class="modal-title">{{ t('Inventory export') }}</h2>
            <button class="btn-close" type="button" :aria-label="t('Close')" @click="requestClose"></button>
          </div>
          <div class="modal-body">
            <div class="small text-secondary mb-3">{{ t('Export data for the selected network: {network}', { network }) }}</div>
            <fieldset class="inventory-export-datasets">
              <legend class="form-label">{{ t('Dataset') }}</legend>
              <label v-for="option in datasets" :key="option.value" class="inventory-export-option">
                <input v-model="dataset" class="form-check-input" type="radio" name="inventory-export-dataset" :value="option.value" />
                <span><strong>{{ option.label }}</strong><small>{{ option.description }}</small></span>
              </label>
            </fieldset>
            <fieldset class="mt-3">
              <legend class="form-label">{{ t('Format') }}</legend>
              <div class="btn-group" role="group" :aria-label="t('Format')">
                <input id="inventory-export-csv" v-model="format" class="btn-check" type="radio" name="inventory-export-format" value="csv" />
                <label class="btn btn-outline-secondary" for="inventory-export-csv">CSV</label>
                <input id="inventory-export-json" v-model="format" class="btn-check" type="radio" name="inventory-export-format" value="json" />
                <label class="btn btn-outline-secondary" for="inventory-export-json">JSON</label>
              </div>
            </fieldset>
          </div>
          <div class="modal-footer">
            <button class="btn btn-link" type="button" @click="requestClose">{{ t('Cancel') }}</button>
            <a class="btn btn-primary" :href="downloadUrl" download @click="$emit('download')"><AppIcon name="download" class="me-1" />{{ t('Download export') }}</a>
          </div>
        </div>
      </div>
    </div>
  </Teleport>
</template>

<script setup>
import { computed, ref } from 'vue';
import AppIcon from './AppIcon.vue';
import { useAccessibleModal } from '../composables/useAccessibleModal.js';
import { t } from '../lib/i18n.js';

const props = defineProps({ network: { type: String, required: true } });
const emit = defineEmits(['close', 'download']);
const dataset = ref('hosts');
const format = ref('csv');
const datasets = computed(() => [
  { value: 'hosts', label: t('Hosts'), description: t('Current inventory, metadata, status, uptime, and service counts.') },
  { value: 'leases', label: t('Lease history'), description: t('Current and historical DHCP address assignments.') },
  { value: 'services', label: t('Services'), description: t('Open ports from each host’s latest effective scan.') },
  { value: 'scan_changes', label: t('Scan changes'), description: t('Appeared, disappeared, and version-changed services.') },
  { value: 'anomalies', label: t('Network anomalies'), description: t('Open ports, vendors, IP moves, duplicate identities, and churn from the last 30 days.') },
  { value: 'uptime_history', label: t('Uptime history'), description: t('Retained raw status intervals from the last seven days.') }
]);
const downloadUrl = computed(() => `/api/exports/${encodeURIComponent(dataset.value)}?format=${encodeURIComponent(format.value)}&network=${encodeURIComponent(props.network)}`);
const modalRoot = useAccessibleModal(() => 'inventory-export', requestClose);

function requestClose() { emit('close'); }
</script>
