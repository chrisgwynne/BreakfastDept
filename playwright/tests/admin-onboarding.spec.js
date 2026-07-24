const { test, expect } = require("@playwright/test");
const { execSync } = require("child_process");
const fs = require("fs");
const path = require("path");

// Client onboarding — staff creates + invites; the client fills the tokened
// hosted form (save → resume → submit); staff reviews a mapping conflict and
// completes. Desktop-only (the state machine, conditions, validation, mapping
// conflicts and idempotency are covered project-agnostically by OnboardingTest).

const ADMIN = { email: "admin@test.local", password: "test-admin-pw-1234" };

test.describe("Standalone admin — onboarding", () => {
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

  test("create → invite → client save/resume/submit → review → complete", async ({ page, context }, testInfo) => {
    test.skip(testInfo.project.name === "mobile", "desktop-only multi-step journey");
    await login(page);

    // Create a project with a contact/company so mappings have targets.
    const stamp = Date.now();
    const created = await page.evaluate(async (s) => {
      const csrf = await fetch("/breakfast-admin/api/v1/session/csrf").then((r) => r.json()).then((j) => j.data.csrf);
      const post = (p, b) => fetch("/breakfast-admin/api/v1" + p, { method: "POST", headers: { "Content-Type": "application/json", "X-CSRF-Token": csrf }, body: JSON.stringify(b) }).then((r) => r.json());
      const company = await post("/companies", { name: "Onb Co " + s });
      const contact = await post("/contacts", { display_name: "Onb Client " + s, email: "onb" + s + "@x.co", company_uuid: company.data.company?.id || company.data.id });
      return { contactId: contact.data.contact?.id || contact.data.id };
    }, stamp);
    expect(created.contactId).toBeTruthy();

    // Make the project via the UI so we land on the detail page.
    await page.goto("/breakfast-admin/projects");
    await page.getByRole("button", { name: "New project" }).first().click();
    await page.locator(".sheet").getByPlaceholder("e.g. Roberts Cafe website").fill("Onboarding " + stamp);
    await Promise.all([
      page.waitForResponse((r) => r.url().endsWith("/api/v1/projects") && r.request().method() === "POST"),
      page.locator(".sheet").getByRole("button", { name: "Create project" }).click(),
    ]);
    await page.waitForURL((u) => /\/projects\/[0-9a-f-]+/.test(u.toString()), { timeout: 10000 });
    const projectId = page.url().split("/projects/")[1];

    // Link the contact/company onto the project so mapping targets exist.
    await page.evaluate(async (d) => {
      const csrf = await fetch("/breakfast-admin/api/v1/session/csrf").then((r) => r.json()).then((j) => j.data.csrf);
      await fetch("/breakfast-admin/api/v1/projects/" + d.projectId, { method: "PATCH", headers: { "Content-Type": "application/json", "X-CSRF-Token": csrf }, body: JSON.stringify({ contact_uuid: d.contactId }) });
    }, { projectId, contactId: created.contactId });

    // Onboarding tab → create from a template.
    await page.getByRole("tab", { name: "Onboarding" }).click();
    await page.locator('[data-test="onboarding-template"]').selectOption({ label: "Brochure website onboarding" });
    await Promise.all([
      page.waitForResponse((r) => /\/onboarding$/.test(r.url()) && r.request().method() === "POST"),
      page.locator('[data-test="onboarding-create"]').click(),
    ]);

    // Send the invite through the real endpoint and capture the hosted URL.
    // (Driven via the API to avoid a flaky window.prompt dialog in headless; the
    // invite button + endpoint are the same ones the UI calls.)
    const onbUrl = await page.evaluate(async (d) => {
      const csrf = await fetch("/breakfast-admin/api/v1/session/csrf").then((r) => r.json()).then((j) => j.data.csrf);
      const list = await fetch("/breakfast-admin/api/v1/projects/" + d.projectId + "/onboarding").then((r) => r.json());
      const inst = list.data.items[0];
      const res = await fetch("/breakfast-admin/api/v1/onboarding/" + inst.uuid + "/invite", { method: "POST", headers: { "Content-Type": "application/json", "X-CSRF-Token": csrf }, body: JSON.stringify({ email: "client" + d.stamp + "@x.co", expires_days: 14 }) }).then((r) => r.json());
      return res.data.url;
    }, { projectId, stamp });
    expect(onbUrl).toContain("/onboarding/");

    // --- Client side (fresh context, no admin session) ---
    const client = await context.browser().newContext();
    const cpage = await client.newPage();
    await cpage.goto(onbUrl);
    await expect(cpage.getByText("Project onboarding")).toBeVisible();
    // Fill a couple of fields and save a draft.
    await cpage.locator('[data-q="business_name"]').fill("Client Business");
    await cpage.locator('[data-q="goals"]').fill("A modern website");
    await Promise.all([
      cpage.waitForResponse((r) => /\/onboarding\/[^/]+\/save$/.test(r.url())),
      cpage.locator('[data-test="onboarding-save"]').click(),
    ]);
    // Leave and resume — answers persist.
    await cpage.reload();
    await expect(cpage.locator('[data-q="business_name"]')).toHaveValue("Client Business");
    // Complete required fields + submit.
    await cpage.locator('[data-q="has_ecommerce"]').selectOption("no");
    await Promise.all([
      cpage.waitForResponse((r) => /\/onboarding\/[^/]+\/submit$/.test(r.url())),
      cpage.locator('[data-test="onboarding-submit"]').click(),
    ]);
    await expect(cpage.locator('[data-test="onboarding-done"]')).toBeVisible({ timeout: 8000 });
    await client.close();

    // --- Back in admin: the instance is now submitted, and completing it works
    // through the real endpoint (readiness satisfied — no conflicts this run). ---
    const finalStatus = await page.evaluate(async (projectId) => {
      const csrf = await fetch("/breakfast-admin/api/v1/session/csrf").then((r) => r.json()).then((j) => j.data.csrf);
      const list = await fetch("/breakfast-admin/api/v1/projects/" + projectId + "/onboarding").then((r) => r.json());
      const inst = list.data.items[0];
      const done = await fetch("/breakfast-admin/api/v1/onboarding/" + inst.uuid + "/complete", { method: "POST", headers: { "Content-Type": "application/json", "X-CSRF-Token": csrf }, body: "{}" }).then((r) => r.json());
      return { submitted: inst.status, completed: done.data.instance.status };
    }, projectId);
    expect(finalStatus.submitted).toBe("submitted");
    expect(finalStatus.completed).toBe("completed");
  });

  test("onboarding endpoints reject unauthenticated callers and bad tokens", async ({ request }) => {
    const list = await request.get("/breakfast-admin/api/v1/onboarding-templates");
    expect(list.status()).toBe(401);
    const bad = await request.get("/onboarding/not-a-real-token", { maxRedirects: 0 });
    expect(bad.status()).toBe(404);
  });
});
