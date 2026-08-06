import fs from 'node:fs';
import { test, expect } from '../test';

type Manifest = {
  plugins: Array<{ file?: string; tests_enabled?: boolean }>;
};

test.describe.configure({ mode: 'serial' });

test('the local site opens', async ({ page }) => {
  const response = await page.goto('/');
  expect(response?.ok()).toBeTruthy();
});

test('the dedicated administrator can log in', async ({ page }) => {
  await page.goto('/wp-admin/');
  await expect(page).toHaveURL(/\/wp-admin\//);
  await expect(page.locator('#wpadminbar')).toBeVisible();
});

test('selected plugins are visible', async ({ page }) => {
  const manifest = JSON.parse(fs.readFileSync(process.env.ANYAPE_WP_TEST_TOOLS_E2E_MANIFEST as string, 'utf8')) as Manifest;
  await page.goto('/wp-admin/plugins.php');
  await expect(page.locator('#the-list')).toBeVisible();
  for (const plugin of manifest.plugins.filter(item => item.tests_enabled && item.file)) {
    await expect(page.locator(`tr[data-plugin="${plugin.file}"]`)).toHaveCount(1);
  }
});

test('a settings form can be saved', async ({ page }) => {
  await page.goto('/wp-admin/tools.php?page=anyape-wp-test-tools-e2e');
  await page.getByLabel('Fixture setting').fill('saved by Playwright');
  await page.getByRole('button', { name: 'Save fixture setting' }).click();
  await expect(page.getByText('Settings saved.')).toBeVisible();
  await expect(page.getByLabel('Fixture setting')).toHaveValue('saved by Playwright');
});

test('a fake service failure is shown', async ({ page }) => {
  await page.goto('/wp-admin/tools.php?page=anyape-wp-test-tools-e2e&service-failure=1');
  await expect(page.getByText('Fake service request failed as expected.')).toBeVisible();
});
