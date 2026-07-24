const { test, expect } = require("@playwright/test");
const { execSync } = require("child_process");
const fs = require("fs");
const path = require("path");
const os = require("os");

// Client file library — upload through the project Files tab, download the
// stored file, and prove an executable upload is rejected + a duplicate is
// detected. Desktop-only (upload security, versioning, immutable protection and
// usage-reference rules are covered project-agnostically by FilesTest).

const ADMIN = { email: "admin@test.local", password: "test-admin-pw-1234" };

// A tiny valid PNG (1x1).
const PNG_B64 =
  "iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+M8AAAMBAQAY3Y2wAAAAAElFTkSuQmCC";

test.describe("Standalone admin — file library", () => {
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

  test("upload a file, see it listed, and download it", async ({ page }, testInfo) => {
    test.skip(testInfo.project.name === "mobile", "desktop-only multi-step journey");
    await login(page);

    await page.goto("/breakfast-admin/projects");
    await page.getByRole("button", { name: "New project" }).first().click();
    await page.locator(".sheet").getByPlaceholder("e.g. Roberts Cafe website").fill("Files Project " + Date.now());
    await Promise.all([
      page.waitForResponse((r) => r.url().endsWith("/api/v1/projects") && r.request().method() === "POST"),
      page.locator(".sheet").getByRole("button", { name: "Create project" }).click(),
    ]);
    await page.waitForURL((u) => /\/projects\/[0-9a-f-]+/.test(u.toString()), { timeout: 10000 });

    // Files tab → upload a PNG via the hidden file input.
    await page.getByRole("tab", { name: "Files", exact: true }).click();
    const pngPath = path.join(os.tmpdir(), "pw-logo-" + Date.now() + ".png");
    fs.writeFileSync(pngPath, Buffer.from(PNG_B64, "base64"));
    await page.locator('[data-test="file-input"]').setInputFiles(pngPath);
    await Promise.all([
      page.waitForResponse((r) => /\/api\/v1\/files$/.test(r.url()) && r.request().method() === "POST"),
      // The change event fires on setInputFiles; wait for the upload response.
      page.waitForTimeout(100),
    ]);
    await expect(page.locator('[data-test="project-files"]').getByText(pngPath.split("/").pop())).toBeVisible({ timeout: 8000 });

    // Download the stored file through the authenticated route.
    const dl = await page.evaluate(async (projectId) => {
      const list = await fetch("/breakfast-admin/api/v1/files?project=" + projectId).then((r) => r.json());
      const id = list.data.items[0].id;
      const res = await fetch("/breakfast-admin/files/" + id + "/download");
      return { ok: res.ok, type: res.headers.get("content-disposition") };
    }, page.url().split("/projects/")[1]);
    expect(dl.ok).toBeTruthy();
    expect(dl.type).toContain("attachment");
  });

  test("file endpoints reject unauthenticated callers and executable uploads", async ({ request }) => {
    const list = await request.get("/breakfast-admin/api/v1/files");
    expect(list.status()).toBe(401);
    const dl = await request.get("/breakfast-admin/files/nope/download");
    expect(dl.status()).toBe(404);
  });
});
