const { test, expect } = require("@playwright/test");
const { execSync } = require("child_process");
const fs = require("fs");
const path = require("path");

// Operational reporting (Phase 4). Proves the Reports page surfaces the delivery
// & profitability section: a project with logged billable time appears in the
// projects table with its unbilled-time value. Desktop-only; the rollup maths is
// covered by ReportingTest.

const ADMIN = { email: "admin@test.local", password: "test-admin-pw-1234" };

test.describe("Standalone admin — operational reporting", () => {
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

  test("logged time appears in the delivery & profitability report", async ({ page }, testInfo) => {
    test.skip(testInfo.project.name === "mobile", "desktop-only multi-step journey");
    await login(page);

    const projectName = "Reporting Project " + Date.now();
    await page.goto("/breakfast-admin/projects");
    await page.getByRole("button", { name: "New project" }).first().click();
    await page.locator(".sheet").getByPlaceholder("e.g. Roberts Cafe website").fill(projectName);
    await page.locator(".sheet").locator('.field', { hasText: "Quoted value" }).locator("input").fill("5000");
    await Promise.all([
      page.waitForResponse((r) => r.url().endsWith("/api/v1/projects") && r.request().method() === "POST"),
      page.locator(".sheet").getByRole("button", { name: "Create project" }).click(),
    ]);
    await page.waitForURL((u) => /\/projects\/[0-9a-f-]+/.test(u.toString()), { timeout: 10000 });

    // Log 2 billable hours at £80/h.
    await page.getByRole("tab", { name: "Time", exact: true }).click();
    await page.locator('[data-test="time-desc"]').fill("Build");
    await page.locator('[data-test="time-hours"]').fill("2");
    await page.locator('[data-test="time-rate"]').fill("80");
    await Promise.all([
      page.waitForResponse((r) => r.url().endsWith("/api/v1/time") && r.request().method() === "POST"),
      page.locator('[data-test="time-log"]').click(),
    ]);

    // Reports → the operations section shows the project with £160 unbilled time.
    await page.goto("/breakfast-admin/reports");
    await expect(page.locator('[data-test="reports-operations"]')).toBeVisible();
    const row = page.locator('[data-test="ops-projects"] tr', { hasText: projectName });
    await expect(row).toContainText("£160.00");
  });

  test("reports endpoint rejects unauthenticated callers", async ({ request }) => {
    const res = await request.get("/breakfast-admin/api/v1/reports");
    expect(res.status()).toBe(401);
  });
});
