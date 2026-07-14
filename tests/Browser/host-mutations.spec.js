import { expect, test } from './fixtures.js';

function hostRow(page, name) {
  return page.locator('tr.inventory-host-row').filter({ hasText: name });
}

test('creates, edits, and deletes a host through refreshed inventory state', async ({ page, api }) => {
  api.state.authenticated = true;
  await page.goto('/');

  const camera = hostRow(page, 'Lobby camera');
  await camera.getByRole('button', { name: 'Create host' }).click();

  let dialog = page.getByRole('dialog', { name: 'Create host' });
  await expect(dialog.locator('input[name="mac"]')).toHaveValue('02:00:00:00:00:30');
  await dialog.locator('input[name="ip"]').fill('31');
  await dialog.getByRole('button', { name: 'Create' }).click();

  dialog = page.getByRole('dialog', { name: 'Edit host' });
  await expect(dialog).toBeVisible();
  expect(api.requestsFor('POST', '/api/hosts')).toHaveLength(1);
  expect(api.requestsFor('POST', '/api/hosts')[0].body).toEqual({
    mac: '02:00:00:00:00:30',
    ip: '31'
  });

  await dialog.locator('input[name="name"]').fill('Lobby entrance camera');
  await dialog.locator('select[name="scan_profile"]').selectOption('deep');
  await dialog.locator('input[name="scan_interval_hours"]').fill('48');
  await dialog.getByRole('checkbox', { name: 'Web' }).check();
  await dialog.getByRole('button', { name: 'Save' }).click();

  await expect(dialog).toBeHidden();
  await expect(page.getByText('Lobby entrance camera', { exact: true })).toBeVisible();
  await expect(page.getByRole('status')).toHaveText('Saved');

  const update = api.requestsFor('PUT', '/api/hosts/3');
  expect(update).toHaveLength(1);
  expect(update[0].body).toMatchObject({
    ip: '31',
    mac: '02:00:00:00:00:30',
    name: 'Lobby entrance camera',
    important: 1,
    web: 1,
    scan_profile: 'deep',
    scan_interval_hours: 48
  });

  const updatedRow = hostRow(page, 'Lobby entrance camera');
  await updatedRow.getByRole('button', { name: 'Edit host' }).click();
  dialog = page.getByRole('dialog', { name: 'Edit host' });
  await dialog.getByRole('button', { name: 'Delete' }).click();

  const confirmation = page.getByRole('dialog', { name: 'Delete host' });
  await expect(confirmation).toContainText('Lobby entrance camera');
  await confirmation.getByRole('button', { name: 'Delete' }).click();

  await expect(confirmation).toBeHidden();
  await expect(page.getByText('Lobby entrance camera', { exact: true })).toBeHidden();
  await expect(page.getByRole('status')).toHaveText('Deleted');
  expect(api.requestsFor('DELETE', '/api/hosts/3')).toHaveLength(1);
});

test('keeps the edit modal open and reports a failed mutation', async ({ page, api }) => {
  api.state.authenticated = true;
  api.failNext('PUT', '/api/hosts/1', 'Database unavailable');
  await page.goto('/');

  await hostRow(page, 'Gateway').getByRole('button', { name: 'Edit host' }).click();
  const dialog = page.getByRole('dialog', { name: 'Edit host' });
  await dialog.locator('input[name="name"]').fill('Broken update');
  await dialog.getByRole('button', { name: 'Save' }).click();

  await expect(dialog).toBeVisible();
  await expect(dialog.getByRole('alert')).toHaveText('Database unavailable');
  expect(api.state.hosts.find((host) => host.id === 1).name).toBe('Gateway');
  expect(api.requestsFor('PUT', '/api/hosts/1')).toHaveLength(1);
});

test('queues the selected scan profile from Inventory', async ({ page, api }) => {
  api.state.authenticated = true;
  await page.goto('/');

  await hostRow(page, 'Gateway').getByRole('button', { name: 'Scan host' }).click();
  const dialog = page.getByRole('dialog', { name: 'Start scan' });
  await expect(dialog).toBeVisible();
  await dialog.getByRole('button', { name: /Standard/ }).click();

  await expect.poll(() => api.requestsFor('POST', '/api/scans/192.0.2.10')).toHaveLength(1);
  expect(api.requestsFor('POST', '/api/scans/192.0.2.10')[0].body).toEqual({ profile: 'standard' });
  await expect(page.getByRole('status')).toHaveText('Standard scan complete, no changes');
});

test('offers metadata-only editing for a named record outside the DHCP network', async ({ page, api }) => {
  api.state.authenticated = true;
  api.state.hosts.push({
    id: 9,
    name: 'Legacy remote service',
    display_name: '',
    ip: '198.51.100.40',
    mac: '02:00:00:00:01:40',
    vendor: 'Example',
    status: 'Up',
    date: '2026-07-14 10:00:00',
    important: 0,
    is_new: 0,
    repeater: 0,
    web: 0,
    dhcp_managed: 1,
    network_is_dhcp: 0,
    metadata_editable: 1,
    device_identity: { network: '198.51.100.0/24', container: 'Legacy remote service' },
    scan_profile: 'standard',
    scan_interval_hours: 24,
    notes: '',
    location: '',
    owner: '',
    model: '',
    icon: null,
    tags: [],
    scan: null
  });
  await page.goto('/');

  const remote = hostRow(page, 'Legacy remote service');
  await expect(remote.getByRole('button', { name: 'Edit host' })).toHaveCount(0);
  await remote.getByRole('button', { name: 'Edit metadata' }).click();

  const dialog = page.getByRole('dialog', { name: 'Edit metadata' });
  await expect(dialog).toBeVisible();
  await expect(dialog.getByLabel('Network')).toHaveCount(0);
  await expect(dialog.getByLabel('Container')).toHaveCount(0);
  await expect(dialog.getByLabel('IP')).toBeDisabled();
  await expect(dialog.locator('input[name="mac"]')).toHaveCount(0);
  await expect(dialog.locator('input[name="name"]')).toHaveCount(0);
  await expect(dialog.locator('input[name="display_name"]')).toHaveValue('Legacy remote service');

  await dialog.locator('input[name="display_name"]').fill('Remote edge');
  await dialog.locator('select[name="scan_profile"]').selectOption('deep');
  await dialog.locator('input[name="scan_interval_hours"]').fill('0');
  await dialog.locator('input[name="location"]').fill('Remote rack');
  await dialog.locator('input[name="owner"]').fill('Platform');
  await dialog.locator('input[name="model"]').fill('Edge 2000');
  await dialog.locator('textarea[name="notes"]').fill('Metadata only');
  await dialog.getByPlaceholder('Add tags').fill('Remote, Server');
  await dialog.getByPlaceholder('Add tags').press('Enter');
  await dialog.getByRole('checkbox', { name: 'Important' }).check();
  await dialog.getByRole('checkbox', { name: 'Web' }).check();
  await dialog.getByRole('button', { name: 'Save' }).click();

  await expect(dialog).toBeHidden();
  await expect(page.getByRole('status')).toHaveText('Saved');
  await expect(page.getByText('Remote edge', { exact: true })).toBeVisible();

  const updates = api.requestsFor('PUT', '/api/hosts/9/metadata');
  expect(updates).toHaveLength(1);
  expect(updates[0].body).toEqual({
    display_name: 'Remote edge',
    important: 1,
    web: 1,
    scan_profile: 'deep',
    scan_interval_hours: 0,
    notes: 'Metadata only',
    location: 'Remote rack',
    owner: 'Platform',
    model: 'Edge 2000',
    icon: null,
    tags: ['Remote', 'Server']
  });
  expect(api.requestsFor('PUT', '/api/hosts/9')).toHaveLength(0);

  await hostRow(page, 'Remote edge').click();
  const details = page.getByRole('dialog', { name: 'Host details' });
  await expect(details.getByRole('button', { name: 'Edit metadata' })).toBeVisible();
  await expect(details.locator('dt').filter({ hasText: /^Display name$/ })).toBeVisible();
  await expect(details.locator('dd').filter({ hasText: /^Remote edge$/ })).toBeVisible();
  await expect(details.locator('dt').filter({ hasText: /^Name$/ })).toHaveCount(0);
  await expect(details.locator('dt').filter({ hasText: /^Network$/ })).toHaveCount(0);
  await expect(details.locator('dt').filter({ hasText: /^Container$/ })).toHaveCount(0);
  await expect(details.locator('dt').filter({ hasText: /^Router$/ })).toHaveCount(0);
  await expect(details.locator('dt').filter({ hasText: /^DNS$/ })).toHaveCount(0);
  await expect(details.locator('dt').filter({ hasText: /^Netboot$/ })).toHaveCount(0);
});
