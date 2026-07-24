const { test, expect } = require("@playwright/test");
const { execSync } = require("child_process");
const fs = require("fs");
const path = require("path");

// Website content sections (page-builder blocks) in the standalone admin: add,
// reorder and delete real sections on a page, each persisted to the draft. Runs
// on desktop (the service layer is covered project-agnostically by the PHPUnit
// WebsiteSections tests).

const ADMIN = { email: "admin@test.local", password: "test-admin-pw-1234" };

test.describe("Standalone admin — website sections", () => {
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

  test.afterAll(() => {
    // Section edits write to the page draft; leave the repo content clean.
    try {
      execSync("git checkout content/ >/dev/null 2>&1 || true", { cwd: process.cwd() });
      execSync("rm -rf content/*/_changes >/dev/null 2>&1 || true", { cwd: process.cwd() });
    } catch {
      /* best effort */
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

  test("add, reorder then delete a section", async ({ page }, testInfo) => {
    test.skip(testInfo.project.name === "mobile", "desktop-only multi-step journey");
    await login(page);
    page.on("dialog", (d) => d.accept()); // delete confirm

    await page.goto("/breakfast-admin/website");
    // Accessibility is a `legal` page with a Body blocks field.
    await page.getByText("Accessibility", { exact: true }).first().click();
    await expect(page.locator(".sheet .sections")).toBeVisible({ timeout: 8000 });

    const before = await page.locator(".sheet .sec").count();

    // Add a text section.
    await page.locator(".addbar__sel").selectOption("text");
    const [addRes] = await Promise.all([
      page.waitForResponse((r) => /\/sections$/.test(r.url()) && r.request().method() === "POST"),
      page.locator(".addbar").getByRole("button", { name: "Add" }).click(),
    ]);
    expect(addRes.status(), "add must succeed server-side").toBe(200);
    await expect(page.locator(".sheet .sec")).toHaveCount(before + 1, { timeout: 8000 });

    // Reorder: move the first section down.
    const [reRes] = await Promise.all([
      page.waitForResponse((r) => /\/sections\/reorder$/.test(r.url()) && r.request().method() === "POST"),
      page.locator(".sheet .sec").first().getByRole("button", { name: "↓" }).click(),
    ]);
    expect(reRes.status()).toBe(200);

    // Delete the last (newly added) section.
    const [delRes] = await Promise.all([
      page.waitForResponse((r) => /\/sections\//.test(r.url()) && r.request().method() === "DELETE"),
      page.locator(".sheet .sec").last().getByRole("button", { name: "×" }).click(),
    ]);
    expect(delRes.status()).toBe(200);
    await expect(page.locator(".sheet .sec")).toHaveCount(before, { timeout: 8000 });
  });

  test("section endpoints reject unauthenticated callers", async ({ request }) => {
    const list = await request.get("/breakfast-admin/api/v1/website/page/accessibility/sections");
    expect(list.status()).toBe(401);
    const add = await request.post("/breakfast-admin/api/v1/website/page/accessibility/sections", { data: { type: "text" } });
    expect(add.status()).toBe(401);
  });
});
