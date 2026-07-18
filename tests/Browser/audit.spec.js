import { expect, test } from './fixtures.js';

test('filters audit events and expands structured change details', async ({ page, api }) => {
  api.state.authenticated = true;
  await page.goto('/audit');

  await expect(page.getByRole('heading', { name: 'Audit log' })).toBeVisible();
  await expect(page.getByText('Updated host Core router')).toBeVisible();
  await expect(page.getByText('Failed administrator login')).toBeVisible();

  await page.getByText('View details').click();
  await expect(page.locator('.audit-details pre')).toContainText('192.0.2.53');

  await page.getByLabel('Action').selectOption('auth.login_failed');
  await expect(page.getByText('Failed administrator login')).toBeVisible();
  await expect(page.getByText('Updated host Core router')).toHaveCount(0);

  expect(api.requestsFor('GET', '/api/audit').at(-1).query).toContain('action=auth.login_failed');
});

test('keeps the audit page and navigation admin-only', async ({ page, api }) => {
  api.state.authenticated = false;
  await page.goto('/audit');

  await expect(page.getByText('Login to view the audit log.')).toBeVisible();
  await expect(page.getByRole('button', { name: 'Audit log', exact: true })).toHaveCount(0);
  expect(api.requestsFor('GET', '/api/audit')).toHaveLength(0);
});
