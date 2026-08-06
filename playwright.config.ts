import { defineConfig, devices, type Project } from '@playwright/test';
import fs from 'node:fs';
import path from 'node:path';

const toolkitRoot = __dirname;
const projectRoot = path.dirname(toolkitRoot);
const manifestPath = requiredEnvironment('WP_TEST_E2E_MANIFEST');
const manifest = JSON.parse(fs.readFileSync(manifestPath, 'utf8')) as {
  plugins?: ExtensionEntry[];
  themes?: ExtensionEntry[];
};

type ExtensionEntry = {
  type: 'plugin' | 'theme';
  slug: string;
  source_path: string;
  tests_enabled: boolean;
};

const projects: Project[] = [
  {
    name: 'toolkit',
    testDir: path.join(toolkitRoot, 'e2e', 'specs'),
    testMatch: '**/*.spec.ts',
  },
];

for (const extension of [...(manifest.plugins ?? []), ...(manifest.themes ?? [])]) {
  if (!extension.tests_enabled) continue;
  const sourcePath = path.resolve(extension.source_path);
  if (!sourcePath.startsWith(`${projectRoot}${path.sep}`)) {
    throw new Error(`Selected ${extension.type} path is outside the WordPress root: ${sourcePath}`);
  }
  const testDir = path.join(sourcePath, 'tests', 'e2e');
  if (!fs.existsSync(testDir)) continue;
  projects.push({
    name: `${extension.type}:${extension.slug}`,
    testDir,
    testMatch: '**/*.spec.ts',
  });
}

export default defineConfig({
  fullyParallel: false,
  forbidOnly: true,
  globalSetup: path.join(toolkitRoot, 'e2e', 'global-setup.ts'),
  outputDir: path.join(toolkitRoot, 'test-results'),
  projects,
  reporter: [
    ['line'],
    [path.join(toolkitRoot, 'e2e', 'failure-reporter.ts')],
    ['html', { outputFolder: path.join(toolkitRoot, 'playwright-report'), open: 'never' }],
  ],
  retries: 0,
  tsconfig: path.join(toolkitRoot, 'tsconfig.json'),
  timeout: 30_000,
  use: {
    ...devices['Desktop Chrome'],
    baseURL: requiredEnvironment('WP_TEST_E2E_BASE_URL'),
    ignoreHTTPSErrors: true,
    storageState: requiredEnvironment('WP_TEST_E2E_ADMIN_STATE'),
    screenshot: 'only-on-failure',
    trace: 'retain-on-failure',
  },
  workers: 1,
});

function requiredEnvironment(name: string): string {
  const value = process.env[name];
  if (!value) throw new Error(`Required browser-test environment value is missing: ${name}`);
  return value;
}
