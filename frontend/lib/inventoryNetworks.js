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
