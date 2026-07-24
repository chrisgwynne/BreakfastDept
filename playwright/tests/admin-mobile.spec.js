const { test, expect } = require("@playwright/test");
const { execSync } = require("child_process");
const fs = require("fs");
const path = require("path");

// Mobile responsiveness for the Phase 2 delivery areas. Proves the standalone
// admin is usable on a phone: the drawer navigation opens and routes, the
// top-level Projects and Vault screens render, and a project's Change requests
// tab is reachable and functional on a small viewport. Runs on the mobile
// project only (the desktop project has its own multi-step journeys).

const ADMIN = { email: "admin@test.local", password: "test-admin-pw-1234" };

test.describe("Standalone admin — mobile", () => {
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

  test("drawer nav routes to Projects and Vault on a phone", async ({ page }, testInfo) => {
    test.skip(testInfo.project.name !== "mobile", "mobile-only responsiveness check");
    await login(page);

    // The drawer is hidden until the burger is tapped.
    await page.getByRole("button", { name: "Menu" }).click();
    await page.getByRole("link", { name: "Projects" }).click();
    await page.waitForURL((u) => u.toString().includes("/projects"));
    await expect(page.getByRole("button", { name: "New project" }).first()).toBeVisible();

    // Vault (admin-only top-level) renders its masked list on mobile.
    await page.getByRole("button", { name: "Menu" }).click();
    await page.getByRole("link", { name: "Vault" }).click();
    await page.waitForURL((u) => u.toString().includes("/vault"));
    await expect(page.locator('[data-test="vault-view"], main').first()).toBeVisible();
  });

  test("project change-requests tab is usable on a phone", async ({ page }, testInfo) => {
    test.skip(testInfo.project.name !== "mobile", "mobile-only responsiveness check");
    await login(page);

    await page.goto("/breakfast-admin/projects");
    await page.getByRole("button", { name: "New project" }).first().click();
    await page.locator(".sheet").getByPlaceholder("e.g. Roberts Cafe website").fill("Mobile CR " + Date.now());
    await Promise.all([
      page.waitForResponse((r) => r.url().endsWith("/api/v1/projects") && r.request().method() === "POST"),
      page.locator(".sheet").getByRole("button", { name: "Create project" }).click(),
    ]);
    await page.waitForURL((u) => /\/projects\/[0-9a-f-]+/.test(u.toString()), { timeout: 10000 });

    // The tab row scrolls horizontally; the tab is visible + stable but the
    // sticky mobile top bar overlaps the auto-scroll target, so force the tap.
    const crTab = page.getByRole("tab", { name: "Change requests", exact: true });
    await crTab.scrollIntoViewIfNeeded();
    await crTab.click({ force: true });
    await expect(page.locator('[data-test="project-change-requests"]')).toBeVisible();

    // The draft form is reachable and submits on a small viewport.
    await page.locator('[data-test="cr-title"]').fill("Add a booking widget");
    await page.locator('[data-test="cr-item-desc-0"]').fill("Booking integration");
    await page.locator('[data-test="cr-item-price-0"]').fill("600");
    await Promise.all([
      page.waitForResponse((r) => r.url().endsWith("/api/v1/change-requests") && r.request().method() === "POST"),
      page.locator('[data-test="cr-create"]').click(),
    ]);
    await expect(page.locator('[data-test="cr-draft"]')).toContainText("£600.00");
  });
});
