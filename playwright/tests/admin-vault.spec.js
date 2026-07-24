const { test, expect } = require("@playwright/test");
const { execSync } = require("child_process");
const fs = require("fs");
const path = require("path");

// Secure credential vault. Proves creating a credential through the UI, that the
// list/detail responses are masked (never the secret), that reveal is rejected
// without step-up re-authentication, and that after re-auth (verifying the real
// password) the secret can be revealed. Desktop-only (encryption at rest, key
// rotation, audit and search/Hermes exclusion are covered by VaultTest).

const ADMIN = { email: "admin@test.local", password: "test-admin-pw-1234" };
const SECRET = "PW-VAULT-CANARY-4821";

test.describe("Standalone admin — credential vault", () => {
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

  test("create → masked → reveal needs re-auth → reveal after re-auth", async ({ page }, testInfo) => {
    test.skip(testInfo.project.name === "mobile", "desktop-only multi-step journey");
    await login(page);

    // Create a credential with a secret through the UI.
    await page.goto("/breakfast-admin/vault");
    await page.locator('[data-test="vault-new"]').click();
    const label = "Hosting " + Date.now();
    await page.locator('[data-test="vault-label"]').fill(label);
    await page.locator('[data-test="vault-secret"]').fill(SECRET);
    const [createRes] = await Promise.all([
      page.waitForResponse((r) => r.url().endsWith("/api/v1/vault") && r.request().method() === "POST"),
      page.locator('[data-test="vault-save"]').click(),
    ]);
    expect(createRes.status()).toBe(200);
    // The create response is masked — the secret never comes back.
    expect(JSON.stringify(await createRes.json())).not.toContain(SECRET);

    // The list response is masked too.
    const listBody = await page.evaluate(() => fetch("/breakfast-admin/api/v1/vault").then((r) => r.text()));
    expect(listBody).not.toContain(SECRET);

    // Open the item; reveal must be rejected without re-auth.
    await page.getByText(label).first().click();
    const itemId = await page.evaluate(async (l) => {
      const list = await fetch("/breakfast-admin/api/v1/vault").then((r) => r.json());
      return list.data.items.find((i) => i.label === l).id;
    }, label);
    const csrf = await page.evaluate(() => fetch("/breakfast-admin/api/v1/session/csrf").then((r) => r.json()).then((j) => j.data.csrf));
    const denied = await page.evaluate(async (d) => {
      const r = await fetch("/breakfast-admin/api/v1/vault/" + d.itemId + "/reveal", { method: "POST", headers: { "Content-Type": "application/json", "X-CSRF-Token": d.csrf }, body: JSON.stringify({ fkey: "password" }) });
      return r.status;
    }, { itemId, csrf });
    expect(denied, "reveal without re-auth must be 401").toBe(401);

    // Re-authenticate with the real password, then reveal returns the plaintext.
    const revealed = await page.evaluate(async (d) => {
      const ra = await fetch("/breakfast-admin/api/v1/vault/reauth", { method: "POST", headers: { "Content-Type": "application/json", "X-CSRF-Token": d.csrf }, body: JSON.stringify({ password: d.password }) });
      if (!ra.ok) return { reauth: ra.status };
      const rv = await fetch("/breakfast-admin/api/v1/vault/" + d.itemId + "/reveal", { method: "POST", headers: { "Content-Type": "application/json", "X-CSRF-Token": d.csrf }, body: JSON.stringify({ fkey: "password" }) }).then((r) => r.json());
      return { value: rv.data.value };
    }, { itemId, csrf, password: ADMIN.password });
    expect(revealed.value).toBe(SECRET);

    // A wrong password is rejected.
    const badReauth = await page.evaluate(async (csrf) => {
      const r = await fetch("/breakfast-admin/api/v1/vault/reauth", { method: "POST", headers: { "Content-Type": "application/json", "X-CSRF-Token": csrf }, body: JSON.stringify({ password: "wrong" }) });
      return r.status;
    }, csrf);
    expect(badReauth).toBe(401);
  });

  test("vault endpoints reject unauthenticated callers", async ({ request }) => {
    const list = await request.get("/breakfast-admin/api/v1/vault");
    expect(list.status()).toBe(401);
    const reveal = await request.post("/breakfast-admin/api/v1/vault/x/reveal", { data: { fkey: "password" } });
    expect(reveal.status()).toBe(401);
  });
});
