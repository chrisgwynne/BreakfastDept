const { test, expect } = require("@playwright/test");
const { execSync } = require("child_process");
const fs = require("fs");
const path = require("path");

// Time tracking (Phase 4). Proves logging a billable entry through the UI, that
// the project rollup reflects it, running + stopping the live timer, and that
// unauthenticated callers are rejected. Desktop-only for the multi-step journey;
// the domain rules (billed lock, one-timer-per-user, value maths) are covered by
// TimeTrackingTest.

const ADMIN = { email: "admin@test.local", password: "test-admin-pw-1234" };

test.describe("Standalone admin — time tracking", () => {
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

  test("log billable time, see the rollup, run and stop the timer", async ({ page }, testInfo) => {
    test.skip(testInfo.project.name === "mobile", "desktop-only multi-step journey");
    await login(page);

    await page.goto("/breakfast-admin/projects");
    await page.getByRole("button", { name: "New project" }).first().click();
    await page.locator(".sheet").getByPlaceholder("e.g. Roberts Cafe website").fill("Time Project " + Date.now());
    await Promise.all([
      page.waitForResponse((r) => r.url().endsWith("/api/v1/projects") && r.request().method() === "POST"),
      page.locator(".sheet").getByRole("button", { name: "Create project" }).click(),
    ]);
    await page.waitForURL((u) => /\/projects\/[0-9a-f-]+/.test(u.toString()), { timeout: 10000 });

    await page.getByRole("tab", { name: "Time", exact: true }).click();
    await expect(page.locator('[data-test="project-time"]')).toBeVisible();

    // Log 2 hours billable at £80/h.
    await page.locator('[data-test="time-desc"]').fill("Homepage build");
    await page.locator('[data-test="time-hours"]').fill("2");
    await page.locator('[data-test="time-rate"]').fill("80");
    await Promise.all([
      page.waitForResponse((r) => r.url().endsWith("/api/v1/time") && r.request().method() === "POST"),
      page.locator('[data-test="time-log"]').click(),
    ]);
    await expect(page.locator('[data-test="time-entry"]').first()).toContainText("Homepage build");
    // £160.00 unbilled value on the rollup.
    await expect(page.locator('[data-test="time-unbilled"]')).toContainText("£160.00");

    // Start the live timer, then stop it.
    await Promise.all([
      page.waitForResponse((r) => r.url().endsWith("/api/v1/time/start") && r.request().method() === "POST"),
      page.locator('[data-test="time-start"]').click(),
    ]);
    await expect(page.locator('[data-test="time-running"]')).toBeVisible();
    await Promise.all([
      page.waitForResponse((r) => r.url().endsWith("/api/v1/time/stop") && r.request().method() === "POST"),
      page.locator('[data-test="time-stop"]').click(),
    ]);
    await expect(page.locator('[data-test="time-running"]')).toHaveCount(0);
  });

  test("time endpoints reject unauthenticated callers", async ({ request }) => {
    const list = await request.get("/breakfast-admin/api/v1/time?project=x");
    expect(list.status()).toBe(401);
    const log = await request.post("/breakfast-admin/api/v1/time", { data: { project_uuid: "x", hours: 1 } });
    expect(log.status()).toBe(401);
  });
});
