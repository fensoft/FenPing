<template>
  <Teleport to="body">
    <div
      ref="modalRoot"
      class="modal modal-blur show d-block notification-delivery-modal"
      tabindex="-1"
      role="dialog"
      aria-modal="true"
      aria-labelledby="notification-delivery-modal-title"
      :aria-busy="saving || telegramChatsLoading"
      @mousedown.self="requestClose"
    >
      <div class="modal-dialog modal-dialog-centered modal-lg" role="document">
        <div class="modal-content">
          <div class="modal-header">
            <h2 id="notification-delivery-modal-title" class="modal-title">{{ t('Notification delivery') }}</h2>
            <button class="btn-close" type="button" :aria-label="t('Close')" :disabled="saving" @click="requestClose"></button>
          </div>

          <div class="modal-body">
            <div class="notification-delivery-header">
              <div class="small text-secondary">{{ t('Shared rules apply to every configured provider') }}</div>
              <div class="notification-provider-badges">
                <span class="badge" :class="delivery.discord.configured ? 'bg-indigo-lt text-indigo' : 'bg-secondary-lt text-secondary'">Discord · {{ t(delivery.discord.configured ? 'Configured' : 'Not configured') }}</span>
                <span class="badge" :class="delivery.telegram.configured ? 'bg-blue-lt text-blue' : 'bg-secondary-lt text-secondary'">Telegram · {{ t(delivery.telegram.configured ? 'Configured' : 'Not configured') }}</span>
                <span class="badge" :class="delivery.telegram.chat_selected ? 'bg-blue-lt text-blue' : 'bg-secondary-lt text-secondary'">{{ t('Telegram destination') }} · {{ t(delivery.telegram.chat_selected ? 'Configured' : 'Not configured') }}</span>
                <span class="badge bg-secondary-lt text-secondary">{{ t('Discord mention') }} · {{ mentionLabel }}</span>
              </div>
            </div>

            <div v-if="settingsError" class="alert alert-danger py-2 px-3 mt-3 mb-0" role="alert">{{ settingsError }}</div>
            <div v-if="settingsSuccess" class="alert alert-success py-2 px-3 mt-3 mb-0" role="status">{{ settingsSuccess }}</div>

            <section class="notification-telegram-settings" aria-labelledby="telegram-destination-title">
              <div class="notification-telegram-heading">
                <div>
                  <h3 id="telegram-destination-title">{{ t('Telegram destination') }}</h3>
                  <div class="small text-secondary">{{ t('Send a message to the bot, then refresh to discover its chat.') }}</div>
                </div>
                <button
                  v-if="isAuthenticated && delivery.telegram.configured"
                  class="btn btn-outline-secondary btn-sm"
                  type="button"
                  :disabled="saving || telegramChatsLoading"
                  @click="$emit('refresh-telegram-chats')"
                >
                  <AppIcon name="refresh" class="me-1" :class="{ 'is-spinning': telegramChatsLoading }" />{{ t('Refresh chats') }}
                </button>
              </div>

              <div v-if="!delivery.telegram.configured" class="alert alert-secondary py-2 px-3 mb-0">
                {{ t('Configure TELEGRAM_BOT_TOKEN to discover chats.') }}
              </div>
              <div v-else-if="!isAuthenticated" class="alert alert-secondary py-2 px-3 mb-0">
                {{ t('Log in to view and select Telegram chats.') }}
              </div>
              <template v-else>
                <div v-if="telegramChatsError" class="alert alert-danger py-2 px-3 mb-2" role="alert">{{ telegramChatsError }}</div>
                <div v-if="telegramChatsLoading && telegramChats.length === 0" class="text-secondary small py-3 text-center">{{ t('Loading') }}</div>
                <div v-else class="notification-telegram-chats" role="radiogroup" :aria-label="t('Telegram destination')">
                  <label class="notification-telegram-chat">
                    <input v-model="telegramChatId" class="form-check-input" type="radio" name="telegram-chat-id" value="" :disabled="saving || telegramChatsLoading" />
                    <span>
                      <strong>{{ t('No Telegram destination') }}</strong>
                      <small>{{ t('Telegram notifications remain disabled until a chat is selected.') }}</small>
                    </span>
                  </label>
                  <label v-for="chat in telegramChats" :key="chat.id" class="notification-telegram-chat">
                    <input v-model="telegramChatId" class="form-check-input" type="radio" name="telegram-chat-id" :value="chat.id" :disabled="saving || telegramChatsLoading" />
                    <span>
                      <span class="notification-telegram-chat-title">
                        <strong>{{ chat.name }}</strong>
                        <span class="badge bg-blue-lt text-blue">{{ chat.type }}</span>
                      </span>
                      <small>{{ telegramChatMeta(chat) }}</small>
                      <small v-if="chat.user">{{ telegramUserMeta(chat.user) }}</small>
                    </span>
                  </label>
                  <div v-if="telegramChats.length === 0 && !telegramChatsLoading" class="text-secondary small px-3 py-2">
                    {{ t('No Telegram chats discovered yet.') }}
                  </div>
                </div>
              </template>
            </section>

            <div class="notification-global-rules">
              <label class="form-check form-switch"><input v-model="rules.restart" class="form-check-input" type="checkbox" :disabled="!isAuthenticated || saving" /><span class="form-check-label">{{ t('Appliance restarts') }}</span></label>
              <label class="form-check form-switch"><input v-model="rules.ip_conflicts" class="form-check-input" type="checkbox" :disabled="!isAuthenticated || saving" /><span class="form-check-label">{{ t('IP conflicts') }}</span></label>
            </div>

            <div class="table-wrap notification-rules-wrap">
              <table class="table table-sm notification-rules-table">
                <thead><tr><th>{{ t('Event') }}</th><th>{{ t('Normal devices') }}</th><th>{{ t('Important devices') }}</th></tr></thead>
                <tbody>
                  <tr><th scope="row">{{ t('Host status changes') }}</th><td><input v-model="rules.host_status.normal" class="form-check-input" type="checkbox" :aria-label="t('Normal-device host status changes')" :disabled="!isAuthenticated || saving" /></td><td><input v-model="rules.host_status.important" class="form-check-input" type="checkbox" :aria-label="t('Important-device host status changes')" :disabled="!isAuthenticated || saving" /></td></tr>
                  <tr><th scope="row">{{ t('Service changes') }}</th><td><input v-model="rules.service_changes.normal" class="form-check-input" type="checkbox" :aria-label="t('Normal-device service changes')" :disabled="!isAuthenticated || saving" /></td><td><input v-model="rules.service_changes.important" class="form-check-input" type="checkbox" :aria-label="t('Important-device service changes')" :disabled="!isAuthenticated || saving" /></td></tr>
                  <tr v-for="anomaly in anomalyRules" :key="anomaly.key"><th scope="row">{{ anomaly.label }}</th><td><input v-model="rules.network_anomalies[anomaly.key].normal" class="form-check-input" type="checkbox" :aria-label="t('Normal-device {event}', { event: anomaly.label })" :disabled="!isAuthenticated || saving" /></td><td><input v-model="rules.network_anomalies[anomaly.key].important" class="form-check-input" type="checkbox" :aria-label="t('Important-device {event}', { event: anomaly.label })" :disabled="!isAuthenticated || saving" /></td></tr>
                </tbody>
              </table>
            </div>

            <section class="mt-4" aria-labelledby="scheduled-reports-title">
              <div class="mb-3">
                <h3 id="scheduled-reports-title" class="mb-1">{{ t('Scheduled reports') }}</h3>
                <div class="small text-secondary">{{ t('Summaries include outages, new devices, IP conflicts, changed ports, and certificates observed by scans.') }}</div>
              </div>
              <div class="row g-3">
                <div class="col-md-6">
                  <label class="form-check form-switch">
                    <input v-model="reports.daily_enabled" class="form-check-input" type="checkbox" :disabled="!isAuthenticated || saving" />
                    <span class="form-check-label">{{ t('Daily report') }}</span>
                  </label>
                  <div class="small text-secondary">{{ lastRunLabel('daily') }}</div>
                </div>
                <div class="col-md-6">
                  <label class="form-check form-switch">
                    <input v-model="reports.weekly_enabled" class="form-check-input" type="checkbox" :disabled="!isAuthenticated || saving" />
                    <span class="form-check-label">{{ t('Weekly report') }}</span>
                  </label>
                  <div class="small text-secondary">{{ lastRunLabel('weekly') }}</div>
                </div>
                <label class="col-md-4 form-label">
                  {{ t('Delivery hour (UTC)') }}
                  <select v-model.number="reports.hour_utc" class="form-select" :disabled="!isAuthenticated || saving">
                    <option v-for="hour in 24" :key="hour - 1" :value="hour - 1">{{ String(hour - 1).padStart(2, '0') }}:00</option>
                  </select>
                </label>
                <label class="col-md-4 form-label">
                  {{ t('Weekly day') }}
                  <select v-model.number="reports.weekly_day" class="form-select" :disabled="!isAuthenticated || saving || !reports.weekly_enabled">
                    <option v-for="day in weekDays" :key="day.value" :value="day.value">{{ day.label }}</option>
                  </select>
                </label>
                <label class="col-md-4 form-label">
                  {{ t('Certificate warning') }}
                  <select v-model.number="reports.certificate_warning_days" class="form-select" :disabled="!isAuthenticated || saving">
                    <option v-for="days in [7, 14, 30, 60, 90]" :key="days" :value="days">{{ t('{days} days', { days }) }}</option>
                  </select>
                </label>
              </div>
            </section>
          </div>

          <div class="modal-footer justify-content-between">
            <div v-if="!isAuthenticated" class="small text-secondary">{{ t('Guest mode is read only. Log in to change notification delivery rules.') }}</div>
            <div v-else class="small text-secondary">{{ t('Discord and Telegram use the same selected rules.') }}</div>
            <div class="d-flex gap-2">
              <button class="btn btn-link" type="button" :disabled="saving" @click="requestClose">{{ t('Close') }}</button>
              <button v-if="isAuthenticated" class="btn btn-primary" type="button" :disabled="saving || telegramChatsLoading || !dirty" @click="$emit('save')">
                <AppIcon :name="saving ? 'loader-2' : 'device-floppy'" class="me-1" :class="{ 'is-spinning': saving }" />{{ t('Save notification rules') }}
              </button>
            </div>
          </div>
        </div>
      </div>
    </div>
  </Teleport>
</template>

<script setup>
import { computed } from 'vue';
import AppIcon from './AppIcon.vue';
import { useAccessibleModal } from '../composables/useAccessibleModal.js';
import { t } from '../lib/i18n.js';

const anomalyRules = [
  { key: 'open_ports', label: t('New open ports') },
  { key: 'unexpected_vendors', label: t('Unexpected vendors') },
  { key: 'ip_changes', label: t('IP changes') },
  { key: 'duplicate_identities', label: t('Duplicate identities') },
  { key: 'churn', label: t('Unusual churn') }
];

const props = defineProps({
  delivery: { type: Object, required: true },
  isAuthenticated: Boolean,
  dirty: Boolean,
  saving: Boolean,
  settingsError: { type: String, default: '' },
  settingsSuccess: { type: String, default: '' },
  telegramChats: { type: Array, default: () => [] },
  telegramChatsError: { type: String, default: '' },
  telegramChatsLoading: Boolean
});
const rules = defineModel('rules', { type: Object, required: true });
const reports = defineModel('reports', { type: Object, required: true });
const telegramChatId = defineModel('telegramChatId', { type: String, default: '' });
const emit = defineEmits(['close', 'refresh-telegram-chats', 'save']);
const mentionLabel = computed(() => props.delivery.discord.mention_target === 'everyone'
  ? '@everyone'
  : props.delivery.discord.mention_target === 'user'
    ? t('Configured user')
    : t('Disabled'));
const weekDays = computed(() => [
  'Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'
].map((label, value) => ({ value, label: t(label) })));
const modalRoot = useAccessibleModal(() => 'notification-delivery', requestClose);

function requestClose() {
  if (!props.saving) emit('close');
}

function telegramChatMeta(chat) {
  return [
    t('Chat ID: {id}', { id: chat.id }),
    chat.username ? `@${chat.username}` : ''
  ].filter(Boolean).join(' · ');
}

function telegramUserMeta(user) {
  return [
    t('User: {name}', { name: user.name }),
    user.username ? `@${user.username}` : '',
    t('User ID: {id}', { id: user.id }),
    user.language_code || ''
  ].filter(Boolean).join(' · ');
}

function lastRunLabel(frequency) {
  const run = props.delivery?.reports?.last_runs?.[frequency];
  if (!run) return t('Never sent');
  return t('Last run: {date} ({state})', { date: `${run.scheduled_for} UTC`, state: t(run.state) });
}
</script>
