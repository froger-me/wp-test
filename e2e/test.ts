import { test as base, expect } from '@playwright/test';

export const test = base.extend<{ browserDiagnostics: void }>({
  browserDiagnostics: [
    async ({ context }, use, testInfo) => {
      const consoleMessages: string[] = [];
      const failedRequests: string[] = [];

      context.on('page', page => {
        page.on('console', message => consoleMessages.push(`[${message.type()}] ${message.text()}`));
        page.on('pageerror', error => consoleMessages.push(`[page error] ${error.message}`));
        page.on('requestfailed', request => {
          failedRequests.push(`${request.method()} ${request.url()} — ${request.failure()?.errorText ?? 'failed'}`);
        });
        page.on('response', response => {
          if (response.status() >= 400) {
            failedRequests.push(`${response.request().method()} ${response.url()} — HTTP ${response.status()}`);
          }
        });
      });

      await use();

      if (testInfo.status !== testInfo.expectedStatus) {
        await testInfo.attach('browser-console', {
          body: Buffer.from(consoleMessages.join('\n') || 'No browser console messages were recorded.'),
          contentType: 'text/plain',
        });
        await testInfo.attach('failed-network-requests', {
          body: Buffer.from(failedRequests.join('\n') || 'No failed browser network requests were recorded.'),
          contentType: 'text/plain',
        });
      }
    },
    { auto: true },
  ],
});

export { expect };
export const lowerCapabilityStorageState = process.env.WP_TEST_E2E_LOWER_STATE as string;
