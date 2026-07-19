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
  const deviceBox = await page.getByRole('columnheader', { name: 'Device' }).boundingBox();
  const ipBox = await page.getByRole('columnheader', { name: 'IP', exact: true }).boundingBox();
  const vendorBox = await page.getByRole('columnheader', { name: 'Vendor' }).boundingBox();
  const activityBox = await page.getByRole('columnheader', { name: 'Activity' }).boundingBox();
  const servicesBox = await page.getByRole('columnheader', { name: 'Services' }).boundingBox();
  expect(deviceBox.width / vendorBox.width).toBeCloseTo(17 / 20, 1);
  expect(Math.abs(ipBox.width - servicesBox.width)).toBeLessThan(2);
  expect(activityBox.width).toBeGreaterThan(ipBox.width);
  expect(deviceBox.width).toBeGreaterThan(activityBox.width);
  const oldDownActivity = page.getByText('Down 2y 42d', { exact: true });
  await expect(oldDownActivity).toBeVisible();
  const oldDownRow = page.locator('tr.inventory-host-row').filter({ hasText: 'Office printer' });
  await expect(oldDownRow).toHaveClass(/activity-down-over-month/);
  await expect(oldDownRow.locator('td').first()).toHaveCSS('color', 'rgb(31, 41, 55)');
  await expect(oldDownRow.locator('td').first()).toHaveCSS('background-color', 'rgba(0, 0, 0, 0)');
  const recentDownRow = page.locator('tr.inventory-host-row').filter({ hasText: 'Lobby camera' });
  await expect(recentDownRow).toHaveClass(/activity-down-under-week/);
  await expect(recentDownRow.locator('td').first()).toHaveCSS('color', 'rgb(107, 114, 128)');
  await expect(recentDownRow.locator('td').first()).toHaveCSS('background-color', 'rgb(255, 246, 246)');
  await expect(page.locator('tr.inventory-host-row').filter({ hasText: 'Gateway' })).not.toHaveClass(/activity-down-/);
  await expect(page.locator('.inventory-mobile-meta').first()).toBeHidden();
  await expectNoHorizontalOverflow(page);
});

test('customizes and persists inventory columns and Down thresholds', async ({ page }) => {
  await page.goto('/');

  const initialStatusWidth = (await page.locator('.inventory-table thead th').first().boundingBox()).width;
  const initialIpWidth = (await page.getByRole('columnheader', { name: 'IP', exact: true }).boundingBox()).width;
  const ipResizeBox = await page.getByRole('separator', { name: 'Resize IP column' }).boundingBox();
  await page.mouse.move(ipResizeBox.x + ipResizeBox.width / 2, ipResizeBox.y + ipResizeBox.height / 2);
  await page.mouse.down();
  await page.mouse.move(ipResizeBox.x + ipResizeBox.width / 2 + 80, ipResizeBox.y + ipResizeBox.height / 2, { steps: 5 });
  await page.mouse.up();
  const draggedIpWidth = (await page.getByRole('columnheader', { name: 'IP', exact: true }).boundingBox()).width;
  const draggedStatusWidth = (await page.locator('.inventory-table thead th').first().boundingBox()).width;
  expect(draggedIpWidth).toBeGreaterThan(initialIpWidth + 30);
  expect(Math.abs(draggedStatusWidth - initialStatusWidth)).toBeLessThan(1);

  await page.getByRole('button', { name: 'Columns' }).click();
  const dialog = page.getByRole('dialog', { name: 'Inventory columns' });
  await expect(dialog).toBeVisible();
  await dialog.getByRole('checkbox', { name: 'Vendor' }).uncheck();
  await dialog.getByLabel('IP width').fill('24');
  await dialog.getByRole('button', { name: 'Move Services up' }).click();
  await dialog.getByRole('button', { name: 'Move Services up' }).click();
  await dialog.getByLabel('Light gray under').fill('2');
  await dialog.getByLabel('Medium gray under').fill('10');
  await dialog.getByRole('button', { name: 'Save layout' }).click();

  await expect(dialog).toBeHidden();
  await expect(page.getByRole('columnheader', { name: 'Vendor' })).toHaveCount(0);
  const customizedIpWidth = (await page.getByRole('columnheader', { name: 'IP', exact: true }).boundingBox()).width;
  const customizedStatusWidth = (await page.locator('.inventory-table thead th').first().boundingBox()).width;
  expect(customizedIpWidth).toBeGreaterThan(initialIpWidth + 30);
  expect(Math.abs(customizedStatusWidth - initialStatusWidth)).toBeLessThan(1);
  const headings = await page.locator('.inventory-table thead th').allTextContents();
  expect(headings.map((heading) => heading.trim()).filter(Boolean)).toEqual(['Device', 'IP', 'Services', 'Activity']);
  await expect(page.locator('tr.inventory-host-row').filter({ hasText: 'Lobby camera' })).toHaveClass(/activity-down-under-month/);
  await expect.poll(() => page.evaluate(() => JSON.parse(localStorage.getItem('fenping_inventory_layout_v1') || 'null'))).toMatchObject({
    downRecentDays: 2,
    downOlderDays: 10,
    columns: [
      { key: 'device', visible: true, width: 17 },
      { key: 'ip', visible: true, width: 24 },
      { key: 'services', visible: true, width: 6 },
      { key: 'vendor', visible: false, width: 20 },
      { key: 'activity', visible: true, width: 7 }
    ]
  });

  const resizeIp = page.getByRole('separator', { name: 'Resize IP column' });
  await resizeIp.focus();
  await resizeIp.press('ArrowRight');
  await expect.poll(() => page.evaluate(() => JSON.parse(localStorage.getItem('fenping_inventory_layout_v1')).columns.find((column) => column.key === 'ip').width)).toBe(25);

  await page.reload();
  await expect(page.getByRole('columnheader', { name: 'Vendor' })).toHaveCount(0);
  await page.getByRole('button', { name: 'Columns' }).click();
  await expect(page.getByRole('dialog', { name: 'Inventory columns' }).getByLabel('IP width')).toHaveValue('25');
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
  const mobileIpBefore = (await page.getByRole('columnheader', { name: 'IP', exact: true }).boundingBox()).width;
  const resizeIp = page.getByRole('separator', { name: 'Resize IP column' });
  await resizeIp.focus();
  for (let index = 0; index < 10; index++) await resizeIp.press('ArrowRight');
  const mobileIpAfter = (await page.getByRole('columnheader', { name: 'IP', exact: true }).boundingBox()).width;
  expect(mobileIpAfter).toBeGreaterThan(mobileIpBefore + 10);
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
