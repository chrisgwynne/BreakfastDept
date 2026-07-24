const { test, expect } = require("@playwright/test");
const { execSync } = require("child_process");
const crypto = require("crypto");
const fs = require("fs");
const path = require("path");

// Stripe deposit payments — the full journey: configure Stripe, issue an invoice,
// create a payment link (server-computed amount), then settle it ONLY via a
// signature-verified webhook (never a redirect), producing a receipt and a paid
// invoice. Proves an invalid signature is rejected and never processed.
// Desktop project (the money path is covered project-agnostically by PaymentsTest).

const ADMIN = { email: "admin@test.local", password: "test-admin-pw-1234" };
const WEBHOOK_SECRET = "whsec_pw_test_secret";

function signedHeader(secret, payload) {
  const ts = Math.floor(Date.now() / 1000);
  const sig = crypto.createHmac("sha256", secret).update(`${ts}.${payload}`).digest("hex");
  return `t=${ts},v1=${sig}`;
}

test.describe("Standalone admin — deposit payments", () => {
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

  test("configure Stripe → issue invoice → payment link → verified webhook → paid + receipt", async ({ page, context, baseURL }, testInfo) => {
    test.skip(testInfo.project.name === "mobile", "desktop-only multi-step journey");
    await login(page);

    // 1. Configure Stripe through the real settings UI: secret key, webhook
    //    signing secret, base URL, then enable + save. In this browser run the
    //    gateway calls are served by an in-process fake (BF_STRIPE_MOCK=1) so no
    //    live network is used; the base-URL field is still exercised for persistence.
    await page.goto("/breakfast-admin/settings");
    await page.locator('[data-test="stripe-add-key"]').click();
    await page.locator('[data-test="stripe-secret-input"]').fill("sk_test_pw_mock");
    await Promise.all([
      page.waitForResponse((r) => r.url().endsWith("/settings/stripe/key") && r.request().method() === "POST"),
      page.locator('[data-test="stripe-secret-save"]').click(),
    ]);
    // Webhook secret via the API (shares the session + CSRF the SPA already holds).
    const csrf = await page.evaluate(() => fetch("/breakfast-admin/api/v1/session/csrf").then((r) => r.json()).then((j) => j.data.csrf));
    const whRes = await context.request.post("/breakfast-admin/api/v1/settings/stripe/webhook-secret", {
      headers: { "X-CSRF-Token": csrf, "Content-Type": "application/json" },
      data: JSON.stringify({ webhook_secret: WEBHOOK_SECRET }),
    });
    expect(whRes.status()).toBe(200);
    // Base URL → local mock, enabled on, then save config.
    await page.locator('[data-test="stripe-base-url"]').fill(`${baseURL}/stripe-mock/v1`);
    await page.locator('[data-test="stripe-enabled"]').check();
    await Promise.all([
      page.waitForResponse((r) => r.url().endsWith("/settings/stripe") && r.request().method() === "PUT"),
      page.locator('[data-test="stripe-save-config"]').click(),
    ]);

    // 2. Create + issue an invoice for £600.
    await page.goto("/breakfast-admin/invoices");
    await page.getByRole("button", { name: "New invoice" }).first().click();
    await page.locator(".sheet").getByPlaceholder("Client or company").fill("Deposit Client Ltd");
    await page.locator(".sheet").getByPlaceholder("Description").first().fill("Project deposit");
    await page.locator(".sheet").getByPlaceholder("Unit £").first().fill("600");
    await page.locator(".sheet").getByPlaceholder("Qty").first().fill("1");
    const [createRes] = await Promise.all([
      page.waitForResponse((r) => r.url().endsWith("/api/v1/invoices") && r.request().method() === "POST"),
      page.locator(".sheet").getByRole("button", { name: "Save draft" }).click(),
    ]);
    const invoiceId = (await createRes.json()).data.invoice.id;
    // The detail sheet opens on the new draft; issue it.
    await Promise.all([
      page.waitForResponse((r) => new RegExp(`/invoices/${invoiceId}/issue$`).test(r.url())),
      page.locator(".sheet").getByRole("button", { name: "Issue", exact: true }).click(),
    ]);

    // 3. Create a Stripe payment link. The amount is computed server-side.
    const [linkRes] = await Promise.all([
      page.waitForResponse((r) => new RegExp(`/invoices/${invoiceId}/payment-link$`).test(r.url()) && r.request().method() === "POST"),
      page.locator('[data-test="create-payment-link"]').click(),
    ]);
    expect(linkRes.status(), "payment link must be created").toBe(200);
    const link = (await linkRes.json()).data.link;
    expect(link.url).toContain("/stripe-mock/hosted/");

    // A pending online payment now exists; the invoice is NOT yet paid.
    const before = await page.evaluate((id) => fetch(`/breakfast-admin/api/v1/invoices/${id}/payments`).then((r) => r.json()), invoiceId);
    expect(before.data.items.length).toBe(1);
    expect(before.data.items[0].status).toBe("pending");
    const paymentUuid = before.data.items[0].id;
    const invBefore = await page.evaluate((id) => fetch(`/breakfast-admin/api/v1/invoices/${id}`).then((r) => r.json()), invoiceId);
    expect(invBefore.data.invoice.status).not.toBe("paid");

    // 4. A webhook with a BAD signature must be rejected and never processed.
    const event = JSON.stringify({
      id: "evt_pw_" + Date.now(),
      type: "checkout.session.completed",
      data: { object: {
        id: "cs_mock_pw", object: "checkout.session", payment_status: "paid",
        amount_total: 60000, currency: "gbp", payment_intent: "pi_mock_pw",
        metadata: { invoice_uuid: invoiceId, payment_uuid: paymentUuid },
      } },
    });
    const bad = await context.request.post("/webhooks/stripe", {
      headers: { "Stripe-Signature": "t=1,v1=deadbeef", "Content-Type": "application/json" },
      data: event,
    });
    expect(bad.status(), "an unsigned/invalid webhook must be rejected").toBe(400);
    const stillPending = await page.evaluate((id) => fetch(`/breakfast-admin/api/v1/invoices/${id}`).then((r) => r.json()), invoiceId);
    expect(stillPending.data.invoice.status).not.toBe("paid");

    // 5. The correctly SIGNED webhook settles the invoice.
    const good = await context.request.post("/webhooks/stripe", {
      headers: { "Stripe-Signature": signedHeader(WEBHOOK_SECRET, event), "Content-Type": "application/json" },
      data: event,
    });
    expect(good.status(), "a verified webhook must be accepted").toBe(200);

    // 6. The invoice is now paid, driven by the verified payment (not a redirect).
    const after = await page.evaluate((id) => fetch(`/breakfast-admin/api/v1/invoices/${id}`).then((r) => r.json()), invoiceId);
    expect(after.data.invoice.status).toBe("paid");
    expect(after.data.invoice.amount_paid).toBe(600);

    // 7. A receipt PDF was generated and downloads as a real binary.
    const pays = await page.evaluate((id) => fetch(`/breakfast-admin/api/v1/invoices/${id}/payments`).then((r) => r.json()), invoiceId);
    expect(pays.data.items[0].status).toBe("succeeded");
    expect(pays.data.items[0].receipt_number).toBeTruthy();
    const receipt = await context.request.get(`/breakfast-admin/payments/${paymentUuid}/receipt`);
    expect(receipt.status()).toBe(200);
    expect((await receipt.body()).slice(0, 5).toString("latin1")).toBe("%PDF-");

    // 8. Re-delivering the SAME event is an idempotent no-op (no double payment).
    const dup = await context.request.post("/webhooks/stripe", {
      headers: { "Stripe-Signature": signedHeader(WEBHOOK_SECRET, event), "Content-Type": "application/json" },
      data: event,
    });
    expect(dup.status()).toBe(200);
    const afterDup = await page.evaluate((id) => fetch(`/breakfast-admin/api/v1/invoices/${id}`).then((r) => r.json()), invoiceId);
    expect(afterDup.data.invoice.amount_paid).toBe(600);
  });

  test("payment endpoints reject unauthenticated callers", async ({ request }) => {
    const list = await request.get("/breakfast-admin/api/v1/payments");
    expect(list.status()).toBe(401);
    const stripe = await request.get("/breakfast-admin/api/v1/settings/stripe");
    expect(stripe.status()).toBe(401);
  });

  test("the Stripe webhook rejects a payload with no signature", async ({ request }) => {
    const res = await request.post("/webhooks/stripe", {
      headers: { "Content-Type": "application/json" },
      data: JSON.stringify({ id: "evt_x", type: "checkout.session.completed" }),
    });
    expect(res.status()).toBe(400);
  });
});
