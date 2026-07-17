import { expect, test } from './fixtures.js';

test('creates and restores backups from the backups page', async ({ page, api }) => {
  api.state.authenticated = true;
  await page.goto('/backups');

  await expect(page.getByRole('heading', { name: 'Backups' })).toBeVisible();
  await expect(page.getByText('fenping-daily-20260714-022300.tgz', { exact: true })).toBeVisible();

  await page.getByRole('button', { name: 'Create backup' }).click();
  await expect(page.getByRole('status')).toHaveText('Backup created');
  await expect(page.getByText('fenping-manual-20260714-120000-abcdef.tgz', { exact: true })).toBeVisible();
  expect(api.requestsFor('POST', '/api/backups')).toHaveLength(1);

  const row = page.getByRole('row').filter({ hasText: 'fenping-daily-20260714-022300.tgz' });
  page.once('dialog', async (dialog) => {
    expect(dialog.message()).toContain('A safety backup will be created first.');
    await dialog.accept();
  });
  await row.getByRole('button', { name: 'Restore' }).click();

  await expect(page.getByRole('status')).toHaveText('Backup fenping-daily-20260714-022300.tgz restored');
  expect(api.requestsFor('POST', '/api/backups/fenping-daily-20260714-022300.tgz/restore')).toHaveLength(1);
});
