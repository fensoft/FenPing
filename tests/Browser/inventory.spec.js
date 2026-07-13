import { expect, test } from './fixtures.js';

function visibleHostRows(page) {
  return page.locator('tr.inventory-host-row:visible');
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
