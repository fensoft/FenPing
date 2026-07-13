export function inventoryNetworkUrl(cidr = '') {
  return cidr ? `/api/inventory?network=${encodeURIComponent(cidr)}` : '/api/inventory';
}

export function inventoryNetworkFallback(networks, preferred = '', dhcp = '') {
  const rows = Array.isArray(networks) ? networks : [];
  const preferredRow = rows.find((network) => network.cidr === preferred && network.selectable);
  if (preferredRow) return preferredRow.cidr;
  const dhcpRow = rows.find((network) => network.cidr === dhcp && network.selectable);
  if (dhcpRow) return dhcpRow.cidr;
  return rows.find((network) => network.selectable)?.cidr || '';
}

export function inventoryNetworkIsDhcp(selected, dhcp) {
  return selected !== '' && selected === dhcp;
}

export function inventoryNetworkLabel(network, translate = (value) => value) {
  const names = Array.isArray(network?.docker_network_names)
    ? [...new Set(network.docker_network_names.filter((name) => typeof name === 'string' && name.trim() !== '').map((name) => name.trim()))]
    : [];
  const details = [...names];
  if (network?.dhcp) details.push(translate('DHCP'));
  else if (!network?.routed) details.push(translate('Not routed'));
  const cidr = typeof network?.cidr === 'string' ? network.cidr : '';
  return details.length > 0 ? `${cidr} (${details.join(' · ')})` : cidr;
}
