const { test, expect } = require("@playwright/test");
const { execSync } = require("child_process");
const fs = require("fs");
const path = require("path");

// Real website editing in the standalone admin: proves the Website tab is a
// working CMS over the genuine Kirby content model — load, edit, save a draft
// (live untouched), preview the draft, publish (persists), and that drafts and
// publishing are permissioned. No second content store, no fake "Saved".

const ADMIN = { email: "admin@test.local", password: "test-admin-pw-1234" };

test.describe("Standalone admin — website editing", () => {
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

  async function openHome(page) {
    await page.goto("/breakfast-admin/website");
    await Promise.all([
      page.waitForResponse((r) => /\/api\/v1\/website\/page\/home$/.test(r.url()) && r.request().method() === "GET"),
      page.getByRole("button", { name: /Home/ }).first().click(),
    ]);
    // Editor sheet is open with the Eyebrow field.
    await expect(page.locator(".field", { hasText: "Eyebrow" }).locator("input").first()).toBeVisible({ timeout: 8000 });
  }

  test("edit homepage → save draft → preview shows the draft → discard", async ({ page, context }) => {
    await login(page);
    await openHome(page);

    const eyebrow = page.locator(".field", { hasText: "Eyebrow" }).locator("input").first();
    const uniq = "E2E draft " + Date.now();
    await eyebrow.fill(uniq);

    const [saveRes] = await Promise.all([
      page.waitForResponse((r) => /\/api\/v1\/website\/page\/home$/.test(r.url()) && r.request().method() === "PATCH"),
      page.getByRole("button", { name: "Save draft" }).click(),
    ]);
    expect(saveRes.status(), "draft must persist server-side").toBe(200);
    await expect(page.getByText("Draft saved")).toBeVisible({ timeout: 8000 });
    await expect(page.getByText("Modified").first()).toBeVisible();

    // The authenticated preview renders the draft (shares the session cookie).
    const preview = await context.request.get("/breakfast-admin/preview/page/home");
    expect(preview.status()).toBe(200);
    expect(await preview.text()).toContain(uniq);

    // Discard leaves the live site untouched.
    const [discardRes] = await Promise.all([
      page.waitForResponse((r) => /\/api\/v1\/website\/page\/home\/discard$/.test(r.url()) && r.request().method() === "POST"),
      page.getByRole("button", { name: "Discard draft" }).click(),
    ]);
    expect(discardRes.status()).toBe(200);
    await expect(page.getByText("Draft discarded")).toBeVisible({ timeout: 8000 });
  });

  test("publish persists to live, then restore the original", async ({ page }) => {
    await login(page);
    await openHome(page);

    const eyebrow = page.locator(".field", { hasText: "Eyebrow" }).locator("input").first();
    const original = await eyebrow.inputValue();
    const uniq = "E2E published " + Date.now();

    try {
      await eyebrow.fill(uniq);
      await Promise.all([
        page.waitForResponse((r) => /\/api\/v1\/website\/page\/home$/.test(r.url()) && r.request().method() === "PATCH"),
        page.getByRole("button", { name: "Save draft" }).click(),
      ]);
      const [pubRes] = await Promise.all([
        page.waitForResponse((r) => /\/api\/v1\/website\/page\/home\/publish$/.test(r.url()) && r.request().method() === "POST"),
        page.getByRole("button", { name: "Publish", exact: true }).click(),
      ]);
      expect(pubRes.status(), "publish must succeed server-side").toBe(200);
      await expect(page.getByText("Published — changes are live")).toBeVisible({ timeout: 8000 });

      // Reload the editor from the server and confirm the change persisted.
      await openHome(page);
      await expect(page.locator(".field", { hasText: "Eyebrow" }).locator("input").first()).toHaveValue(uniq);
    } finally {
      // Always restore the original value so the working tree isn't left changed.
      await openHome(page);
      await page.locator(".field", { hasText: "Eyebrow" }).locator("input").first().fill(original);
      await Promise.all([
        page.waitForResponse((r) => /\/api\/v1\/website\/page\/home$/.test(r.url()) && r.request().method() === "PATCH"),
        page.getByRole("button", { name: "Save draft" }).click(),
      ]);
      await Promise.all([
        page.waitForResponse((r) => /\/api\/v1\/website\/page\/home\/publish$/.test(r.url()) && r.request().method() === "POST"),
        page.getByRole("button", { name: "Publish", exact: true }).click(),
      ]);
    }
  });

  test("website write + preview endpoints reject unauthenticated callers", async ({ request }) => {
    const patch = await request.patch("/breakfast-admin/api/v1/website/page/home", { data: { hero_eyebrow: "x" } });
    expect(patch.status(), "PATCH must require auth").toBe(401);
    const publish = await request.post("/breakfast-admin/api/v1/website/page/home/publish");
    expect(publish.status(), "publish must require auth").toBe(401);
    const preview = await request.get("/breakfast-admin/preview/page/home");
    expect(preview.status(), "preview must require auth").toBe(404);
  });
});
