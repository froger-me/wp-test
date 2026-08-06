import { chromium, type FullConfig } from '@playwright/test';
import fs from 'node:fs';

export default async function globalSetup(config: FullConfig): Promise<void> {
  const users = JSON.parse(fs.readFileSync(requiredEnvironment('ANYAPE_WP_TEST_TOOLS_E2E_USERS_FILE'), 'utf8')) as { token: string };
  const baseURL = requiredEnvironment('ANYAPE_WP_TEST_TOOLS_E2E_BASE_URL');
  const browser = await chromium.launch();
  try {
    await authenticate(browser, baseURL, 'admin', users.token, requiredEnvironment('ANYAPE_WP_TEST_TOOLS_E2E_ADMIN_STATE'));
    await authenticate(browser, baseURL, 'lower', users.token, requiredEnvironment('ANYAPE_WP_TEST_TOOLS_E2E_LOWER_STATE'));
  } finally {
    await browser.close();
  }
}

async function authenticate(
  browser: Awaited<ReturnType<typeof chromium.launch>>,
  baseURL: string,
  account: 'admin' | 'lower',
  token: string,
  statePath: string,
): Promise<void> {
  const context = await browser.newContext({ baseURL, ignoreHTTPSErrors: true });
  const page = await context.newPage();
  const query = new URLSearchParams({ action: 'anyape_wp_test_tools_e2e_login', account, token });
  await page.goto(`/wp-login.php?${query.toString()}`);
  try {
    await page.waitForURL(/\/wp-admin\//);
  } catch (error) {
    const message = await page.locator('#login_error, .message').allTextContents();
    throw new Error(`Dedicated user login did not reach wp-admin. URL: ${page.url()}. Message: ${message.join(' ')}`, {
      cause: error,
    });
  }
  await context.storageState({ path: statePath });
  await context.close();
}

function requiredEnvironment(name: string): string {
  const value = process.env[name];
  if (!value) throw new Error(`Required browser-test environment value is missing: ${name}`);
  return value;
}
