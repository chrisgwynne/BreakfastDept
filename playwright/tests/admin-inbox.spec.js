const { test, expect } = require("@playwright/test");
const { execSync } = require("child_process");
const fs = require("fs");
const path = require("path");

// Staff operational inbox (Phase 4). Proves that a client action (a portal
// message) surfaces in the staff inbox with a nav badge, deep-links to the
// project, and drops out once handled — driven end to end through the real
// portal + admin. Desktop-only; the aggregation rules are covered by InboxTest.

const ADMIN = { email: "admin@test.local", password: "test-admin-pw-1234" };

test.describe("Standalone admin — operational inbox", () => {
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

  test("a client message surfaces in the inbox and clears when read", async ({ page, browser }, testInfo) => {
    test.skip(testInfo.project.name === "mobile", "desktop-only multi-step journey");
    await login(page);

    // Create a project and invite a client.
    const projectName = "Inbox Project " + Date.now();
    await page.goto("/breakfast-admin/projects");
    await page.getByRole("button", { name: "New project" }).first().click();
    await page.locator(".sheet").getByPlaceholder("e.g. Roberts Cafe website").fill(projectName);
    await Promise.all([
      page.waitForResponse((r) => r.url().endsWith("/api/v1/projects") && r.request().method() === "POST"),
      page.locator(".sheet").getByRole("button", { name: "Create project" }).click(),
    ]);
    await page.waitForURL((u) => /\/projects\/[0-9a-f-]+/.test(u.toString()), { timeout: 10000 });
    await page.getByRole("tab", { name: "Client access", exact: true }).click();
    await page.locator('[data-test="access-email"]').fill(`inbox${Date.now()}@example.com`);
    await Promise.all([
      page.waitForResponse((r) => r.url().endsWith("/api/v1/portal/invite") && r.request().method() === "POST"),
      page.locator('[data-test="access-add"]').click(),
    ]);
    const signInUrl = await page.locator('[data-test="access-link"]').inputValue();

    // The client signs in and sends a message.
    const clientCtx = await browser.newContext();
    const clientPage = await clientCtx.newPage();
    await clientPage.goto(signInUrl);
    await clientPage.locator('[data-test="portal-project"]').first().click();
    await clientPage.locator('[data-test="portal-message-body"]').fill("Please call me about hosting.");
    await Promise.all([
      clientPage.waitForURL((u) => u.toString().includes("/portal/project/")),
      clientPage.locator('[data-test="portal-message-send"]').click(),
    ]);
    await clientCtx.close();

    // Staff open the inbox — the message is there, badge visible.
    await page.getByRole("link", { name: "Inbox" }).click();
    await page.waitForURL((u) => u.toString().includes("/inbox"));
    await expect(page.locator('[data-test="inbox-group-messages"]')).toContainText("Please call me about hosting");
    await expect(page.locator('[data-test="inbox-badge"]')).toBeVisible();

    // Clicking the item deep-links to the project's Client access tab.
    await page.locator('[data-test="inbox-item-messages"]').first().click();
    await page.waitForURL((u) => /\/projects\/[0-9a-f-]+/.test(u.toString()));
    await expect(page.locator('[data-test="project-access"]')).toBeVisible();

    // Read the thread, then the inbox no longer shows this message.
    await page.locator('[data-test="access-message"]').first().click();
    await page.getByRole("link", { name: "Inbox" }).click();
    await page.waitForURL((u) => u.toString().includes("/inbox"));
    await expect(page.getByText("Please call me about hosting")).toHaveCount(0);
  });

  test("inbox endpoint rejects unauthenticated callers", async ({ request }) => {
    const res = await request.get("/breakfast-admin/api/v1/inbox");
    expect(res.status()).toBe(401);
  });
});
