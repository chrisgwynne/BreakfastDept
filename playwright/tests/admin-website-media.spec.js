const { test, expect } = require("@playwright/test");
const { execSync } = require("child_process");
const fs = require("fs");
const path = require("path");

// Website media library in the standalone admin: proves a real file is uploaded
// over Kirby's file model, listed with its metadata, and deleted again — no fake
// states. Runs on desktop (the service layer is covered project-agnostically by
// the PHPUnit WebsiteMedia tests).

const ADMIN = { email: "admin@test.local", password: "test-admin-pw-1234" };

// A real 1×1 PNG.
const PNG = Buffer.from(
  "iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAACklEQVR4nGNgAAAAAgAB4iG8MwAAAABJRU5ErkJggg==",
  "base64",
);

test.describe("Standalone admin — website media", () => {
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

  test("upload then delete a real file on a page", async ({ page }, testInfo) => {
    test.skip(testInfo.project.name === "mobile", "desktop-only multi-step journey");
    await login(page);
    page.on("dialog", (d) => d.accept()); // delete confirm

    await page.goto("/breakfast-admin/website");
    // Open a page editor (Contact is a stable public page).
    await page.getByText("Contact", { exact: true }).first().click();
    await expect(page.locator(".sheet .media")).toBeVisible({ timeout: 8000 });

    const unique = "pw-media-" + Date.now() + ".png";
    const [upRes] = await Promise.all([
      page.waitForResponse((r) => /\/media$/.test(r.url()) && r.request().method() === "POST"),
      page.locator('.sheet input[type="file"]').setInputFiles({ name: unique, mimeType: "image/png", buffer: PNG }),
    ]);
    expect(upRes.status(), "upload must succeed server-side").toBe(200);

    // The file appears in the library with its metadata (filename is slugified).
    const slug = unique.toLowerCase();
    await expect(page.locator(".mediaitem", { hasText: slug })).toBeVisible({ timeout: 8000 });

    // Delete it again (dialog auto-accepted), leaving content clean.
    const [delRes] = await Promise.all([
      page.waitForResponse((r) => /\/media\//.test(r.url()) && r.request().method() === "DELETE"),
      page.locator(".mediaitem", { hasText: slug }).getByRole("button", { name: "×" }).click(),
    ]);
    expect(delRes.status()).toBe(200);
    await expect(page.locator(".mediaitem", { hasText: slug })).toHaveCount(0, { timeout: 8000 });
  });

  test("media endpoints reject unauthenticated callers", async ({ request }) => {
    const list = await request.get("/breakfast-admin/api/v1/website/page/contact/media");
    expect(list.status()).toBe(401);
    const del = await request.delete("/breakfast-admin/api/v1/website/page/contact/media/x.png");
    expect(del.status()).toBe(401);
  });
});
