// Playwright configuration for Breakfast browser tests.
// Uses the pre-installed Chromium; starts a PHP built-in server on public/.
const { defineConfig, devices } = require("@playwright/test");

const PORT = process.env.BF_TEST_PORT || 8891;
const baseURL = `http://127.0.0.1:${PORT}`;

module.exports = defineConfig({
  testDir: "./playwright/tests",
  timeout: 30000,
  fullyParallel: false,
  // The test server is PHP's single-threaded built-in server; running desktop
  // and mobile workers against it in parallel starves requests and causes
  // flaky timeouts. Serialise to one worker so the suite is deterministic.
  workers: 1,
  retries: 0,
  reporter: [["list"]],
  use: {
    baseURL,
    trace: "on-first-retry",
    // In CI, Playwright manages its own browser (installed via `playwright
    // install`). Only override the executable when PW_CHROMIUM is provided
    // (e.g. a sandbox with a pre-installed Chromium).
    launchOptions: process.env.PW_CHROMIUM ? { executablePath: process.env.PW_CHROMIUM } : {},
  },
  projects: [
    { name: "desktop", use: { ...devices["Desktop Chrome"] } },
    { name: "mobile", use: { ...devices["Pixel 5"] } },
  ],
  // Migrate the DB, then serve public/ through router.php so the built admin SPA,
  // its API (/breakfast-admin/api/v1) and the public pages all dispatch correctly
  // under php -S. The admin bundle is expected to be built already (npm run build
  // in admin/ — done in CI before this job; locally, build it once).
  webServer: {
    command: `php bin/console migrate && php -S 127.0.0.1:${PORT} -t public public/router.php`,
    url: baseURL,
    reuseExistingServer: !process.env.CI,
    timeout: 30000,
    env: { APP_ENV: "development", APP_DEBUG: "true", BF_STRIPE_MOCK: "1" },
  },
});
