const { test, expect } = require("@playwright/test");
const { execSync } = require("child_process");
const fs = require("fs");
const path = require("path");

// Contracts & e-signature — the full journey from an accepted proposal through
// client signature, Breakfast countersignature, signed PDF and evidence.
// Desktop project (the domain is covered project-agnostically by ContractsTest).

const ADMIN = { email: "admin@test.local", password: "test-admin-pw-1234" };

test.describe("Standalone admin — contracts lifecycle", () => {
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

  test("proposal → contract → sign → countersign → signed PDF", async ({ page, context }, testInfo) => {
    test.skip(testInfo.project.name === "mobile", "desktop-only multi-step journey");
    await login(page);

    // 1. Create + send + accept a proposal (sets up an accepted proposal).
    await page.goto("/breakfast-admin/proposals");
    await page.getByRole("button", { name: "New proposal" }).first().click();
    const title = "Contract Proposal " + Date.now();
    await page.getByPlaceholder("Website design & build").fill(title);
    await page.locator(".sheet .cf__row").first().locator("input").first().fill("Roberts Cafe");
    await page.locator(".sheet .cf__row").first().locator('input[type="email"]').fill("sian@roberts.example");
    await page.getByPlaceholder("Description").first().fill("Website build");
    await page.getByPlaceholder("Unit £").first().fill("3000");
    await page.getByPlaceholder("Qty").first().fill("1");
    await page.locator(".sheet .field", { hasText: "Deposit (£)" }).locator("input").fill("1800");
    await Promise.all([
      page.waitForResponse((r) => r.url().endsWith("/api/v1/proposals") && r.request().method() === "POST"),
      page.getByRole("button", { name: "Create proposal" }).click(),
    ]);
    await page.locator(".sheet").getByRole("button", { name: "Send", exact: true }).click();
    await expect(page.locator(".app-toast", { hasText: "Proposal sent" })).toBeVisible({ timeout: 8000 });

    // Accept via the public link so we have an accepted proposal.
    const pdata = await page.evaluate(() => fetch("/breakfast-admin/api/v1/proposals").then((r) => r.json()));
    const proposal = pdata.data.items.find((i) => i.title === title);
    await context.request.post(proposal.public_url + "/accept", { form: { name: "Sian Roberts", email: "sian@roberts.example" }, maxRedirects: 0 });

    // 2. Create a contract from the accepted proposal.
    await page.reload();
    await page.getByText(title).first().click();
    const [ctrRes] = await Promise.all([
      page.waitForResponse((r) => r.url().endsWith("/api/v1/contracts") && r.request().method() === "POST"),
      page.locator(".sheet").getByRole("button", { name: "Create contract" }).click(),
    ]);
    expect(ctrRes.status(), "contract create must succeed").toBe(200);
    const contract = (await ctrRes.json()).data.contract;

    // 3. Open the contract, send it for signature (real unsigned PDF).
    await page.goto("/breakfast-admin/contracts");
    await page.getByText(contract.title).first().click();
    const [sendRes] = await Promise.all([
      page.waitForResponse((r) => /\/api\/v1\/contracts\/[^/]+\/send$/.test(r.url()) && r.request().method() === "POST"),
      page.locator(".sheet").getByRole("button", { name: "Send for signature" }).click(),
    ]);
    expect(sendRes.status(), "send must succeed").toBe(200);
    await expect(page.locator(".app-toast", { hasText: "Contract sent" })).toBeVisible({ timeout: 8000 });

    // The unsigned PDF is a real binary.
    const cdata = await page.evaluate(() => fetch("/breakfast-admin/api/v1/contracts").then((r) => r.json()));
    const sent = cdata.data.items.find((i) => i.id === contract.id);
    expect(sent.public_url).toBeTruthy();
    const unsigned = await context.request.get(`/breakfast-admin/contracts/${contract.id}/unsigned/download`);
    expect(unsigned.status()).toBe(200);
    expect((await unsigned.body()).slice(0, 5).toString("latin1")).toBe("%PDF-");

    // 4. Client views (records viewed, not signed) then signs via the hosted flow.
    const view = await context.request.get(sent.public_url);
    expect(view.status()).toBe(200);
    expect(await view.text()).toContain("Sign this contract");

    const signRes = await context.request.post(sent.public_url + "/sign", {
      form: { name: "Sian Roberts", email: "sian@roberts.example", consent: "yes" },
      maxRedirects: 0,
    });
    expect([200, 302, 303].includes(signRes.status())).toBeTruthy();

    // 5. Back in admin: countersign as Breakfast → completed → signed PDF.
    await page.reload();
    await page.getByText(contract.title).first().click();
    const [csRes] = await Promise.all([
      page.waitForResponse((r) => /\/api\/v1\/contracts\/[^/]+\/sign-internal$/.test(r.url()) && r.request().method() === "POST"),
      page.locator(".sheet").getByRole("button", { name: "Countersign" }).click(),
    ]);
    expect(csRes.status(), "countersign must succeed").toBe(200);
    await expect(page.locator(".app-toast", { hasText: "Countersigned" })).toBeVisible({ timeout: 8000 });

    // 6. The signed PDF is a real binary; evidence is recorded.
    const signed = await context.request.get(`/breakfast-admin/contracts/${contract.id}/signed/download`);
    expect(signed.status()).toBe(200);
    const bytes = await signed.body();
    expect(bytes.slice(0, 5).toString("latin1")).toBe("%PDF-");
    expect(bytes.length).toBeGreaterThan(1000);

    const evidence = await page.evaluate((id) => fetch(`/breakfast-admin/api/v1/contracts/${id}/evidence`).then((r) => r.json()), contract.id);
    expect(evidence.data.evidence.length).toBe(2);
    expect(evidence.data.evidence[0].document_hash.length).toBe(64);
  });

  test("contract endpoints reject unauthenticated callers", async ({ request }) => {
    const list = await request.get("/breakfast-admin/api/v1/contracts");
    expect(list.status()).toBe(401);
    const create = await request.post("/breakfast-admin/api/v1/contracts", { data: { title: "x" } });
    expect(create.status()).toBe(401);
  });

  test("an unknown public contract token is a neutral 404", async ({ request }) => {
    const res = await request.get("/contract/not-a-real-token-000000", { maxRedirects: 0 });
    expect(res.status()).toBe(404);
  });
});
