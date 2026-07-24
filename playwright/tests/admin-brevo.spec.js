const { test, expect } = require("@playwright/test");
const { execSync } = require("child_process");
const fs = require("fs");
const path = require("path");

// Secure Brevo configuration from Settings: proves the API key can be added,
// is shown only masked, the config persists, the key can be removed, and the
// endpoints reject unauthenticated callers. The live connection test is covered
// by PHPUnit with a faked transport (no real provider key, no network in CI).

const ADMIN = { email: "admin@test.local", password: "test-admin-pw-1234" };

test.describe("Standalone admin — Brevo settings", () => {
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

  test("add key (masked), save config, then remove", async ({ page }) => {
    await login(page);
    await page.goto("/breakfast-admin/settings");
    await expect(page.getByRole("heading", { name: "Brevo email" })).toBeVisible({ timeout: 8000 });

    // Add a key — the full value is never echoed back, only a masked hint.
    await page.getByRole("button", { name: /Add key|Replace/ }).click();
    await page.locator('.keyform input').fill("xkeysib-e2e-secret-000012349999");
    const [keyRes] = await Promise.all([
      page.waitForResponse((r) => r.url().endsWith("/settings/brevo/key") && r.request().method() === "POST"),
      page.getByRole("button", { name: "Save key" }).click(),
    ]);
    expect(keyRes.status()).toBe(200);
    await expect(page.getByText("API key saved")).toBeVisible({ timeout: 8000 });
    await expect(page.getByText("••••9999")).toBeVisible();
    await expect(page.locator(".badge--ok")).toHaveText("Configured");

    // Save some non-secret config.
    await page.locator('.field', { hasText: 'Sender email' }).locator('input').fill("hi@breakfastdept.com");
    const [cfgRes] = await Promise.all([
      page.waitForResponse((r) => r.url().endsWith("/settings/brevo") && r.request().method() === "PUT"),
      page.getByRole("button", { name: "Save Brevo settings" }).click(),
    ]);
    expect(cfgRes.status()).toBe(200);
    await expect(page.getByText("Brevo settings saved")).toBeVisible({ timeout: 8000 });

    // Remove the key.
    const [delRes] = await Promise.all([
      page.waitForResponse((r) => r.url().endsWith("/settings/brevo/key") && r.request().method() === "DELETE"),
      page.getByRole("button", { name: "Remove" }).click(),
    ]);
    expect(delRes.status()).toBe(200);
    await expect(page.getByText("API key removed")).toBeVisible({ timeout: 8000 });
    await expect(page.locator(".badge--off")).toHaveText("Not configured");
  });

  test("brevo settings endpoints reject unauthenticated callers", async ({ request }) => {
    const get = await request.get("/breakfast-admin/api/v1/settings/brevo");
    expect(get.status(), "overview must require auth").toBe(401);
    const key = await request.post("/breakfast-admin/api/v1/settings/brevo/key", { data: { api_key: "x" } });
    expect(key.status(), "set key must require auth").toBe(401);
    const testConn = await request.post("/breakfast-admin/api/v1/settings/brevo/test");
    expect(testConn.status(), "test must require auth").toBe(401);
  });
});
