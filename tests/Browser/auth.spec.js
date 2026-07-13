import { expect, test } from './fixtures.js';

test('handles rejected login, successful login, and logout', async ({ page, api }) => {
  await page.goto('/');

  await expect(page.getByText('Guest', { exact: true })).toBeVisible();
  const loginButton = page.getByRole('button', { name: 'Login' });
  await loginButton.focus();
  await loginButton.press('Enter');

  let dialog = page.getByRole('dialog', { name: 'Login' });
  const password = dialog.getByLabel('Password');
  await expect(password).toBeFocused();

  await password.fill('wrong password');
  await dialog.getByRole('button', { name: 'Login' }).click();
  await expect(dialog.getByRole('alert')).toHaveText('Invalid password');
  await expect(dialog).toBeVisible();

  await password.fill(api.state.password);
  await dialog.getByRole('button', { name: 'Login' }).click();

  await expect(dialog).toBeHidden();
  await expect(page.getByText('Admin', { exact: true })).toBeVisible();
  await expect(page.getByRole('button', { name: 'Logout' })).toBeVisible();
  await expect(page.getByRole('status')).toHaveText('Logged in');

  const loginRequests = api.requestsFor('POST', '/api/auth/login');
  expect(loginRequests).toHaveLength(2);
  expect(loginRequests.map((request) => request.body)).toEqual([
    { password: 'wrong password' },
    { password: api.state.password }
  ]);
  expect(api.requestsFor('POST', '/api/ping/refresh')).toHaveLength(1);

  await page.getByRole('button', { name: 'Logout' }).click();
  await expect(page.getByText('Guest', { exact: true })).toBeVisible();
  await expect(page.getByRole('button', { name: 'Login' })).toBeVisible();
  await expect(page.getByRole('status')).toHaveText('Logged out');
  expect(api.requestsFor('POST', '/api/auth/logout')).toHaveLength(1);
});
