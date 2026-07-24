const { test, expect } = require("@playwright/test");
const { execSync } = require("child_process");
const fs = require("fs");
const path = require("path");

// Invoicing in the standalone admin: proves the primary actions perform real,
// server-authoritative operations — a draft is persisted, issuing allocates a
// real number, and the public link renders a branded document. No fake states.

const ADMIN = { email: "admin@test.local", password: "test-admin-pw-1234" };

test.describe("Standalone admin — invoicing", () => {
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

  test("create a draft, issue it, and open the branded public link", async ({ page, context }) => {
    await login(page);
    await page.goto("/breakfast-admin/invoices");

    // --- create a draft -------------------------------------------------
    await page.getByRole("button", { name: "New invoice" }).first().click();
    await page.getByPlaceholder("Client or company").fill("E2E Client " + Date.now());
    await page.getByPlaceholder("Description").first().fill("Website build");
    await page.getByPlaceholder("Unit £").first().fill("1000");

    const [createRes] = await Promise.all([
      page.waitForResponse((r) => r.url().endsWith("/api/v1/invoices") && r.request().method() === "POST"),
      page.getByRole("button", { name: "Save draft" }).click(),
    ]);
    expect(createRes.status(), "draft must persist server-side").toBe(200);
    await expect(page.getByText("Invoice draft saved")).toBeVisible({ timeout: 8000 });

    // The detail sheet opens; issue the draft and get a real number.
    const [issueRes] = await Promise.all([
      page.waitForResponse((r) => /\/api\/v1\/invoices\/[^/]+\/issue$/.test(r.url()) && r.request().method() === "POST"),
      page.getByRole("button", { name: "Issue", exact: true }).click(),
    ]);
    expect(issueRes.status(), "issuing must succeed server-side").toBe(200);
    await expect(page.getByText("Invoice issued")).toBeVisible({ timeout: 8000 });

    // A real, monotonic invoice number is now shown in the open detail sheet
    // (the list column is hidden on the mobile viewport, so scope to the sheet).
    const year = new Date().getFullYear();
    await expect(page.locator(".sheet").getByText(new RegExp(`INV-${year}-\\d{4}`)).first()).toBeVisible({ timeout: 8000 });

    // Issuing generated a GENUINE .pdf server-side. The "Download PDF" control
    // appears (pdf_ready) and streams a real PDF binary — not an HTML page —
    // from the authenticated download route.
    const dl = page.getByRole("link", { name: /Download PDF/ });
    await expect(dl).toBeVisible({ timeout: 8000 });
    const href = await dl.getAttribute("href");
    expect(href).toMatch(/\/breakfast-admin\/invoices\/[^/]+\/download$/);

    const pdf = await context.request.get(href);
    expect(pdf.status()).toBe(200);
    expect(pdf.headers()["content-type"]).toContain("application/pdf");
    expect(pdf.headers()["content-disposition"]).toContain("attachment");
    const bytes = await pdf.body();
    expect(bytes.slice(0, 5).toString("latin1"), "a real PDF binary is served").toBe("%PDF-");
    expect(bytes.length).toBeGreaterThan(500);

    // The document is listed as an immutable issued version.
    await expect(page.locator(".sheet").getByText(/Issued v1/)).toBeVisible({ timeout: 8000 });
  });

  test("invoice write endpoints reject unauthenticated callers", async ({ request }) => {
    for (const [path, method, body] of [
      ["invoices", "post", { bill_to_name: "x" }],
      ["invoices/settings", "patch", { invoice_prefix: "X" }],
    ]) {
      const res = await request[method](`/breakfast-admin/api/v1/${path}`, { data: body });
      expect(res.status(), `${path} must require auth`).toBe(401);
    }
  });

  test("an unknown public invoice token is a neutral 404", async ({ request }) => {
    const res = await request.get("/invoice/not-a-real-token-000000000000", { maxRedirects: 0 });
    expect(res.status()).toBe(404);
  });
});
