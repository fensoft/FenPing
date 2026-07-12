<template><div class="modal-footer"><button class="btn btn-link" type="button" :disabled="saving" @click="$emit('close')">{{ t('Cancel') }}</button><button class="btn btn-primary" type="submit" :disabled="saving"><AppIcon :name="submitIcon" class="me-1" />{{ submitLabel }}</button></div></template>
<script setup>
import { t } from '../lib/i18n.js';

defineProps({ saving: Boolean, submitLabel: { type: String, required: true }, submitIcon: { type: String, required: true } });
defineEmits(['close']);
</script>
