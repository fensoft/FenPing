import { expect, test } from './fixtures.js';

test('refreshes the mounted page when a live event arrives', async ({ page, api }) => {
  const pageErrors = [];
  page.on('pageerror', (error) => pageErrors.push(error.message));
  await page.goto('/');

  const before = api.requestsFor('GET', '/api/inventory').length;
  await page.evaluate(() => {
    window.__fenpingEventSources[0].dispatchEvent(new MessageEvent('fenping-update', {
      data: JSON.stringify({ version: 1, scopes: ['scans'] })
    }));
  });

  await expect.poll(
    () => api.requestsFor('GET', '/api/inventory').length
  ).toBeGreaterThan(before);
  expect(pageErrors).toEqual([]);
});
