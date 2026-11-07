// Browser tests of the components against the development server (dev/server.php, PHP in Docker)
import { defineConfig, devices } from '@playwright/test';

const port = Number(process.env.TANSU_E2E_PORT || 8090);

export default defineConfig({
  testDir: './e2e',
  // The tests share dev/storage, so they run one at a time
  workers: 1,
  fullyParallel: false,
  forbidOnly: Boolean(process.env.CI),
  reporter: process.env.CI ? 'github' : 'list',
  use: {
    baseURL: `http://localhost:${port}`,
    trace: 'retain-on-failure',
  },
  projects: [{ name: 'chromium', use: { ...devices['Desktop Chrome'] } }],
  webServer: {
    command: `docker run --rm --init -u "$(id -u):$(id -g)" -p ${port}:8080 -v "$(cd .. && pwd):/app" tansu-php php -S 0.0.0.0:8080 dev/server.php`,
    url: `http://localhost:${port}/api/info`,
    reuseExistingServer: !process.env.CI,
    stdout: 'ignore',
    stderr: 'ignore',
    timeout: 60_000,
  },
});
