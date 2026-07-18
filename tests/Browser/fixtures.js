import { expect, test as base } from '@playwright/test';

const NETWORK = '192.0.2';
const CIDR = `${NETWORK}.0/24`;

function seedHosts() {
  return [
    {
      id: 1,
      name: 'Gateway',
      ip: `${NETWORK}.10`,
      mac: '02:00:00:00:00:10',
      vendor: 'Router Labs',
      category: 'Infrastructure',
      category_ip: `${NETWORK}.1`,
      status: 'Up',
      date: '2026-07-14 10:00:00',
      important: 1,
      is_new: 0,
      repeater: 1,
      web: 1,
      dhcp_managed: 1,
      network_is_dhcp: 1,
      router: '',
      dns: 'gateway.lan',
      scan_profile: 'standard',
      scan_interval_hours: 24,
      scan: null
    },
    {
      id: 2,
      name: 'Office printer',
      ip: `${NETWORK}.20`,
      mac: '02:00:00:00:00:20',
      vendor: 'Print Corp',
      status: 'Down',
      date: '2026-07-14 09:00:00',
      important: 0,
      is_new: 0,
      repeater: 0,
      web: 0,
      dhcp_managed: 1,
      network_is_dhcp: 1,
      router: '',
      dns: 'printer.lan',
      scan_profile: 'lightweight',
      scan_interval_hours: 12,
      stability: { current_seconds: (2 * 365 + 42) * 24 * 60 * 60 },
      scan: null
    },
    {
      id: null,
      name: 'Lobby camera',
      ip: `${NETWORK}.30`,
      mac: '02:00:00:00:00:30',
      vendor: 'Vision Systems',
      category: 'Cameras',
      category_ip: `${NETWORK}.25`,
      status: 'Down',
      date: '2026-07-14 08:00:00',
      important: 1,
      is_new: 1,
      repeater: 0,
      web: 0,
      dhcp_managed: 0,
      network_is_dhcp: 1,
      stability: { current_seconds: 3 * 24 * 60 * 60 },
      scan: null
    }
  ];
}

function clone(value) {
  return structuredClone(value);
}

function normalizedMac(value) {
  return String(value || '').replace(/[^a-f0-9]/gi, '').toLowerCase();
}

function fullIp(value) {
  const ip = String(value || '');
  return ip.includes('.') ? ip : `${NETWORK}.${ip}`;
}

function responseHost(host) {
  return {
    router: '',
    dns: '',
    important: 0,
    repeater: 0,
    web: 0,
    netboot_image_id: null,
    scan_profile: 'standard',
    scan_interval_hours: 24,
    ...clone(host)
  };
}

function detailFor(host) {
  return {
    host: responseHost(host),
    latest_scan: null,
    scans: [],
    history: { rows: [], summary: null },
    netboot_image: null
  };
}

function topologyPayload() {
  return {
    generated_at: '2026-07-14T10:00:00+00:00',
    disclaimer: 'Connections are traceroute or route-table observations and gateway configurations, not verified physical links.',
    route_observation_status: 'ok',
    summary: {
      network_count: 1, node_count: 4, connection_count: 3,
      trace_target_count: 1, router_count: 1, host_count: 3,
      untraced_host_count: 1, last_observed_at: '2026-07-14 09:55:00'
    },
    networks: [{
      id: `network:${CIDR}`, cidr: CIDR, dhcp: true, routed: true,
      docker_network_names: ['fenping-demo'], host_count: 3, untraced_host_count: 1,
      route: { destination: CIDR, gateway: null, interface: 'eth0', source: `${NETWORK}.100` }
    }],
    nodes: [
      { id: `ip:${NETWORK}.100`, type: 'appliance', ip: `${NETWORK}.100`, label: 'FenPing', network: CIDR, roles: ['appliance'] },
      { id: `ip:${NETWORK}.10`, type: 'router', ip: `${NETWORK}.10`, label: 'Gateway', network: CIDR, roles: ['hop', 'host', 'router'], hostname: 'gateway.lan', host: { id: 1, name: 'Gateway', mac: '02:00:00:00:00:10', status: 'Up', vendor: 'Router Labs', last_seen: '2026-07-14 10:00:00' } },
      { id: `ip:${NETWORK}.20`, type: 'host', ip: `${NETWORK}.20`, label: 'Office printer', network: CIDR, roles: ['host', 'target'], hostname: 'printer.lan', host: { id: 2, name: 'Office printer', mac: '02:00:00:00:00:20', status: 'Down', vendor: 'Print Corp', last_seen: '2026-07-14 09:00:00' } },
      { id: `network:${CIDR}`, type: 'network', ip: null, label: CIDR, network: CIDR, roles: ['network'] }
    ],
    connections: [
      { id: 'connection:trace-1', kind: 'traceroute_observation', from: `ip:${NETWORK}.100`, to: `ip:${NETWORK}.10`, label: 'Traceroute observation', observed_at: '2026-07-14 09:55:00', missing_hops: 0, networks: [CIDR], targets: [`${NETWORK}.20`], scan_ids: [42], observation_count: 1, evidence: [{ source: 'traceroute', scan_id: 42, target_ip: `${NETWORK}.20`, ttl_from: 0, ttl_to: 1, rtt: 0.2 }] },
      { id: 'connection:trace-2', kind: 'traceroute_observation', from: `ip:${NETWORK}.10`, to: `ip:${NETWORK}.20`, label: 'Traceroute observation', observed_at: '2026-07-14 09:55:00', missing_hops: 0, networks: [CIDR], targets: [`${NETWORK}.20`], scan_ids: [42], observation_count: 1, evidence: [{ source: 'traceroute', scan_id: 42, target_ip: `${NETWORK}.20`, ttl_from: 1, ttl_to: 2, rtt: 0.5 }] },
      { id: 'connection:route', kind: 'route_observation', from: `ip:${NETWORK}.100`, to: `network:${CIDR}`, label: 'Route-table observation', observed_at: '2026-07-14T10:00:00+00:00', missing_hops: 0, networks: [CIDR], targets: [], scan_ids: [], observation_count: 1, evidence: [{ source: 'route_table', network: CIDR, destination: CIDR, gateway: null, interface: 'eth0', source_address: `${NETWORK}.100` }] }
    ],
    paths: [{ id: 'trace:42', scan_id: 42, target_ip: `${NETWORK}.20`, target_node_id: `ip:${NETWORK}.20`, network: CIDR, mode: 'standard', protocol: 'tcp', port: 80, observed_at: '2026-07-14 09:55:00', reached_target: true, node_ids: [`ip:${NETWORK}.100`, `ip:${NETWORK}.10`, `ip:${NETWORK}.20`] }]
  };
}

async function fulfillJson(route, payload, status = 200) {
  await route.fulfill({
    status,
    contentType: 'application/json',
    body: JSON.stringify(payload)
  });
}

function requestBody(request) {
  const raw = request.postData();
  if (!raw) return null;
  try {
    return JSON.parse(raw);
  } catch {
    return raw;
  }
}

function createApi() {
  const state = {
    authenticated: false,
    configured: true,
    password: 'correct horse battery staple',
    hosts: seedHosts(),
    backups: [{
      filename: 'fenping-daily-20260714-022300.tgz',
      kind: 'daily',
      created_at: '2026-07-14T02:23:00+00:00',
      size: 4096,
      sha256: '1234567890abcdef',
      verification: { status: 'verified', restore_tested_at: '2026-07-14T02:24:00+00:00', message: null },
      retention_roles: ['daily'],
      download_url: '/api/backups/fenping-daily-20260714-022300.tgz/file'
    }],
    auditEvents: [
      {
        id: 2,
        occurred_at: '2026-07-14 12:05:00',
        actor: 'admin',
        remote_address: '192.0.2.55',
        user_agent: 'FenPing browser test',
        action: 'host.updated',
        resource_type: 'host',
        resource_id: '1',
        summary: 'Updated host Core router',
        details: { changes: { dns: { before: '', after: '192.0.2.53' } } }
      },
      {
        id: 1,
        occurred_at: '2026-07-14 12:00:00',
        actor: 'anonymous',
        remote_address: '198.51.100.10',
        user_agent: 'FenPing browser test',
        action: 'auth.login_failed',
        resource_type: 'session',
        resource_id: null,
        summary: 'Failed administrator login',
        details: {}
      }
    ],
    dnsGroups: [{
      id: 1,
      name: 'Lab services',
      enabled: true,
      contents: '192.0.2.20 printer.test printer',
      record_count: 1,
      created_at: '2026-07-14 10:00:00',
      updated_at: '2026-07-14 10:00:00'
    }],
    nextDnsGroupId: 2,
    nextScanId: 100
  };
  const requests = [];
  const failures = [];
  const unhandled = [];

  return {
    state,
    requests,
    unhandled,
    failNext(method, path, message = 'Mutation failed', status = 500) {
      failures.push({ method, path, message, status });
    },
    requestsFor(method, path) {
      return requests.filter((request) => request.method === method && request.path === path);
    },
    takeFailure(method, path) {
      const index = failures.findIndex((failure) => failure.method === method && failure.path === path);
      return index === -1 ? null : failures.splice(index, 1)[0];
    }
  };
}

async function handleApi(route, api) {
  const request = route.request();
  const url = new URL(request.url());
  const method = request.method();
  const path = url.pathname;
  const body = requestBody(request);
  api.requests.push({ method, path, query: url.search, body });

  const failure = api.takeFailure(method, path);
  if (failure) {
    await fulfillJson(route, { error: failure.message }, failure.status);
    return;
  }

  if (method === 'GET' && path === '/api/auth/session') {
    await fulfillJson(route, { authenticated: api.state.authenticated, configured: api.state.configured });
    return;
  }

  if (method === 'POST' && path === '/api/auth/login') {
    if (body?.password !== api.state.password) {
      await fulfillJson(route, { error: 'Invalid password' }, 403);
      return;
    }
    api.state.authenticated = true;
    await fulfillJson(route, { authenticated: true, configured: true });
    return;
  }

  if (method === 'POST' && path === '/api/auth/logout') {
    api.state.authenticated = false;
    await fulfillJson(route, { authenticated: false, configured: true });
    return;
  }

  if (method === 'GET' && path === '/api/ipam/conflicts') {
    await fulfillJson(route, { status: 'ok', conflicts: [] });
    return;
  }

  if (method === 'GET' && path === '/api/audit') {
    if (!api.state.authenticated) { await fulfillJson(route, { error: 'login required' }, 403); return; }
    const action = url.searchParams.get('action') || '';
    const resourceType = url.searchParams.get('resource_type') || '';
    const search = (url.searchParams.get('search') || '').toLowerCase();
    const perPage = Number(url.searchParams.get('per_page') || 50);
    const page = Number(url.searchParams.get('page') || 1);
    const all = api.state.auditEvents;
    const filtered = all.filter(event =>
      (!action || event.action === action)
      && (!resourceType || event.resource_type === resourceType)
      && (!search || `${event.summary} ${event.resource_id || ''} ${event.remote_address || ''}`.toLowerCase().includes(search))
    );
    const pages = Math.max(1, Math.ceil(filtered.length / perPage));
    const selectedPage = Math.min(Math.max(1, page), pages);
    await fulfillJson(route, {
      events: clone(filtered.slice((selectedPage - 1) * perPage, selectedPage * perPage)),
      pagination: { page: selectedPage, per_page: perPage, pages, total: filtered.length },
      filters: {
        actions: [...new Set(all.map(event => event.action))].sort(),
        resource_types: [...new Set(all.map(event => event.resource_type))].sort()
      }
    });
    return;
  }

  if (method === 'GET' && path === '/api/topology') {
    await fulfillJson(route, topologyPayload());
    return;
  }

  if (method === 'GET' && path === '/api/dns/groups') {
    await fulfillJson(route, { groups: clone(api.state.dnsGroups) });
    return;
  }

  if (method === 'POST' && path === '/api/dns/groups') {
    const group = {
      id: api.state.nextDnsGroupId++,
      name: body?.name || '',
      enabled: Boolean(body?.enabled),
      contents: body?.contents || '',
      record_count: String(body?.contents || '').split(/\r?\n/).filter(line => line.trim() && !line.trim().startsWith('#')).length,
      created_at: '2026-07-14 10:00:00',
      updated_at: '2026-07-14 10:00:00'
    };
    api.state.dnsGroups.push(group);
    await fulfillJson(route, { group: clone(group), log: 'dnsmasq reloaded' });
    return;
  }

  const dnsGroupMatch = path.match(/^\/api\/dns\/groups\/(\d+)$/);
  if (method === 'PUT' && dnsGroupMatch) {
    const group = api.state.dnsGroups.find(candidate => Number(candidate.id) === Number(dnsGroupMatch[1]));
    if (!group) { await fulfillJson(route, { error: 'DNS group not found' }, 404); return; }
    Object.assign(group, clone(body), {
      id: group.id,
      enabled: Boolean(body?.enabled),
      record_count: String(body?.contents || '').split(/\r?\n/).filter(line => line.trim() && !line.trim().startsWith('#')).length,
      updated_at: '2026-07-14 10:01:00'
    });
    await fulfillJson(route, { group: clone(group), log: 'dnsmasq reloaded' });
    return;
  }

  if (method === 'DELETE' && dnsGroupMatch) {
    const index = api.state.dnsGroups.findIndex(candidate => Number(candidate.id) === Number(dnsGroupMatch[1]));
    if (index === -1) { await fulfillJson(route, { error: 'DNS group not found' }, 404); return; }
    api.state.dnsGroups.splice(index, 1);
    await fulfillJson(route, { deleted: true, log: 'dnsmasq reloaded' });
    return;
  }

  if (method === 'GET' && path === '/api/inventory') {
    await fulfillJson(route, {
      network: NETWORK,
      dhcp_network: CIDR,
      selected_network: CIDR,
      networks: [{ cidr: CIDR, dhcp: true, selectable: true }],
      hosts: clone(api.state.hosts)
    });
    return;
  }

  const exportMatch = path.match(/^\/api\/exports\/(hosts|leases|services|scan_changes|uptime_history)$/);
  if (method === 'GET' && exportMatch) {
    const format = url.searchParams.get('format') === 'json' ? 'json' : 'csv';
    await route.fulfill({
      status: 200,
      headers: {
        'Content-Type': format === 'json' ? 'application/json; charset=utf-8' : 'text/csv; charset=utf-8',
        'Content-Disposition': `attachment; filename="fenping-${exportMatch[1].replaceAll('_', '-')}-192-0-2-0-24-20260714-120000.${format}"`
      },
      body: format === 'json'
        ? JSON.stringify({ format: 'fenping-inventory-export', dataset: exportMatch[1], network: CIDR, count: 1, records: [{}] })
        : 'ip,name\n192.0.2.10,Gateway\n'
    });
    return;
  }

  if (method === 'POST' && path === '/api/ping/refresh') {
    await fulfillJson(route, { status: 'ok' });
    return;
  }

  const scanMatch = path.match(/^\/api\/scans\/(\d+\.\d+\.\d+\.\d+)$/);
  if (method === 'POST' && scanMatch) {
    const id = api.state.nextScanId++;
    await fulfillJson(route, {
      created: true,
      metadata: {
        id,
        ip: scanMatch[1],
        mode: body?.profile || 'standard',
        state: 'queued'
      }
    }, 202);
    return;
  }

  const scanStatusMatch = path.match(/^\/api\/scans\/(\d+\.\d+\.\d+\.\d+)\/status$/);
  if (method === 'GET' && scanStatusMatch) {
    await fulfillJson(route, {
      id: Number(url.searchParams.get('id') || api.state.nextScanId - 1),
      ip: scanStatusMatch[1],
      mode: 'standard',
      state: 'complete',
      result_changed: false
    });
    return;
  }

  if (method === 'GET' && path === '/api/netboot/images') {
    await fulfillJson(route, { images: [] });
    return;
  }

  if (method === 'GET' && path === '/api/backups') {
    await fulfillJson(route, { backups: clone(api.state.backups), storage: { same_filesystem: false } });
    return;
  }

  if (method === 'POST' && path === '/api/backups') {
    const filename = 'fenping-manual-20260714-120000-abcdef.tgz';
    api.state.backups.unshift({
      filename,
      kind: 'manual',
      created_at: '2026-07-14T12:00:00+00:00',
      size: 8192,
      sha256: 'abcdef1234567890',
      verification: { status: 'unverified', restore_tested_at: null, message: null },
      retention_roles: [],
      download_url: `/api/backups/${filename}/file`
    });
    await fulfillJson(route, { created: filename });
    return;
  }

  const backupRestoreMatch = path.match(/^\/api\/backups\/([^/]+)\/restore$/);
  if (method === 'POST' && backupRestoreMatch) {
    const filename = decodeURIComponent(backupRestoreMatch[1]);
    await fulfillJson(route, { restored: filename, safety_backup: 'fenping-before-restore-20260714-120100-fedcba.tgz' });
    return;
  }

  const detailMatch = path.match(/^\/api\/hosts\/(\d+)\/detail$/);
  if (method === 'GET' && detailMatch) {
    const host = api.state.hosts.find((candidate) => Number(candidate.id) === Number(detailMatch[1]));
    await fulfillJson(route, host ? detailFor(host) : { error: 'Host not found' }, host ? 200 : 404);
    return;
  }

  const byIpDetailMatch = path.match(/^\/api\/hosts\/by-ip\/(.+)\/detail$/);
  if (method === 'GET' && byIpDetailMatch) {
    const ip = decodeURIComponent(byIpDetailMatch[1]);
    const host = api.state.hosts.find((candidate) => candidate.ip === ip);
    await fulfillJson(route, host ? detailFor(host) : { error: 'Host not found' }, host ? 200 : 404);
    return;
  }


  const hostMetadataMatch = path.match(/^\/api\/hosts\/(\d+)\/metadata$/);
  if (method === 'PUT' && hostMetadataMatch) {
    const host = api.state.hosts.find((candidate) => Number(candidate.id) === Number(hostMetadataMatch[1]));
    if (!host) {
      await fulfillJson(route, { error: 'Host not found' }, 404);
      return;
    }
    Object.assign(host, clone(body), {
      important: body?.important || 0,
      web: body?.web || 0
    });
    await fulfillJson(route, { saved: true, host: responseHost(host) });
    return;
  }
  const hostMatch = path.match(/^\/api\/hosts\/(\d+)$/);
  if (method === 'GET' && hostMatch) {
    const host = api.state.hosts.find((candidate) => Number(candidate.id) === Number(hostMatch[1]));
    await fulfillJson(route, host ? responseHost(host) : { error: 'Host not found' }, host ? 200 : 404);
    return;
  }

  if (method === 'POST' && path === '/api/hosts') {
    const nextId = Math.max(0, ...api.state.hosts.map((host) => Number(host.id || 0))) + 1;
    const existing = api.state.hosts.find((host) => normalizedMac(host.mac) === normalizedMac(body?.mac));
    const host = existing || {};
    Object.assign(host, {
      id: nextId,
      name: existing?.name || '',
      ip: fullIp(body?.ip),
      mac: body?.mac || '',
      vendor: existing?.vendor || '',
      status: existing?.status || '',
      date: existing?.date || '',
      important: existing?.important || 0,
      is_new: 0,
      repeater: existing?.repeater || 0,
      web: existing?.web || 0,
      dhcp_managed: 1,
      network_is_dhcp: 1,
      router: '',
      dns: '',
      scan_profile: 'standard',
      scan_interval_hours: 24,
      scan: existing?.scan || null
    });
    if (!existing) api.state.hosts.push(host);
    await fulfillJson(route, { id: nextId });
    return;
  }

  if (method === 'PUT' && hostMatch) {
    const host = api.state.hosts.find((candidate) => Number(candidate.id) === Number(hostMatch[1]));
    if (!host) {
      await fulfillJson(route, { error: 'Host not found' }, 404);
      return;
    }
    Object.assign(host, clone(body), {
      ip: fullIp(body?.ip),
      important: body?.important || 0,
      repeater: body?.repeater || 0,
      web: body?.web || 0,
      dhcp_managed: 1,
      network_is_dhcp: 1,
      is_new: 0
    });
    await fulfillJson(route, responseHost(host));
    return;
  }

  if (method === 'DELETE' && hostMatch) {
    const index = api.state.hosts.findIndex((candidate) => Number(candidate.id) === Number(hostMatch[1]));
    if (index === -1) {
      await fulfillJson(route, { error: 'Host not found' }, 404);
      return;
    }
    api.state.hosts.splice(index, 1);
    await fulfillJson(route, { deleted: true });
    return;
  }

  api.unhandled.push(`${method} ${path}${url.search}`);
  await fulfillJson(route, { error: `Unhandled browser-test API request: ${method} ${path}` }, 501);
}

export const test = base.extend({
  api: [async ({ page }, use) => {
    const api = createApi();

    await page.addInitScript(() => {
      class SilentEventSource extends EventTarget {
        static CONNECTING = 0;
        static OPEN = 1;
        static CLOSED = 2;

        constructor(url) {
          super();
          this.url = String(url);
          this.readyState = SilentEventSource.OPEN;
          this.withCredentials = false;
          window.__fenpingEventSources ||= [];
          window.__fenpingEventSources.push(this);
        }

        close() {
          this.readyState = SilentEventSource.CLOSED;
        }
      }

      Object.defineProperty(window, 'EventSource', {
        configurable: true,
        writable: true,
        value: SilentEventSource
      });
    });

    await page.route('**/api/**', (route) => handleApi(route, api));
    await use(api);
    expect(api.unhandled, 'all browser API requests should be handled by the fixture').toEqual([]);
  }, { auto: true }]
});

export { expect } from '@playwright/test';
