import { expect, test } from './fixtures.js';

const expectedVersion = process.env.VITE_FENPING_VERSION || 'dev';

async function expectNoHorizontalOverflow(page) {
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth)).toBe(true);
}

test('shows the full desktop inventory layout without overflow', async ({ page }) => {
  await page.goto('/');

  await expect(page.locator('.app-brand-icon')).toBeVisible();
  await expect(page.locator('.app-brand-favicon')).toBeHidden();
  await expect(page.locator('.app-brand-version')).toHaveText(expectedVersion);
  await expect(page.locator('.app-brand-version')).toBeVisible();
  await expect(page.getByRole('columnheader', { name: 'Vendor' })).toBeVisible();
  await expect(page.getByRole('columnheader', { name: 'Activity' })).toBeVisible();
  await expect(page.getByRole('columnheader', { name: 'Services' })).toBeVisible();
  await expect(page.locator('.inventory-mobile-meta').first()).toBeHidden();
  await expectNoHorizontalOverflow(page);
});

test('uses the compact mobile inventory and single-column modal layout', async ({ page, api }) => {
  api.state.authenticated = true;
  await page.setViewportSize({ width: 375, height: 812 });
  await page.goto('/');

  await expect(page.locator('.app-brand-icon')).toBeHidden();
  await expect(page.locator('.app-brand-favicon')).toBeVisible();
  await expect(page.locator('.app-brand-version')).toBeHidden();
  await expect(page.getByRole('columnheader', { name: 'Vendor' })).toBeHidden();
  await expect(page.getByRole('columnheader', { name: 'Activity' })).toBeHidden();
  await expect(page.getByRole('columnheader', { name: 'Services' })).toBeHidden();
  await expect(page.locator('.inventory-mobile-meta').first()).toBeVisible();
  await expect(page.locator('.inventory-action-label').first()).toBeHidden();

  const gateway = page.locator('tr.inventory-host-row').filter({ hasText: 'Gateway' });
  const editButton = gateway.getByRole('button', { name: 'Edit host' });
  await expect(editButton).toBeVisible();
  const editBox = await editButton.boundingBox();
  expect(editBox.width).toBeGreaterThanOrEqual(24);
  expect(editBox.height).toBeGreaterThanOrEqual(24);
  await editButton.click();

  const dialog = page.getByRole('dialog', { name: 'Edit host' });
  const ipBox = await dialog.locator('input[name="ip"]').boundingBox();
  const routerBox = await dialog.locator('input[name="router"]').boundingBox();
  expect(Math.abs(ipBox.x - routerBox.x)).toBeLessThan(2);
  expect(routerBox.y).toBeGreaterThan(ipBox.y);
  await expectNoHorizontalOverflow(page);
});

test('switches the application shell and modal to Arabic RTL while preserving address direction', async ({ page }) => {
  await page.goto('/');

  await page.getByRole('combobox', { name: 'Language' }).selectOption('ar');
  await expect(page.locator('html')).toHaveAttribute('lang', 'ar');
  await expect(page.locator('html')).toHaveAttribute('dir', 'rtl');
  expect(await page.evaluate(() => localStorage.getItem('fenping_locale'))).toBe('ar');
  await expect(page.getByText('جرد', { exact: true })).toBeVisible();

  const sidebarBox = await page.locator('.app-sidebar').boundingBox();
  const mainBox = await page.locator('.app-main').boundingBox();
  expect(sidebarBox.x).toBeGreaterThan(mainBox.x);
  await expect(page.locator('.inventory-ip-cell').first()).toHaveCSS('direction', 'ltr');

  await page.getByRole('button', { name: 'تسجيل الدخول' }).click();
  const dialog = page.getByRole('dialog', { name: 'تسجيل الدخول' });
  await expect(dialog).toBeVisible();
  await expect(dialog.getByLabel('كلمة المرور')).toBeFocused();
  await dialog.press('Escape');
  await expect(dialog).toBeHidden();
  await expectNoHorizontalOverflow(page);
});
