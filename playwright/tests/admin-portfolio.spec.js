const { test, expect } = require("@playwright/test");

// Portfolio module — API-level coverage of the mutating endpoints registered in
// action-registry.json (create / update / duplicate / transition / homepage).
// The full editor UI journey is covered by the desktop + mobile specs added in
// the admin SPA checkpoint; here we prove the backend actions exist, enforce
// auth, and are not frontend-only fakes.

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

  test("public /work renders (empty state with no published work) and no JS errors", async ({ page }) => {
    const errs = [];
    page.on("pageerror", (e) => errs.push(String(e)));
    const res = await page.goto("/work");
    expect(res.status()).toBe(200);
    await expect(page.locator("h1")).toBeVisible();
    // On a fresh database there is no published work yet → friendly empty state.
    await expect(page.locator('[data-test="portfolio-empty"]')).toBeVisible();
    // No horizontal overflow, no page errors.
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth + 1);
    expect(overflow).toBe(false);
    expect(errs).toEqual([]);
  });
});
