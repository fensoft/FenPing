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
