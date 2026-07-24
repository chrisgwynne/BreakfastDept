const { test, expect } = require("@playwright/test");
const { execSync } = require("child_process");
const fs = require("fs");
const path = require("path");

// Proposals — the commercial lifecycle entry point. Proves the whole vertical
// end to end: create → send (real PDF) → hosted client link → evidenced client
// acceptance → convert to won opportunity + deposit invoice. Desktop project
// (the domain is covered project-agnostically by the PHPUnit ProposalsTest).

const ADMIN = { email: "admin@test.local", password: "test-admin-pw-1234" };

test.describe("Standalone admin — proposals lifecycle", () => {
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

  test("create, send, client accepts, then convert", async ({ page, context }, testInfo) => {
    test.skip(testInfo.project.name === "mobile", "desktop-only multi-step journey");
    await login(page);

    await page.goto("/breakfast-admin/proposals");
    await page.getByRole("button", { name: "New proposal" }).first().click();

    const title = "E2E Proposal " + Date.now();
    await page.getByPlaceholder("Website design & build").fill(title);
    await page.locator(".sheet .cf__row").first().locator("input").first().fill("Roberts Cafe");
    await page.getByPlaceholder("Description").first().fill("Website design & build");
    await page.getByPlaceholder("Unit £").first().fill("3000");
    await page.getByPlaceholder("Qty").first().fill("1");
    await page.getByPlaceholder("VAT %").first().fill("20");
    await page.locator(".sheet .field", { hasText: "Deposit (£)" }).locator("input").fill("1800");

    const [createRes] = await Promise.all([
      page.waitForResponse((r) => r.url().endsWith("/api/v1/proposals") && r.request().method() === "POST"),
      page.getByRole("button", { name: "Create proposal" }).click(),
    ]);
    expect(createRes.status(), "create must persist server-side").toBe(200);
    await expect(page.locator(".app-toast", { hasText: "Proposal created" })).toBeVisible({ timeout: 8000 });

    // Send it — a real PDF is generated and a public link minted.
    const [sendRes] = await Promise.all([
      page.waitForResponse((r) => /\/api\/v1\/proposals\/[^/]+\/send$/.test(r.url()) && r.request().method() === "POST"),
      page.locator(".sheet").getByRole("button", { name: "Send", exact: true }).click(),
    ]);
    expect(sendRes.status(), "send must succeed server-side").toBe(200);
    await expect(page.locator(".app-toast", { hasText: "Proposal sent" })).toBeVisible({ timeout: 8000 });

    // The stored PDF is a genuine binary.
    const dl = page.locator(".sheet").getByRole("link", { name: /Download PDF/ });
    await expect(dl).toBeVisible({ timeout: 8000 });
    const pdf = await context.request.get(await dl.getAttribute("href"));
    expect(pdf.status()).toBe(200);
    expect((await pdf.body()).slice(0, 5).toString("latin1")).toBe("%PDF-");

    // Fetch the public client page (marks viewed), then accept as the client.
    const publicUrl = await page.evaluate(() => {
      return fetch("/breakfast-admin/api/v1/proposals").then((r) => r.json());
    });
    const proposal = publicUrl.data.items.find((i) => i.title === title);
    expect(proposal.public_url).toBeTruthy();

    const view = await context.request.get(proposal.public_url);
    expect(view.status()).toBe(200);
    expect(await view.text()).toContain("Accept proposal");

    // Evidenced acceptance via the public form endpoint.
    const acceptRes = await context.request.post(proposal.public_url + "/accept", {
      form: { name: "Sian Roberts", email: "sian@roberts.example" },
      maxRedirects: 0,
    });
    expect([200, 302, 303].includes(acceptRes.status())).toBeTruthy();

    // Back in admin: the proposal is accepted; convert it.
    await page.reload();
    await page.getByText(title).first().click();
    await expect(page.locator(".sheet")).toContainText("Sian Roberts", { timeout: 8000 });

    const [convRes] = await Promise.all([
      page.waitForResponse((r) => /\/api\/v1\/proposals\/[^/]+\/convert$/.test(r.url()) && r.request().method() === "POST"),
      page.locator(".sheet").getByRole("button", { name: /Convert/ }).click(),
    ]);
    expect(convRes.status(), "convert must succeed server-side").toBe(200);
    await expect(page.locator(".app-toast", { hasText: "Converted" })).toBeVisible({ timeout: 8000 });
  });

  test("proposal endpoints reject unauthenticated callers", async ({ request }) => {
    const list = await request.get("/breakfast-admin/api/v1/proposals");
    expect(list.status()).toBe(401);
    const create = await request.post("/breakfast-admin/api/v1/proposals", { data: { title: "x" } });
    expect(create.status()).toBe(401);
  });

  test("an unknown public proposal token is a neutral 404", async ({ request }) => {
    const res = await request.get("/proposal/not-a-real-token-000000", { maxRedirects: 0 });
    expect(res.status()).toBe(404);
  });
});
