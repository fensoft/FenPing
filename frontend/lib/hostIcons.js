export const hostIconOptions = Object.freeze([
  { value: 'desktop', icon: 'device-desktop', label: 'Desktop' },
  { value: 'laptop', icon: 'device-laptop', label: 'Laptop' },
  { value: 'mobile', icon: 'device-mobile', label: 'Mobile' },
  { value: 'printer', icon: 'printer', label: 'Printer' },
  { value: 'camera', icon: 'camera', label: 'Camera' },
  { value: 'router', icon: 'router', label: 'Router' },
  { value: 'server', icon: 'server', label: 'Server' },
  { value: 'database', icon: 'database', label: 'Database' },
  { value: 'lightbulb', icon: 'bulb', label: 'Lightbulb' },
  { value: 'television', icon: 'device-tv', label: 'Television' },
  { value: 'game-controller', icon: 'device-gamepad', label: 'Game controller' },
  { value: 'home', icon: 'home', label: 'Home' }
]);

const iconNames = new Map(hostIconOptions.map((option) => [option.value, option.icon]));

export function normalizeHostIcon(value) {
  const normalized = typeof value === 'string' ? value.trim() : '';
  return iconNames.has(normalized) ? normalized : '';
}

export function hostIconName(value) {
  return iconNames.get(normalizeHostIcon(value)) || '';
}

export function hostIconLabel(value) {
  return hostIconOptions.find((option) => option.value === normalizeHostIcon(value))?.label || '';
}
