const { test, expect } = require("@playwright/test");
const { execSync } = require("child_process");
const fs = require("fs");
const path = require("path");

// Change requests + scope control. Proves the full vertical through the real UI:
// draft a priced change, send it (freezes a snapshot + real PDF + client token),
// approve it on the hosted client page (explicit + evidenced), then apply it to
// the project as an admin — which adds approved value, generates a task and
// drafts an invoice. Desktop-only (the state machine + idempotency + pricing are
// covered project-agnostically by ChangeRequestsTest).

const ADMIN = { email: "admin@test.local", password: "test-admin-pw-1234" };

test.describe("Standalone admin — change requests", () => {
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

  test("draft → send → client approves → apply to project", async ({ page }, testInfo) => {
    test.skip(testInfo.project.name === "mobile", "desktop-only multi-step journey");
    await login(page);

    // New project.
    await page.goto("/breakfast-admin/projects");
    await page.getByRole("button", { name: "New project" }).first().click();
    await page.locator(".sheet").getByPlaceholder("e.g. Roberts Cafe website").fill("Scope Change " + Date.now());
    await Promise.all([
      page.waitForResponse((r) => r.url().endsWith("/api/v1/projects") && r.request().method() === "POST"),
      page.locator(".sheet").getByRole("button", { name: "Create project" }).click(),
    ]);
    await page.waitForURL((u) => /\/projects\/[0-9a-f-]+/.test(u.toString()), { timeout: 10000 });

    // Change requests tab (exact avoids matching nothing else).
    await page.getByRole("tab", { name: "Change requests", exact: true }).click();
    await expect(page.locator('[data-test="project-change-requests"]')).toBeVisible();

    // Draft a priced change: one fixed £800 line.
    await page.locator('[data-test="cr-title"]').fill("Add a bilingual blog");
    await page.locator('[data-test="cr-description"]').fill("Client wants a Welsh/English blog section.");
    await page.locator('[data-test="cr-item-desc-0"]').fill("Blog templates");
    await page.locator('[data-test="cr-item-price-0"]').fill("800");
    await Promise.all([
      page.waitForResponse((r) => r.url().endsWith("/api/v1/change-requests") && r.request().method() === "POST"),
      page.locator('[data-test="cr-create"]').click(),
    ]);
    await expect(page.locator('[data-test="cr-draft"]')).toBeVisible();
    await expect(page.locator('[data-test="cr-draft"]')).toContainText("£800.00");

    // Send freezes the snapshot + PDF + token.
    await page.locator('[data-test="cr-draft"] .crrow__head').click();
    await Promise.all([
      page.waitForResponse((r) => /\/change-requests\/[^/]+\/send$/.test(r.url()) && r.request().method() === "POST"),
      page.locator('[data-test="cr-send"]').click(),
    ]);
    const publicUrl = await page.locator('[data-test="cr-public-url"]').inputValue();
    expect(publicUrl).toContain("/change/");
    const projectUrl = page.url();

    // Client approves on the hosted page (explicit + evidenced).
    await page.goto(publicUrl);
    await expect(page.locator('[data-test="change-request"]')).toContainText("Add a bilingual blog");
    await page.locator("#cr-name").fill("Angharad Roberts");
    await page.locator("#cr-consent").check();
    await Promise.all([
      page.waitForURL((u) => u.toString().includes("/change/"), { timeout: 10000 }),
      page.locator('[data-test="cr-approve"]').click(),
    ]);
    await expect(page.locator('[data-test="cr-approved"]')).toBeVisible();

    // Back in admin: the change is approved; apply it to the project.
    page.on("dialog", (d) => d.accept());
    await page.goto(projectUrl);
    await page.getByRole("tab", { name: "Change requests", exact: true }).click();
    const approvedRow = page.locator('[data-test="cr-approved"]').first();
    await expect(approvedRow).toBeVisible();
    await approvedRow.locator(".crrow__head").click();
    await Promise.all([
      page.waitForResponse((r) => /\/change-requests\/[^/]+\/apply$/.test(r.url()) && r.request().method() === "POST"),
      page.locator('[data-test="cr-apply"]').click(),
    ]);
    await expect(page.locator('[data-test="cr-in_progress"]').first()).toContainText("applied");
  });

  test("change-request endpoints reject unauthenticated callers", async ({ request }) => {
    const list = await request.get("/breakfast-admin/api/v1/change-requests");
    expect(list.status()).toBe(401);
    const apply = await request.post("/breakfast-admin/api/v1/change-requests/x/apply", { data: {} });
    expect(apply.status()).toBe(401);
  });

  test("unknown client change token renders 404", async ({ request }) => {
    const res = await request.get("/change/not-a-real-token");
    expect(res.status()).toBe(404);
  });
});
