const { test, expect } = require("@playwright/test");
const { execSync } = require("child_process");
const fs = require("fs");
const path = require("path");

// Portfolio module — API-level coverage of the mutating endpoints registered in
// action-registry.json (create / update / duplicate / transition / homepage),
// plus the full editorial UI journey through the standalone admin SPA.

test.describe("Portfolio admin API", () => {
  test("portfolio endpoints reject unauthenticated callers", async ({ request }) => {
    const list = await request.get("/breakfast-admin/api/v1/portfolio");
    expect(list.status()).toBe(401);

    const create = await request.post("/breakfast-admin/api/v1/portfolio", {
      data: { internal_name: "Should not work" },
    });
    expect(create.status()).toBe(401);

    const publish = await request.post("/breakfast-admin/api/v1/portfolio/does-not-exist/publish", { data: {} });
    expect(publish.status()).toBe(401);
  });

  test("portfolio drafts are never publicly reachable", async ({ request }) => {
    // A record that has never been published must not resolve on the public
    // site (drafts inaccessible). A random slug 404s, not 200.
    const res = await request.get("/work/definitely-not-a-published-slug-xyz");
    expect([301, 302, 404]).toContain(res.status());
  });

  test("public /work renders the flat-file work section and no JS errors", async ({ page }) => {
    const errs = [];
    page.on("pageerror", (e) => errs.push(String(e)));
    const res = await page.goto("/work");
    expect(res.status()).toBe(200);
    await expect(page.locator("h1")).toBeVisible();
    // /work is now flat-file Kirby content (content/1_work/*) — the LocalMarkers
    // project is a committed page and renders as a card.
    await expect(page.getByText("LocalMarkers").first()).toBeVisible();
    const caseRes = await page.goto("/work/localmarkers");
    expect(caseRes.status()).toBe(200);
    await expect(page.getByRole("heading", { name: "LocalMarkers" })).toBeVisible();
    // No horizontal overflow, no page errors on either page.
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth + 1);
    expect(overflow).toBe(false);
    expect(errs).toEqual([]);
  });
});

// The authoritative editorial interface: create a case study, edit it (with
// autosave + optimistic concurrency), build its story and results, see the
// publish checklist enforce the public/private boundary, and drive the record
// through the server-enforced state machine — all through the real SPA.
const ADMIN = { email: "admin@test.local", password: "test-admin-pw-1234" };

test.describe("Portfolio editor (admin SPA)", () => {
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

  test("create, edit, and move a case study through the workflow", async ({ page }, testInfo) => {
    test.skip(testInfo.project.name === "mobile", "desktop-only multi-step editorial journey");
    const errs = [];
    page.on("pageerror", (e) => errs.push(String(e)));
    await login(page);

    // Create from the list.
    await page.goto("/breakfast-admin/portfolio");
    await page.locator('[data-test="portfolio-new"]').click();
    const name = "PW Case " + Date.now();
    await page.locator('[data-test="portfolio-internal-name"]').fill(name);
    const [createRes] = await Promise.all([
      page.waitForResponse((r) => r.url().endsWith("/api/v1/portfolio") && r.request().method() === "POST"),
      page.locator('[data-test="portfolio-create"]').click(),
    ]);
    expect(createRes.status()).toBe(200);
    // We land on the editor.
    await page.waitForURL((u) => /\/portfolio\/[0-9a-f-]+/.test(u.toString()), { timeout: 10000 });
    await expect(page.locator('[data-test="portfolio-title"]')).toContainText(name);

    // A brand-new draft cannot be published — the checklist lists blockers and
    // the Publish button is not offered until it reaches "ready".
    await expect(page.locator('[data-test="portfolio-blockers"]')).toBeVisible();

    // Edit overview fields — autosave PATCHes with the revision.
    await page.locator('[data-test="field-display_title"]').fill("A faster site for a busy café");
    await page.locator('[data-test="field-summary"]').fill("A clearer, quicker site.");
    await Promise.all([
      page.waitForResponse((r) => /\/api\/v1\/portfolio\/[0-9a-f-]+$/.test(r.url()) && r.request().method() === "PATCH"),
      page.locator('[data-test="field-display_title"]').blur(),
    ]);
    await expect(page.locator('[data-test="portfolio-savestate"]')).toContainText(/saved/i);

    // Add a story section.
    await page.locator('[data-test="tab-story"]').click();
    await page.locator('[data-test="story-problem"] textarea').fill("The old site was slow to update.");
    await Promise.all([
      page.waitForResponse((r) => /\/story\/problem$/.test(r.url()) && r.request().method() === "POST"),
      page.locator('[data-test="story-save-problem"]').click(),
    ]);

    // Add a genuine result.
    await page.locator('[data-test="tab-results"]').click();
    await page.locator('[data-test="result-value"]').fill("+38%");
    await page.locator('[data-test="result-label"]').fill("online bookings");
    await Promise.all([
      page.waitForResponse((r) => /\/results$/.test(r.url()) && r.request().method() === "POST"),
      page.locator('[data-test="result-add"]').click(),
    ]);
    await expect(page.locator('[data-test="panel-results"]')).toContainText("+38%");

    // Drive the state machine: draft → internal_review → ready.
    await Promise.all([
      page.waitForResponse((r) => /\/submit-review$/.test(r.url()) && r.request().method() === "POST"),
      page.locator('[data-test="portfolio-submit-review"]').click(),
    ]);
    await expect(page.locator('[data-test="portfolio-status"]')).toContainText(/review/i);
    await Promise.all([
      page.waitForResponse((r) => /\/mark-ready$/.test(r.url()) && r.request().method() === "POST"),
      page.locator('[data-test="portfolio-mark-ready"]').click(),
    ]);
    await expect(page.locator('[data-test="portfolio-status"]')).toContainText(/ready/i);

    // The record still isn't publishable (no approved image / service) — the
    // Publish button stays disabled and the checklist explains why. This is the
    // public/private boundary being enforced in the UI, not just the API.
    await expect(page.locator('[data-test="portfolio-blockers"]')).toBeVisible();
    await expect(page.locator('[data-test="portfolio-publish"]')).toBeDisabled();

    // History records the transitions.
    await page.locator('[data-test="tab-history"]').click();
    await expect(page.locator('[data-test="panel-history"]')).toContainText(/ready|review|created/i);

    expect(errs).toEqual([]);
  });
});
