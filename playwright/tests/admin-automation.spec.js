const { test, expect } = require("@playwright/test");
const { execSync } = require("child_process");
const fs = require("fs");
const path = require("path");

// Automation (Phase 4). Proves managing rules through the Operations screen —
// add a rule, toggle it, run the engine, delete it — and that the endpoints are
// admin-gated. Desktop-only; the firing/idempotency/grace logic is covered by
// AutomationTest.

const ADMIN = { email: "admin@test.local", password: "test-admin-pw-1234" };

test.describe("Standalone admin — automation", () => {
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

  test("add, toggle, run and delete an automation rule", async ({ page }, testInfo) => {
    test.skip(testInfo.project.name === "mobile", "desktop-only multi-step journey");
    await login(page);

    await page.goto("/breakfast-admin/operations");
    await expect(page.locator('[data-test="automation"]')).toBeVisible();

    // Add an overdue-invoice rule.
    await page.locator('[data-test="automation-trigger"]').selectOption("invoice_overdue");
    await page.locator('[data-test="automation-name"]').fill("Chase overdue invoices");
    await Promise.all([
      page.waitForResponse((r) => r.url().endsWith("/api/v1/automation") && r.request().method() === "POST"),
      page.locator('[data-test="automation-add"]').click(),
    ]);
    await expect(page.locator('[data-test="rule-on"]')).toContainText("Chase overdue invoices");

    // Disable it.
    await Promise.all([
      page.waitForResponse((r) => /\/automation\/[^/]+$/.test(r.url()) && r.request().method() === "PATCH"),
      page.locator('[data-test="rule-toggle"]').first().click(),
    ]);
    await expect(page.locator('[data-test="rule-off"]')).toBeVisible();

    // Run the engine (nothing to fire, but the call succeeds).
    await Promise.all([
      page.waitForResponse((r) => r.url().endsWith("/api/v1/automation/run") && r.request().method() === "POST"),
      page.locator('[data-test="automation-run"]').click(),
    ]);

    // Delete the rule.
    await Promise.all([
      page.waitForResponse((r) => /\/automation\/[^/]+$/.test(r.url()) && r.request().method() === "DELETE"),
      page.locator('[data-test="rule-delete"]').first().click(),
    ]);
    await expect(page.locator('[data-test="rule-off"]')).toHaveCount(0);
  });

  test("automation endpoints reject unauthenticated callers", async ({ request }) => {
    const list = await request.get("/breakfast-admin/api/v1/automation");
    expect(list.status()).toBe(401);
    const run = await request.post("/breakfast-admin/api/v1/automation/run", { data: {} });
    expect(run.status()).toBe(401);
  });
});
