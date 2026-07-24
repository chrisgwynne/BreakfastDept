const { test, expect } = require("@playwright/test");
const { execSync } = require("child_process");
const fs = require("fs");
const path = require("path");

// Retainers & recurring billing (Phase 4). Proves creating a retainer and
// running its billing through the UI, which generates a period + draft invoice
// in arrears. Desktop-only for the multi-step journey; the billing maths, time
// overage, idempotency and catch-up are covered by RetainersTest.

const ADMIN = { email: "admin@test.local", password: "test-admin-pw-1234" };

test.describe("Standalone admin — retainers", () => {
  test.beforeAll(() => {
    execSync(`php bin/console user:seed --email=${ADMIN.email} --password=${ADMIN.password} --role=admin`, { cwd: process.cwd(), stdio: "inherit" });
    try { fs.rmSync(path.join(process.cwd(), "storage", "accounts", ".logins"), { force: true }); } catch { /* none */ }
  });

  async function login(page) {
    await page.goto("/breakfast-admin/login");
    await page.locator("#email").fill(ADMIN.email);
    await page.locator("#password").fill(ADMIN.password);
    await Promise.all([
      page.waitForResponse((r) => r.url().includes("/breakfast-admin/api/v1/session") && r.request().method() === "POST"),
      page.getByRole("button", { name: /sign in/i }).click(),
    ]);
    await page.waitForURL((u) => !u.toString().includes("/login"), { timeout: 15000 });
  }

  test("create a retainer and run its recurring billing", async ({ page }, testInfo) => {
    test.skip(testInfo.project.name === "mobile", "desktop-only multi-step journey");
    await login(page);

    await page.goto("/breakfast-admin/projects");
    await page.getByRole("button", { name: "New project" }).first().click();
    await page.locator(".sheet").getByPlaceholder("e.g. Roberts Cafe website").fill("Retainer Project " + Date.now());
    await Promise.all([
      page.waitForResponse((r) => r.url().endsWith("/api/v1/projects") && r.request().method() === "POST"),
      page.locator(".sheet").getByRole("button", { name: "Create project" }).click(),
    ]);
    await page.waitForURL((u) => /\/projects\/[0-9a-f-]+/.test(u.toString()), { timeout: 10000 });

    await page.getByRole("tab", { name: "Retainers", exact: true }).click();
    await expect(page.locator('[data-test="project-retainers"]')).toBeVisible();

    // Start ~40 days ago so exactly one monthly period has elapsed today.
    const start = new Date(Date.now() - 40 * 86400 * 1000).toISOString().slice(0, 10);
    await page.locator('[data-test="retainer-title"]').fill("Care plan");
    await page.locator('[data-test="retainer-fee"]').fill("500");
    await page.locator('[data-test="retainer-hours"]').fill("5");
    await page.locator('[data-test="retainer-start"]').fill(start);
    await Promise.all([
      page.waitForResponse((r) => r.url().endsWith("/api/v1/retainers") && r.request().method() === "POST"),
      page.locator('[data-test="retainer-create"]').click(),
    ]);
    await expect(page.locator('[data-test="retainer-active"]')).toBeVisible();

    // Run billing → one period is generated and shown.
    await Promise.all([
      page.waitForResponse((r) => /\/retainers\/[^/]+\/run$/.test(r.url()) && r.request().method() === "POST"),
      page.locator('[data-test="retainer-run"]').click(),
    ]);
    await expect(page.locator('[data-test="retainer-period"]').first()).toBeVisible();
    await expect(page.locator('[data-test="retainer-period"]').first()).toContainText("£500.00");
  });

  test("retainer endpoints reject unauthenticated callers", async ({ request }) => {
    const list = await request.get("/breakfast-admin/api/v1/retainers?project=x");
    expect(list.status()).toBe(401);
    const run = await request.post("/breakfast-admin/api/v1/retainers/run", { data: {} });
    expect(run.status()).toBe(401);
  });
});
