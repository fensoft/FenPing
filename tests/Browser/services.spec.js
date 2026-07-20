import { expect, test } from './fixtures.js';

test('pins discoveries and adds a checked manual service', async ({ page, api }) => {
  api.state.authenticated = true;
  await page.goto('/services');

  await expect(page.getByRole('heading', { name: 'Important services' })).toBeVisible();
  await expect(page.getByText('No important services')).toBeVisible();

  await page.getByTitle('Pin service').first().click();
  await expect(page.getByRole('table').first().getByText('Gateway', { exact: true })).toBeVisible();
  await expect(page.getByRole('table').last().getByText('Gateway', { exact: true })).toHaveCount(0);

  await page.getByRole('button', { name: 'Add manual service' }).click();
  await page.getByRole('dialog').getByLabel('Name').fill('Public dashboard');
  await page.getByRole('dialog').getByLabel('HTTPS URL').fill('https://dashboard.example.test/health');
  await page.getByRole('button', { name: 'Add and check' }).click();

  const important = page.getByRole('table').first();
  await expect(important.getByText('Public dashboard')).toBeVisible();
  await expect(important.getByText('Healthy')).toBeVisible();
  await expect(important.getByText('HTTP 200')).toBeVisible();
  expect(api.requestsFor('POST', '/api/services/manual')).toHaveLength(1);

  await important.getByTitle('Recheck').click();
  expect(api.requestsFor('POST', '/api/services/manual/2/check')).toHaveLength(1);

  await important.getByTitle('Edit').click();
  await page.getByRole('dialog').getByLabel('Name').fill('Public dashboard edge');
  await page.getByRole('button', { name: 'Save and check' }).click();
  await expect(important.getByText('Public dashboard edge')).toBeVisible();
  expect(api.requestsFor('PUT', '/api/services/manual/2')).toHaveLength(1);

  page.once('dialog', dialog => dialog.accept());
  await important.getByTitle('Delete').click();
  await expect(important.getByText('Public dashboard edge')).toHaveCount(0);
  expect(api.requestsFor('DELETE', '/api/services/manual/2')).toHaveLength(1);
});

test('keeps service management read-only for guests', async ({ page }) => {
  await page.goto('/services');
  await expect(page.getByText('Guest mode is read only. Login to pin or manage services.')).toBeVisible();
  await expect(page.getByRole('button', { name: 'Add manual service' })).toHaveCount(0);
  await expect(page.getByTitle('Pin service')).toHaveCount(0);
});

test('keeps the manual-service modal keyboard accessible and mobile responsive', async ({ page, api }) => {
  api.state.authenticated = true;
  await page.setViewportSize({ width: 375, height: 812 });
  await page.goto('/services');

  const add = page.getByRole('button', { name: 'Add manual service' });
  await add.click();
  const dialog = page.getByRole('dialog', { name: 'Add manual service' });
  await expect(dialog).toBeVisible();
  await expect(dialog.getByLabel('Name')).toBeFocused();
  await expect(page.locator('.app-sidebar')).toHaveAttribute('inert', '');
  await expect(page.locator('.app-sidebar')).toHaveAttribute('aria-hidden', 'true');

  await dialog.getByLabel('Type').selectOption('socks5');
  await expect(dialog.getByLabel('Port')).toHaveValue('1080');
  const hostBox = await dialog.getByLabel('Host').boundingBox();
  const portBox = await dialog.getByLabel('Port').boundingBox();
  expect(Math.abs(hostBox.x - portBox.x)).toBeLessThan(2);
  expect(portBox.y).toBeGreaterThan(hostBox.y);
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);

  await dialog.getByRole('button', { name: 'Close' }).focus();
  await dialog.getByRole('button', { name: 'Close' }).press('Shift+Tab');
  await expect(dialog.getByRole('button', { name: 'Add and check' })).toBeFocused();
  await dialog.press('Escape');
  await expect(dialog).toBeHidden();
  await expect(page.locator('.app-sidebar')).not.toHaveAttribute('inert', '');
  await expect(add).toBeFocused();
});
