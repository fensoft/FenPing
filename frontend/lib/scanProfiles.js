export const scanProfiles = Object.freeze([
  Object.freeze({
    id: 'lightweight',
    name: 'Lightweight',
    description: '100 common TCP ports with basic service names.',
    timeout: '5 minute limit',
    icon: 'ti ti-bolt'
  }),
  Object.freeze({
    id: 'standard',
    name: 'Standard',
    description: 'Top 1,000 TCP ports with service, OS, and script detection.',
    timeout: '30 minute limit',
    icon: 'ti ti-radar'
  }),
  Object.freeze({
    id: 'deep',
    name: 'Deep',
    description: 'All 65,535 TCP ports with full service, OS, and script detection.',
    timeout: '2 hour limit',
    icon: 'ti ti-telescope'
  })
]);

export function scanProfileLabel(profile) {
  if (profile === 'quick') return 'Lightweight';
  return scanProfiles.find((item) => item.id === profile)?.name || profile || '-';
}

export function scanProfileBadgeClass(profile) {
  if (profile === 'deep') return 'bg-purple-lt text-purple';
  if (profile === 'standard') return 'bg-azure-lt text-azure';
  return 'bg-blue-lt text-blue';
}
