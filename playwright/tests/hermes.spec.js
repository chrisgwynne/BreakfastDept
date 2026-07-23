const { test, expect } = require("@playwright/test");
const { execSync } = require("child_process");
const fs = require("fs");
const path = require("path");

// The Hermes integration console inside the standalone admin. Proves it is
// reachable and rendered for an authorised admin (not the Kirby Panel), and that
// its API enforces authentication server-side. Deep RBAC + data shaping are
// covered by the PHP HermesAdminTest; this is the browser-facing guarantee.

const ADMIN = { email: "admin@test.local", password: "test-admin-pw-1234" };

test.describe("Hermes console (standalone admin)", () => {
  test.beforeAll(() => {
    execSync(`php bin/console user:seed --email=${ADMIN.email} --password=${ADMIN.password} --role=admin`, {
      cwd: process.cwd(),
      stdio: "inherit",
    });
    try {
      fs.rmSync(path.join(process.cwd(), "storage", "accounts", ".logins"), { force: true });
    } catch {
      /* no throttle file yet */
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

  test("Hermes is in the nav and its console loads for an admin", async ({ page }) => {
    await login(page);
    // The nav entry is present for an authorised admin (visible in the rail on
    // desktop; in the DOM behind the menu toggle on mobile).
    await expect(page.getByRole("link", { name: "Hermes" })).toBeAttached({ timeout: 15000 });

    // The console itself loads at its route on any viewport.
    await page.goto("/breakfast-admin/hermes");
    await expect(page.getByRole("heading", { name: "Hermes", exact: true })).toBeVisible({ timeout: 15000 });
    // The console's own tabs — a standalone screen, not the Kirby Panel.
    for (const tab of ["Overview", "Credentials", "Scopes", "Activity", "Diagnostics"]) {
      await expect(page.getByRole("tab", { name: tab })).toBeVisible();
    }
    // Scopes tab always renders the full catalogue regardless of configuration.
    await page.getByRole("tab", { name: "Scopes" }).click();
    await expect(page.getByText("content:read", { exact: true })).toBeVisible({ timeout: 15000 });
  });

  test("the Hermes API refuses unauthenticated requests server-side", async ({ request }) => {
    for (const endpoint of ["overview", "credentials", "scopes", "activity", "health"]) {
      const res = await request.get(`/breakfast-admin/api/v1/hermes/${endpoint}`);
      expect(res.status(), `hermes/${endpoint} must require auth`).toBe(401);
      expect(res.headers()["content-type"] || "").toContain("application/json");
    }
  });
});
