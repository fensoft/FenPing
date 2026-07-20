import { expect, test } from './fixtures.js';

function visibleHostRows(page) {
  return page.locator('tr.inventory-host-row:visible');
}

async function login(page, api) {
  await page.getByRole('button', { name: 'Login' }).click();
  const dialog = page.getByRole('dialog', { name: 'Login' });
  await dialog.getByLabel('Password').fill(api.state.password);
  await dialog.getByRole('button', { name: 'Login' }).click();
}

test('filters inventory through search and combined segmented controls', async ({ page }) => {
  await page.goto('/');
  await expect(visibleHostRows(page)).toHaveCount(3);

  const search = page.getByPlaceholder('Search devices');
  await search.fill('print corp');
  await expect(visibleHostRows(page)).toHaveCount(1);
  await expect(page.getByText('Office printer', { exact: true })).toBeVisible();
  await expect(page.getByText('Gateway', { exact: true })).toBeHidden();
  await expect(page.locator('.inventory-summary')).toContainText('1/3 devices');

  await page.locator('button[title="Clear filters"]').click();
  await expect(visibleHostRows(page)).toHaveCount(3);

  await page.getByText('Down', { exact: true }).click();
  await page.getByText('Important', { exact: true }).click();
  await page.getByText('New', { exact: true }).click();

  await expect(visibleHostRows(page)).toHaveCount(1);
  await expect(page.getByText('Lobby camera', { exact: true })).toBeVisible();
  await expect(page.locator('.inventory-summary')).toContainText('1/3 devices');

  await page.reload();
  await expect(page.getByRole('group', { name: 'Status filter' }).getByRole('radio', { name: 'Down' })).toBeChecked();
  await expect(page.getByRole('group', { name: 'Importance filter' }).getByRole('radio', { name: 'Important' })).toBeChecked();
  await expect(page.getByRole('group', { name: 'New-device filter' }).getByRole('radio', { name: 'New' })).toBeChecked();
  await expect(visibleHostRows(page)).toHaveCount(1);

  await page.locator('button[title="Clear filters"]').click();
  await expect(search).toHaveValue('');
  await expect(visibleHostRows(page)).toHaveCount(3);
  await expect(page.locator('.inventory-summary')).toContainText('3 devices');
});

test('builds a download for the selected inventory dataset and format', async ({ page, api }) => {
  await page.goto('/');
  await login(page, api);

  await page.getByRole('button', { name: 'Export' }).click();
  const dialog = page.getByRole('dialog', { name: 'Inventory export' });
  await dialog.getByText('Lease history', { exact: true }).click();
  await dialog.getByText('JSON', { exact: true }).click();
  const link = dialog.getByRole('link', { name: 'Download export' });
  await expect(link).toHaveAttribute('href', '/api/exports/leases?format=json&network=192.0.2.0%2F24');
  await expect(link).toHaveAttribute('download', '');
});

test('selects filtered category members and applies a bulk tag edit', async ({ page, api }) => {
  await page.goto('/');
  await login(page, api);

  await page.locator('tr.category-row').filter({ hasText: 'Infrastructure' }).getByRole('button', { name: 'Close category' }).click();
  await page.getByRole('checkbox', { name: 'Select category Infrastructure' }).check();
  const toolbar = page.getByRole('toolbar', { name: 'Bulk actions' });
  await expect(toolbar).toContainText('2 selected');
  await expect(toolbar.getByRole('button', { name: 'Approve (0)' })).toBeDisabled();

  await toolbar.getByRole('button', { name: 'Edit tags (2)' }).click();
  const dialog = page.getByRole('dialog', { name: 'Edit tags' });
  const tagInput = dialog.locator('.host-tags-input input').first();
  await tagInput.fill('Office');
  await tagInput.press('Enter');
  await dialog.getByRole('button', { name: 'Apply' }).click();

  await expect(page.getByRole('status')).toContainText('2 changed');
  await expect(toolbar).toBeHidden();
  const requests = api.requestsFor('POST', '/api/inventory/bulk-actions');
  expect(requests).toHaveLength(1);
  expect(requests[0].body).toEqual({
    action: 'tags',
    targets: [{ kind: 'host', id: 1 }, { kind: 'host', id: 2 }],
    add_tags: ['Office'],
    remove_tags: []
  });
  expect(api.state.hosts.filter(host => host.id).every(host => host.tags.includes('Office'))).toBe(true);
});

test('bulk approval and deletion expose only eligible selected devices', async ({ page, api }) => {
  await page.goto('/');
  await login(page, api);

  await page.getByRole('checkbox', { name: 'Select Lobby camera' }).check();
  let toolbar = page.getByRole('toolbar', { name: 'Bulk actions' });
  await expect(toolbar.getByRole('button', { name: 'Approve (1)' })).toBeEnabled();
  await expect(toolbar.getByRole('button', { name: 'Delete reservations (0)' })).toBeDisabled();
  await toolbar.getByRole('button', { name: 'Approve (1)' }).click();
  await page.getByRole('dialog', { name: 'Approve devices' }).getByRole('button', { name: 'Approve' }).click();
  await expect(page.getByRole('status')).toContainText('1 changed');
  expect(api.state.hosts.find(host => host.name === 'Lobby camera').is_new).toBe(0);

  await page.getByPlaceholder('Search devices').fill('Gateway');
  await page.getByRole('checkbox', { name: 'Select all filtered devices' }).check();
  toolbar = page.getByRole('toolbar', { name: 'Bulk actions' });
  await expect(toolbar).toContainText('1 selected');
  await toolbar.getByRole('button', { name: 'Delete reservations (1)' }).click();
  await page.getByRole('dialog', { name: 'Delete reservations' }).getByRole('button', { name: 'Delete' }).click();
  await expect(page.getByRole('status')).toContainText('1 changed');
  expect(api.state.hosts.some(host => host.name === 'Gateway')).toBe(false);
});
