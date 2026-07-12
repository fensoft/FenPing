import { t } from './i18n.js';

export function formatMac(mac) {
  return String(mac || '').toLowerCase();
}

export function formatDuration(value) {
  const seconds = Math.max(0, Math.floor(Number(value || 0)));
  if (seconds < 60) return `${seconds}s`;
  const minutes = Math.floor(seconds / 60);
  const hours = Math.floor(minutes / 60);
  const days = Math.floor(hours / 24);
  const parts = [];
  if (days > 0) parts.push(`${days}d`);
  if (hours % 24 > 0) parts.push(`${hours % 24}h`);
  if (minutes % 60 > 0 || parts.length === 0) parts.push(`${minutes % 60}m`);
  return parts.slice(0, 2).join(' ');
}

export function formatBytes(value) {
  const bytes = Number(value || 0);
  if (bytes < 1024) return `${bytes} B`;
  const units = ['KB', 'MB', 'GB'];
  let size = bytes / 1024;
  let unit = units[0];
  for (let i = 1; i < units.length && size >= 1024; i++) {
    size /= 1024;
    unit = units[i];
  }
  return `${size >= 10 ? Math.round(size) : size.toFixed(1)} ${unit}`;
}

export function formatScanDuration(value) {
  if (value === null || value === undefined || value === '') return '-';
  let remaining = Math.max(0, Math.floor(Number(value) || 0));
  const parts = [];
  for (const [size, suffix] of [[86400, 'd'], [3600, 'h'], [60, 'm'], [1, 's']]) {
    const amount = Math.floor(remaining / size);
    if (amount > 0 || (size === 1 && parts.length === 0)) parts.push(`${amount}${suffix}`);
    remaining %= size;
    if (parts.length === 2) break;
  }
  return parts.join('');
}

export function parseServerDate(value) {
  const text = String(value || '').trim();
  if (text === '') return NaN;
  const plain = /^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}$/;
  return Date.parse(plain.test(text) ? `${text.replace(' ', 'T')}Z` : text);
}

export function formatServerDate(value) {
  const text = String(value || '').trim();
  if (text === '') return '-';
  const timestamp = parseServerDate(text);
  if (Number.isNaN(timestamp)) return text;
  const date = new Date(timestamp);
  const pad = (part) => String(part).padStart(2, '0');
  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())} ${pad(date.getHours())}:${pad(date.getMinutes())}:${pad(date.getSeconds())}`;
}

export const formatScanDate = formatServerDate;
export const formatNotifyDate = formatServerDate;

export function formatRelativeAge(value, now = Date.now()) {
  const timestamp = Number(value || 0);
  if (!timestamp) return '';
  return t('{duration} ago', { duration: formatDuration(Math.max(0, Math.floor(now / 1000) - timestamp)) });
}

export function formatPercent(value) {
  return `${Math.round(Number(value || 0))}%`;
}

export function toFlag(value) {
  return value === true || value === 1 || value === '1';
}

export function statusClass(status) {
  if (status === 'Up') return 'status-pill status-up';
  if (status === 'Down') return 'status-pill status-down';
  if (status === 'arp') return 'status-pill status-arp';
  if (status === 'arp-down') return 'status-pill status-arp-down';
  return 'status-pill status-unknown';
}

export function statusIcon(status) {
  if (status === 'Up') return 'check';
  if (status === 'Down') return 'x';
  if (status === 'arp') return 'wifi';
  if (status === 'arp-down') return 'alert-triangle';
  return 'question-mark';
}

export function statusLabel(status) {
  if (status === 'Up') return t('Up');
  if (status === 'Down') return t('Down');
  if (status === 'arp') return 'ARP';
  if (status === 'arp-down') return t('ARP down');
  return status || t('Unknown');
}

export function statusTitle(status) {
  if (status === 'Up') return t('host up');
  if (status === 'Down') return t('host down');
  if (status === 'arp') return t('arp up / ip down');
  if (status === 'arp-down') return t('host down, in arp cache');
  return status || t('unknown status');
}

export function historyRowClass(item) {
  if (item.status === 'Up') return '';
  return Number(item.duration || 0) > 180 ? 'history-alert' : 'history-muted';
}

export function scanIsActiveState(state) {
  return state === 'queued' || state === 'running';
}

export function scanRunStateClass(state) {
  return `scan-run-state${state ? ` scan-run-${state}` : ''}`;
}

export function scanRunStateIcon(state) {
  if (state === 'queued') return 'clock';
  if (state === 'complete') return 'check';
  if (state === 'failed') return 'alert-triangle';
  if (state === 'timeout') return 'clock-exclamation';
  if (state === 'cancelled') return 'ban';
  return 'point';
}

export function scanRunStateLabel(state) {
  return state ? t(state) : '-';
}

export function scanStateClass(state) {
  if (state === 'open') return 'scan-state scan-state-open';
  if (state === 'closed') return 'scan-state scan-state-closed';
  if (state === 'filtered') return 'scan-state scan-state-filtered';
  return 'scan-state';
}

export function scanStateLabel(state) {
  return state ? t(state) : '-';
}

export function activeScanDuration(scan, now = Date.now()) {
  if (!scan) return null;
  if (scan.duration !== null && scan.duration !== undefined) return scan.duration;
  const started = parseServerDate(scan.date_begin);
  return Number.isNaN(started) ? null : Math.max(0, Math.floor((now - started) / 1000));
}
