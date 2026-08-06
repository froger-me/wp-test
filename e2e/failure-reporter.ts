import type { Reporter, TestCase, TestResult } from '@playwright/test/reporter';
import fs from 'node:fs';
import path from 'node:path';

export default class FailureReporter implements Reporter {
  onTestEnd(test: TestCase, result: TestResult): void {
    if (result.status === test.expectedStatus) return;

    const outputRoot = path.join(__dirname, '..', 'test-results', 'failure-details');
    fs.mkdirSync(outputRoot, { recursive: true });
    const fileName = `${test.id.replace(/[^A-Za-z0-9._-]+/g, '-')}-${result.retry}.txt`;
    const lines = [
      `Test: ${test.titlePath().join(' > ')}`,
      `Profile: ${process.env.ANYAPE_WP_TEST_TOOLS_E2E_PROFILE ?? 'unknown'}`,
      `Status: ${result.status}`,
      '',
      'WordPress debug log (last 200 lines):',
      readLogTail(process.env.ANYAPE_WP_TEST_TOOLS_E2E_DEBUG_LOG),
    ];
    fs.writeFileSync(path.join(outputRoot, fileName), lines.join('\n'));
  }
}

function readLogTail(logPath: string | undefined): string {
  if (!logPath || !fs.existsSync(logPath)) return 'No WordPress debug log was found.';
  const contents = fs.readFileSync(logPath);
  const requestedOffset = Number.parseInt(process.env.ANYAPE_WP_TEST_TOOLS_E2E_DEBUG_LOG_OFFSET ?? '0', 10);
  const offset = Number.isFinite(requestedOffset) && requestedOffset <= contents.length ? requestedOffset : 0;
  const currentRun = contents.subarray(offset).toString('utf8');
  return currentRun.split(/\r?\n/).slice(-200).join('\n') || 'No new WordPress debug-log lines were recorded.';
}
