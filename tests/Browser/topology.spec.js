import { expect, test } from './fixtures.js';

test('shows guest-visible observed topology with keyboard evidence inspection', async ({ page }) => {
  await page.goto('/topology');

  await expect(page.getByRole('heading', { name: 'Observed topology' })).toBeVisible();
  await expect(page.getByRole('note')).toContainText('not physical');
  await expect(page.getByRole('img', { name: 'Observed network topology graph' })).toBeVisible();
  await expect(page.getByText('fenping-demo')).toBeVisible();

  const gateway = page.getByRole('button', { name: /Router: Gateway/ });
  await gateway.focus();
  await gateway.press('Enter');
  await expect(page.locator('.topology-inspector')).toContainText('Router Labs');
  await expect(page.getByRole('button', { name: 'Open host details' })).toBeVisible();

  await page.getByRole('button', { name: 'Zoom in' }).click();
  await expect(page.locator('.topology-zoom-value')).toHaveText('120%');
  await page.getByRole('button', { name: 'Fit' }).click();
  await expect(page.locator('.topology-zoom-value')).toContainText('%');
  await page.getByRole('combobox', { name: 'Topology trace target' }).selectOption('192.0.2.20');
  await expect(page.locator('.topology-zoom-value')).toHaveText('100%');
  await expect(page.locator('.topology-evidence-table tbody tr')).toHaveCount(3);
});

test('keeps the topology workspace inside a mobile viewport', async ({ page }) => {
  await page.setViewportSize({ width: 375, height: 812 });
  await page.goto('/topology');
  await expect(page.locator('.topology-canvas')).toBeVisible();
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth)).toBe(true);
});
