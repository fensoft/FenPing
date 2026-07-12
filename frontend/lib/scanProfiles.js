import { t } from './i18n.js';

export const scanProfiles = Object.freeze([
  Object.freeze({
    id: 'lightweight',
    name: 'Lightweight',
    description: '100 common TCP ports with basic service names.',
    timeout: '5 minute limit',
    icon: 'bolt'
  }),
  Object.freeze({
    id: 'standard',
    name: 'Standard',
    description: 'Top 1,000 TCP ports with service, OS, and script detection.',
    timeout: '30 minute limit',
    icon: 'radar'
  }),
  Object.freeze({
    id: 'deep',
    name: 'Deep',
    description: 'All 65,535 TCP ports with full service, OS, and script detection.',
    timeout: '2 hour limit',
    icon: 'telescope'
  })
]);

export const scanCadenceOptions = Object.freeze([
  Object.freeze({ hours: 0, name: 'Off' }),
  Object.freeze({ hours: 1, name: 'Every hour' }),
  Object.freeze({ hours: 6, name: 'Every 6 hours' }),
  Object.freeze({ hours: 12, name: 'Every 12 hours' }),
  Object.freeze({ hours: 24, name: 'Daily' }),
  Object.freeze({ hours: 168, name: 'Weekly' })
]);

export function scanProfileLabel(profile) {
  if (profile === 'quick') return t('Lightweight');
  const name = scanProfiles.find((item) => item.id === profile)?.name;
  return name ? t(name) : profile || '-';
}

export function scanProfileBadgeClass(profile) {
  if (profile === 'deep') return 'bg-purple-lt text-purple';
  if (profile === 'standard') return 'bg-azure-lt text-azure';
  return 'bg-blue-lt text-blue';
}

export function scanCadenceLabel(hours) {
  const value = Number(hours || 0);
  const preset = scanCadenceOptions.find((item) => item.hours === value);
  if (preset) return t(preset.name);
  return t('Every {count} hours', { count: value });
}
