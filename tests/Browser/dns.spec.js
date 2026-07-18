import { expect, test } from './fixtures.js';

test('creates a DNS override group from imported hosts-file text', async ({ page, api }) => {
  api.state.authenticated = true;
  await page.goto('/dns');

  await expect(page.getByRole('heading', { name: 'DNS overrides' })).toBeVisible();
  await expect(page.getByRole('button', { name: /Lab services/ })).toBeVisible();
  await page.getByRole('button', { name: 'New group' }).click();
  await page.getByLabel('Name').fill('Imported records');
  await page.locator('input[type="file"]').setInputFiles({
    name: 'lab.hosts',
    mimeType: 'text/plain',
    buffer: Buffer.from('192.0.2.55 app.test app\nCNAME portal.test app.test\n')
  });
  await expect(page.getByLabel('DNS records')).toHaveValue(/CNAME portal\.test app\.test/);
  await page.getByRole('button', { name: 'Create' }).click();

  await expect(page.getByText('DNS group created')).toBeVisible();
  const requests = api.requestsFor('POST', '/api/dns/groups');
  expect(requests).toHaveLength(1);
  expect(requests[0].body).toMatchObject({ name: 'Imported records', enabled: true });
  expect(requests[0].body.contents).toContain('192.0.2.55 app.test app');
});
