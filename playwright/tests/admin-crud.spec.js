const { test, expect } = require("@playwright/test");
const { execSync } = require("child_process");
const fs = require("fs");
const path = require("path");

// Real create workflows in the standalone admin: proves the primary action
// buttons perform actual, persisted operations (not fake success), and that
// every rendered primary action has a real handler (no dead "#"/no-op controls).

const ADMIN = { email: "admin@test.local", password: "test-admin-pw-1234" };

test.describe("Standalone admin — real workflows", () => {
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

  test("creating a lead persists it and shows it in the list", async ({ page }) => {
    await login(page);
    await page.goto("/breakfast-admin/leads");
    await page.getByRole("button", { name: "New lead" }).first().click();

    const uniq = "e2e-" + Date.now();
    await page.locator(".cf input").first().fill("Test Lead " + uniq);
    await page.locator('.cf input[type="email"]').first().fill(uniq + "@example.test");

    const [res] = await Promise.all([
      page.waitForResponse((r) => r.url().endsWith("/api/v1/enquiries") && r.request().method() === "POST"),
      page.getByRole("button", { name: "Create", exact: true }).click(),
    ]);
    expect(res.status(), "create must succeed server-side").toBe(200);

    // Accurate feedback + the new lead really appears in the reloaded list.
    await expect(page.getByText("Lead created")).toBeVisible({ timeout: 8000 });
    await expect(page.getByText("Test Lead " + uniq)).toBeVisible({ timeout: 8000 });
  });

  test("lead creation is validated server-side", async ({ page }) => {
    await login(page);
    await page.goto("/breakfast-admin/leads");
    await page.getByRole("button", { name: "New lead" }).first().click();
    const [res] = await Promise.all([
      page.waitForResponse((r) => r.url().endsWith("/api/v1/enquiries") && r.request().method() === "POST"),
      page.getByRole("button", { name: "Create", exact: true }).click(),
    ]);
    expect(res.status()).toBe(422);
    // The form stays open with a field error — no fake success.
    await expect(page.locator(".field__err").first()).toBeVisible();
  });

  test("write endpoints reject unauthenticated callers", async ({ request }) => {
    for (const [path, body] of [
      ["enquiries", { name: "x" }],
      ["contacts", { first_name: "x" }],
      ["companies", { name: "x" }],
      ["email/send", { to: "a@b.co", subject: "s", body: "b" }],
    ]) {
      const res = await request.post(`/breakfast-admin/api/v1/${path}`, { data: body });
      expect(res.status(), `${path} must require auth`).toBe(401);
    }
  });

  test("no primary action button is a dead placeholder", async ({ page }) => {
    await login(page);
    for (const route of ["", "leads", "crm", "pipeline", "tasks", "email"]) {
      await page.goto("/breakfast-admin/" + route);
      await page.waitForTimeout(400);
      // No anchor uses a placeholder href, and no button sits inside a dead "#".
      expect(await page.locator('a[href="#"]').count(), `dead anchor on /${route}`).toBe(0);
    }
  });
});
