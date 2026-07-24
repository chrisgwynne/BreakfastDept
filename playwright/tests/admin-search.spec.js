const { test, expect } = require("@playwright/test");
const { execSync } = require("child_process");
const fs = require("fs");
const path = require("path");

// Global search from the command palette: a real record created in the CRM is
// found by typing and jumps straight to it.

const ADMIN = { email: "admin@test.local", password: "test-admin-pw-1234" };

test.describe("Standalone admin — global search", () => {
  test.beforeAll(() => {
    execSync(`php bin/console user:seed --email=${ADMIN.email} --password=${ADMIN.password} --role=admin`, {
      cwd: process.cwd(),
      stdio: "inherit",
    });
    try {
      fs.rmSync(path.join(process.cwd(), "storage", "accounts", ".logins"), { force: true });
    } catch {
      /* no throttle file */
    }
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

  test("find a contact by name and jump to it", async ({ page }) => {
    await login(page);

    // Create a searchable lead through the real create flow.
    const uniq = "Zephyr" + Date.now();
    await page.goto("/breakfast-admin/leads");
    await page.getByRole("button", { name: "New lead" }).first().click();
    await page.locator(".cf input").first().fill(uniq + " Jones");
    await page.locator('.cf input[type="email"]').first().fill(uniq.toLowerCase() + "@example.test");
    await Promise.all([
      page.waitForResponse((r) => r.url().endsWith("/api/v1/enquiries") && r.request().method() === "POST"),
      page.getByRole("button", { name: "Create", exact: true }).click(),
    ]);
    await expect(page.getByText("Lead created")).toBeVisible({ timeout: 8000 });

    // Open the command palette and search.
    await page.locator(".topbar__search").click();
    await Promise.all([
      page.waitForResponse((r) => /\/api\/v1\/search\?/.test(r.url())),
      page.locator(".cp__input").fill(uniq),
    ]);
    const hit = page.locator(".cp__item", { hasText: uniq });
    await expect(hit.first()).toBeVisible({ timeout: 8000 });
    await hit.first().click();

    // It navigated to the contact workspace.
    await page.waitForURL(/\/crm\/contacts\//, { timeout: 8000 });
    await expect(page.getByText(uniq + " Jones").first()).toBeVisible({ timeout: 8000 });
  });
});
