const { test, expect } = require("@playwright/test");
const { execSync } = require("child_process");
const fs = require("fs");
const path = require("path");

// Editing, converting and archiving CRM records from the standalone admin —
// real, server-authoritative mutations (no fake success). Each test creates its
// own lead through the UI so it is self-contained.

const ADMIN = { email: "admin@test.local", password: "test-admin-pw-1234" };

test.describe("Standalone admin — CRM lifecycle", () => {
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

  async function createLead(page, name, email) {
    await page.goto("/breakfast-admin/leads");
    await page.getByRole("button", { name: "New lead" }).first().click();
    await page.locator(".cf input").first().fill(name);
    await page.locator('.cf input[type="email"]').first().fill(email);
    await Promise.all([
      page.waitForResponse((r) => r.url().endsWith("/api/v1/enquiries") && r.request().method() === "POST"),
      page.getByRole("button", { name: "Create", exact: true }).click(),
    ]);
    await expect(page.getByText("Lead created")).toBeVisible({ timeout: 8000 });
  }

  test("convert a lead to an opportunity", async ({ page }) => {
    await login(page);
    const name = "Convert Me " + Date.now();
    await createLead(page, name, "convert" + Date.now() + "@example.test");

    await page.getByText(name).first().click();
    const [res] = await Promise.all([
      page.waitForResponse((r) => /\/api\/v1\/enquiries\/[^/]+\/convert$/.test(r.url()) && r.request().method() === "POST"),
      page.getByRole("button", { name: /Convert to opportunity/ }).click(),
    ]);
    expect(res.status(), "convert must succeed server-side").toBe(200);
    await expect(page.getByText("Lead converted to opportunity")).toBeVisible({ timeout: 8000 });
  });

  test("archive a lead", async ({ page }) => {
    await login(page);
    const name = "Archive Me " + Date.now();
    await createLead(page, name, "archive" + Date.now() + "@example.test");

    await page.getByText(name).first().click();
    const [res] = await Promise.all([
      page.waitForResponse((r) => /\/api\/v1\/enquiries\/[^/]+\/archive$/.test(r.url()) && r.request().method() === "POST"),
      page.locator(".sheet").getByRole("button", { name: "Archive" }).click(),
    ]);
    expect(res.status()).toBe(200);
    await expect(page.getByText("Lead archived")).toBeVisible({ timeout: 8000 });
  });

  test("edit then archive a contact", async ({ page }) => {
    await login(page);
    const name = "Editable Contact " + Date.now();
    await createLead(page, name, "editcontact" + Date.now() + "@example.test");

    // Open the contact from the CRM directory.
    await page.goto("/breakfast-admin/crm");
    await page.getByText(name).first().click();
    await page.waitForURL(/\/crm\/contacts\//, { timeout: 8000 });

    // Edit the phone number.
    await page.locator(".ident__actions").getByRole("button", { name: "Edit" }).click();
    await page.locator('.sheet .field', { hasText: 'Phone' }).locator('input').fill("01492 555111");
    const [editRes] = await Promise.all([
      page.waitForResponse((r) => /\/api\/v1\/contacts\/[^/]+$/.test(r.url()) && r.request().method() === "PATCH"),
      page.getByRole("button", { name: "Save changes" }).click(),
    ]);
    expect(editRes.status(), "edit must succeed server-side").toBe(200);
    await expect(page.getByText("Contact updated")).toBeVisible({ timeout: 8000 });
    await expect(page.getByText("01492 555111")).toBeVisible({ timeout: 8000 });

    // Archive it.
    const [archRes] = await Promise.all([
      page.waitForResponse((r) => /\/api\/v1\/contacts\/[^/]+\/archive$/.test(r.url()) && r.request().method() === "POST"),
      page.locator(".ident__actions").getByRole("button", { name: "Archive" }).click(),
    ]);
    expect(archRes.status()).toBe(200);
    await expect(page.getByText("Contact archived")).toBeVisible({ timeout: 8000 });
  });

  test("edit/convert/archive endpoints reject unauthenticated callers", async ({ request }) => {
    const patch = await request.patch("/breakfast-admin/api/v1/contacts/x", { data: { phone: "1" } });
    expect(patch.status()).toBe(401);
    const convert = await request.post("/breakfast-admin/api/v1/enquiries/x/convert");
    expect(convert.status()).toBe(401);
    const archive = await request.post("/breakfast-admin/api/v1/contacts/x/archive");
    expect(archive.status()).toBe(401);
  });
});
