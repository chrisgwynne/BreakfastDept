const { test, expect } = require("@playwright/test");
const { execSync } = require("child_process");
const fs = require("fs");
const path = require("path");

// Authenticated Breakfast Admin suite. Seeds a real admin + a limited writer
// (additively — only these test emails are touched), logs in through the actual
// Kirby Panel login form, and asserts the branded admin, navigation and — most
// importantly — that forbidden actions are refused by the SERVER (403), not
// merely hidden in the UI.

const ADMIN = { email: "admin@test.local", password: "test-admin-pw-1234" };
const WRITER = { email: "writer@test.local", password: "test-writer-pw-1234" };

test.describe("Breakfast Admin — authenticated", () => {
  test.beforeAll(() => {
    execSync(`php bin/console user:seed --email=${ADMIN.email} --password=${ADMIN.password} --role=admin`, { cwd: process.cwd(), stdio: "inherit" });
    execSync(`php bin/console user:seed --email=${WRITER.email} --password=${WRITER.password} --role=writer`, { cwd: process.cwd(), stdio: "inherit" });
    // Kirby throttles logins after 10 failed attempts per IP/email and persists
    // the counter in storage/accounts/.logins. The deliberate "invalid password"
    // test below increments it, and it accumulates across local runs — a stale
    // counter would make even a correct password return "Invalid login". Reset
    // it so the throttle never contaminates a fresh run.
    const throttle = path.join(process.cwd(), "storage", "accounts", ".logins");
    try {
      fs.rmSync(throttle, { force: true });
    } catch {
      /* no throttle file yet — nothing to clear */
    }
  });

  // Test users live only in the gitignored storage/accounts and are harmless to
  // leave; Kirby refuses to delete the last admin, so we don't attempt cleanup.

  async function login(page, user) {
    await page.goto("/breakfast-admin/login");
    await page.locator('input[name="email"]').first().fill(user.email);
    await page.locator('input[name="password"]').first().fill(user.password);
    // Submit and wait for the actual auth API call to resolve, so the session
    // cookie is established before the test proceeds.
    const [resp] = await Promise.all([
      page.waitForResponse((r) => r.url().includes("/api/auth/login") && r.request().method() === "POST", { timeout: 20000 }).catch(() => null),
      page.getByRole("button", { name: /log in/i }).first().click(),
    ]);
    await page.waitForTimeout(500);
    return resp ? resp.status() : 0;
  }

  test("login page renders the Panel login form", async ({ page }) => {
    await page.goto("/breakfast-admin/login");
    await expect(page.locator('input[type="password"], input[name="password"]').first()).toBeVisible({ timeout: 15000 });
  });

  test("invalid password is rejected", async ({ page }) => {
    const status = await login(page, { email: ADMIN.email, password: "wrong-password" });
    expect(status, "auth must not succeed").not.toBe(200);
    // Still on the login screen; no session established.
    await expect(page.locator('input[name="password"]').first()).toBeVisible();
  });

  test("valid login authenticates and shows the custom Breakfast Admin nav", async ({ page }, testInfo) => {
    // The Panel side-nav is collapsed behind a toggle on mobile viewports; the
    // nav content is identical, so assert it on desktop.
    test.skip(testInfo.project.name === "mobile", "nav is behind a menu toggle on mobile");

    const status = await login(page, ADMIN);
    expect(status, "auth ok").toBe(200);

    // The custom Breakfast Admin navigation (rendered from panel.menu) replaces
    // the default Panel menu — a branded, task-oriented nav.
    await expect(page.getByText("Client Previews", { exact: true }).first()).toBeVisible({ timeout: 20000 });
    await expect(page.getByText("Dashboard", { exact: true }).first()).toBeVisible();
    await expect(page.getByText("Leads", { exact: true }).first()).toBeVisible();

    // Logout invalidates the session (login form returns).
    await page.goto("/breakfast-admin/logout");
    await expect(page.locator('input[name="password"]').first()).toBeVisible({ timeout: 15000 });
  });

  test("forbidden actions are refused by the server (403), not just hidden", async ({ page }) => {
    const status = await login(page, WRITER);
    expect(status, "writer auth ok").toBe(200);

    // A Writer has no previews.view grant → the previews API must 403 server-side,
    // and admin-only operations must 403 too. The Panel API authenticates via the
    // session cookie + the CSRF token read from the running Panel.
    const csrf = await page.evaluate(() => (window.panel && window.panel.system && window.panel.system.csrf) || "");
    const headers = { "x-csrf": csrf };

    const previews = await page.request.get("/api/breakfast/previews", { headers });
    expect(previews.status(), "writer previews.view must 403").toBe(403);

    const ops = await page.request.get("/api/breakfast/admin/ops", { headers });
    expect(ops.status(), "writer admin ops must 403").toBe(403);
  });
});
