const { test, expect } = require("@playwright/test");
const { execSync } = require("child_process");
const fs = require("fs");
const path = require("path");

// Client portal (Phase 3 foundation). Proves the whole passwordless vertical:
// staff invite a client to a project (create identity + grant + mint link), the
// client signs in through the single-use link in a fresh browser context (no
// staff session), lands on their portal and sees exactly the granted project —
// and the public self-service login page works end to end. Desktop-only for the
// multi-step journey; the domain rules are covered by PortalTest.

const ADMIN = { email: "admin@test.local", password: "test-admin-pw-1234" };

test.describe("Standalone admin — client portal", () => {
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

  test("staff invite → client signs in via link → sees granted project", async ({ page, browser }, testInfo) => {
    test.skip(testInfo.project.name === "mobile", "desktop-only multi-step journey");
    await login(page);

    const projectName = "Portal Project " + Date.now();
    await page.goto("/breakfast-admin/projects");
    await page.getByRole("button", { name: "New project" }).first().click();
    await page.locator(".sheet").getByPlaceholder("e.g. Roberts Cafe website").fill(projectName);
    await Promise.all([
      page.waitForResponse((r) => r.url().endsWith("/api/v1/projects") && r.request().method() === "POST"),
      page.locator(".sheet").getByRole("button", { name: "Create project" }).click(),
    ]);
    await page.waitForURL((u) => /\/projects\/[0-9a-f-]+/.test(u.toString()), { timeout: 10000 });

    // Client access tab → invite a client.
    await page.getByRole("tab", { name: "Client access", exact: true }).click();
    await expect(page.locator('[data-test="project-access"]')).toBeVisible();
    const clientEmail = `client${Date.now()}@example.com`;
    await page.locator('[data-test="access-email"]').fill(clientEmail);
    await page.locator('[data-test="access-name"]').fill("Sian Roberts");
    await Promise.all([
      page.waitForResponse((r) => r.url().endsWith("/api/v1/portal/invite") && r.request().method() === "POST"),
      page.locator('[data-test="access-add"]').click(),
    ]);
    await expect(page.locator('[data-test="access-row"]')).toBeVisible();
    const signInUrl = await page.locator('[data-test="access-link"]').inputValue();
    expect(signInUrl).toContain("/portal/verify/");

    // The client signs in from a clean context (no staff session at all).
    const clientCtx = await browser.newContext();
    const clientPage = await clientCtx.newPage();
    await clientPage.goto(signInUrl);
    await expect(clientPage.locator('[data-test="portal-home"]')).toBeVisible();
    await expect(clientPage.locator('[data-test="portal-project"]')).toContainText(projectName);

    // The one-shot link cannot be replayed.
    await clientPage.goto(signInUrl);
    await expect(clientPage.locator('[data-test="portal-error"]')).toBeVisible();

    // But the established session still works.
    await clientPage.goto("/portal");
    await expect(clientPage.locator('[data-test="portal-home"]')).toBeVisible();

    // Sign out clears the session.
    await clientPage.locator('[data-test="portal-logout"]').click();
    await expect(clientPage.locator('[data-test="portal-login"]')).toBeVisible();
    await clientCtx.close();
  });

  test("public self-service login page issues a working link", async ({ page }, testInfo) => {
    test.skip(testInfo.project.name === "mobile", "desktop-only multi-step journey");
    // Seed an identity with project access through the admin API first.
    await login(page);
    const projectName = "Self Serve " + Date.now();
    await page.goto("/breakfast-admin/projects");
    await page.getByRole("button", { name: "New project" }).first().click();
    await page.locator(".sheet").getByPlaceholder("e.g. Roberts Cafe website").fill(projectName);
    await Promise.all([
      page.waitForResponse((r) => r.url().endsWith("/api/v1/projects") && r.request().method() === "POST"),
      page.locator(".sheet").getByRole("button", { name: "Create project" }).click(),
    ]);
    await page.waitForURL((u) => /\/projects\/[0-9a-f-]+/.test(u.toString()), { timeout: 10000 });
    const email = `selfserve${Date.now()}@example.com`;
    await page.getByRole("tab", { name: "Client access", exact: true }).click();
    await page.locator('[data-test="access-email"]').fill(email);
    await Promise.all([
      page.waitForResponse((r) => r.url().endsWith("/api/v1/portal/invite") && r.request().method() === "POST"),
      page.locator('[data-test="access-add"]').click(),
    ]);

    // Now use the PUBLIC login page as the client would.
    await page.context().clearCookies();
    await page.goto("/portal");
    await expect(page.locator('[data-test="portal-login"]')).toBeVisible();
    await page.locator('[data-test="portal-email"]').fill(email);
    await Promise.all([
      page.waitForResponse((r) => r.url().endsWith("/portal/login") && r.request().method() === "POST"),
      page.locator('[data-test="portal-submit"]').click(),
    ]);
    // Dev link is revealed outside production; follow it to sign in.
    const devLink = await page.locator('[data-test="portal-dev-link"]').getAttribute("href");
    expect(devLink).toContain("/portal/verify/");
    await page.goto(devLink);
    await expect(page.locator('[data-test="portal-home"]')).toContainText(projectName);
  });

  test("portal admin API rejects unauthenticated callers", async ({ request }) => {
    const list = await request.get("/breakfast-admin/api/v1/portal");
    expect(list.status()).toBe(401);
    const invite = await request.post("/breakfast-admin/api/v1/portal/invite", { data: { project_uuid: "x", email: "a@b.co" } });
    expect(invite.status()).toBe(401);
  });
});
