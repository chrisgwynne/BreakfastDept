const { test, expect } = require("@playwright/test");
const { execSync } = require("child_process");
const fs = require("fs");
const path = require("path");

// The standalone Breakfast Admin application (a custom Vue SPA at
// /breakfast-admin, NOT the Kirby Panel). These tests seed a real admin, drive
// the bespoke login form, and assert two things: the app authenticates a valid
// admin and lands them in the shell, and — crucially — that the admin API
// enforces authentication on the SERVER for every protected endpoint, so nothing
// depends on the client hiding it. (Fine-grained RBAC is covered by the PHP
// AdminApiTest; here we prove the browser-facing guarantees.)

const ADMIN = { email: "admin@test.local", password: "test-admin-pw-1234" };

test.describe("Breakfast Admin — standalone app", () => {
  test.beforeAll(() => {
    execSync(`php bin/console user:seed --email=${ADMIN.email} --password=${ADMIN.password} --role=admin`, {
      cwd: process.cwd(),
      stdio: "inherit",
    });
    // Kirby throttles logins after repeated failures per IP/email and persists
    // the counter in storage/accounts/.logins. The deliberate wrong-password test
    // below increments it; clear it so a stale counter can't fail a fresh run.
    try {
      fs.rmSync(path.join(process.cwd(), "storage", "accounts", ".logins"), { force: true });
    } catch {
      /* no throttle file yet */
    }
  });

  async function login(page, user) {
    await page.goto("/breakfast-admin/login");
    await page.locator("#email").fill(user.email);
    await page.locator("#password").fill(user.password);
    const [resp] = await Promise.all([
      page
        .waitForResponse((r) => r.url().includes("/breakfast-admin/api/v1/session") && r.request().method() === "POST", { timeout: 20000 })
        .catch(() => null),
      page.getByRole("button", { name: /sign in/i }).click(),
    ]);
    return resp ? resp.status() : 0;
  }

  test("the bespoke login screen renders (not the Kirby Panel)", async ({ page }) => {
    await page.goto("/breakfast-admin/login");
    await expect(page.locator("#password")).toBeVisible({ timeout: 15000 });
    await expect(page.getByText(/welcome back/i)).toBeVisible();
    // The Panel's login markup uses name="email"; the SPA uses ids only.
    expect(await page.locator('input[name="email"]').count()).toBe(0);
  });

  test("a wrong password is rejected without disclosing the account", async ({ page }) => {
    const status = await login(page, { email: ADMIN.email, password: "wrong-password" });
    expect(status, "auth must not succeed").not.toBe(200);
    await expect(page.locator("#password")).toBeVisible();
    await expect(page.getByRole("alert")).toBeVisible();
  });

  test("a valid admin signs in and lands in the shell", async ({ page }) => {
    const status = await login(page, ADMIN);
    expect(status, "auth ok").toBe(200);
    await page.waitForURL((u) => !u.toString().includes("/login"), { timeout: 15000 });
    // The custom navigation rail — a standalone app, not the Panel.
    await expect(page.getByRole("link", { name: "Dashboard" })).toBeVisible({ timeout: 15000 });
    await expect(page.getByRole("link", { name: "Leads" })).toBeVisible();
    await expect(page.getByRole("link", { name: "Pipeline" })).toBeVisible();
  });

  test("the admin API refuses unauthenticated requests server-side", async ({ request }) => {
    // The `request` fixture carries no session cookie. Every protected endpoint
    // must answer 401 JSON — the access control lives on the server.
    for (const endpoint of ["session", "dashboard", "enquiries", "opportunities", "operations"]) {
      const res = await request.get(`/breakfast-admin/api/v1/${endpoint}`);
      expect(res.status(), `${endpoint} must require auth`).toBe(401);
      expect(res.headers()["content-type"] || "").toContain("application/json");
    }
  });
});
