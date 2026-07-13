import { expect, test } from './fixtures.js';

test('supports keyboard category and host activation with an accessible modal focus trap', async ({ page }) => {
  await page.goto('/');

  const category = page.locator('tr.category-row').filter({ hasText: 'Infrastructure' });
  const gateway = page.locator('tr.inventory-host-row').filter({ hasText: 'Gateway' });

  await category.focus();
  await category.press('Space');
  await expect(category).toHaveAttribute('aria-expanded', 'false');
  await expect(gateway).toHaveAttribute('aria-hidden', 'true');

  await category.press('Space');
  await expect(category).toHaveAttribute('aria-expanded', 'true');
  await expect(gateway).not.toHaveAttribute('aria-hidden', 'true');

  await gateway.focus();
  await expect(gateway).toBeFocused();
  await page.keyboard.press('Enter');

  const dialog = page.getByRole('dialog', { name: 'Host details' });
  await expect(dialog).toBeVisible();
  await expect(page.locator('.app-layout')).toHaveAttribute('inert', '');
  await expect(page.locator('.app-layout')).toHaveAttribute('aria-hidden', 'true');

  const closeButtons = dialog.getByRole('button', { name: 'Close' });
  const first = closeButtons.first();
  const last = closeButtons.last();
  await expect(first).toBeFocused();

  await first.press('Shift+Tab');
  await expect(last).toBeFocused();
  await last.press('Tab');
  await expect(first).toBeFocused();

  await first.press('Escape');
  await expect(dialog).toBeHidden();
  await expect(page.locator('.app-layout')).not.toHaveAttribute('aria-hidden', 'true');
  await expect(gateway).toBeFocused();
});
