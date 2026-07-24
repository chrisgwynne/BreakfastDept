const { test, expect } = require("@playwright/test");
const { execSync } = require("child_process");
const fs = require("fs");
const path = require("path");

// Project templates — applying a built-in template to a project generates real
// milestones and tasks. Desktop-only (versioning + date resolution + freezing
// are covered project-agnostically by ProjectTemplatesTest).

const ADMIN = { email: "admin@test.local", password: "test-admin-pw-1234" };

test.describe("Standalone admin — project templates", () => {
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

  test("apply a template and see generated milestones + tasks", async ({ page }, testInfo) => {
    test.skip(testInfo.project.name === "mobile", "desktop-only multi-step journey");
    await login(page);

    await page.goto("/breakfast-admin/projects");
    await page.getByRole("button", { name: "New project" }).first().click();
    await page.locator(".sheet").getByPlaceholder("e.g. Roberts Cafe website").fill("Template Project " + Date.now());
    await page.locator(".sheet").locator('.field', { hasText: "Target date" }).locator("input").fill("2026-06-30");
    await Promise.all([
      page.waitForResponse((r) => r.url().endsWith("/api/v1/projects") && r.request().method() === "POST"),
      page.locator(".sheet").getByRole("button", { name: "Create project" }).click(),
    ]);
    await page.waitForURL((u) => /\/projects\/[0-9a-f-]+/.test(u.toString()), { timeout: 10000 });

    // Apply the brochure website template.
    await page.locator('[data-test="template-select"]').click();
    // Wait for templates to load, then choose one by label.
    await page.locator('[data-test="template-select"]').selectOption({ label: "Brochure website" });
    await Promise.all([
      page.waitForResponse((r) => /\/apply-template$/.test(r.url()) && r.request().method() === "POST"),
      page.locator('[data-test="apply-template"]').click(),
    ]);
    await expect(page.locator(".app-toast", { hasText: "milestones" })).toBeVisible({ timeout: 8000 });

    // Milestones tab shows the generated checkpoints.
    await page.getByRole("tab", { name: "Milestones" }).click();
    await expect(page.getByText("Design & mock-up")).toBeVisible();
    await expect(page.getByText("Launch")).toBeVisible();

    // Board shows generated tasks.
    await page.getByRole("tab", { name: "Board" }).click();
    await expect(page.getByText("Homepage content due")).toBeVisible();
  });
});
