const { test, expect } = require("@playwright/test");
const { execSync } = require("child_process");

// Breakfast Admin relocation + Client Previews origin behaviour. These run
// unauthenticated: they assert the public-facing guarantees (the old /panel is
// gone, the admin lives at its slug, previews serve with the security header set
// and are contained), which is what the definition of done requires to be proven
// in the browser.

test.describe("Breakfast Admin relocation", () => {
  test("old /panel and descendants return 404", async ({ request }) => {
    expect((await request.get("/panel")).status()).toBe(404);
    expect((await request.get("/panel/")).status()).toBe(404);
    expect((await request.get("/panel/users")).status()).toBe(404);
  });

  test("admin loads at the configured slug", async ({ request }) => {
    // 200 (installer/login shell) or 302 (redirect to login when an admin exists)
    // — both mean the admin responds at its slug; only a 404 would be a failure.
    const res = await request.get("/breakfast-admin", { maxRedirects: 0 });
    expect([200, 302]).toContain(res.status());
  });

  test("public pages carry no hard-coded /panel links", async ({ page }) => {
    await page.goto("/");
    expect(await page.locator('a[href$="/panel"], a[href*="/panel/"]').count()).toBe(0);
  });
});

test.describe("Client Previews origin", () => {
  test.beforeAll(() => {
    // Publish a demo preview into the same SQLite DB the test server uses.
    execSync("php bin/console previews:seed-demo demo", { cwd: process.cwd(), stdio: "inherit" });
  });

  test("a published preview loads over the dev path prefix", async ({ page }) => {
    await page.goto("/_preview/demo/");
    await expect(page.locator("#demo-preview")).toBeVisible();
  });

  test("preview responses carry the security header set", async ({ request }) => {
    const res = await request.get("/_preview/demo/");
    expect(res.status()).toBe(200);
    const h = res.headers();
    expect(h["x-content-type-options"]).toBe("nosniff");
    expect(h["x-robots-tag"]).toContain("noindex");
    expect(h["referrer-policy"]).toBe("no-referrer");
    expect(h["content-security-policy"]).toContain("object-src 'none'");
    expect(h["content-security-policy"]).toContain("worker-src 'none'");
    expect(h["content-security-policy"]).toContain("form-action 'self'");
  });

  test("preview responses enforce cross-origin isolation", async ({ request }) => {
    // These headers are what stop one preview origin reading another: no CORS
    // grant, cross-origin-resource/opener policies pinned to same-origin, and a
    // CSP that confines fetch/XHR to the preview's own origin. In production each
    // preview additionally gets its own {slug}.host origin (see the integration
    // suite) so the browser same-origin policy separates them outright.
    const h = (await request.get("/_preview/demo/")).headers();
    expect(h["access-control-allow-origin"]).toBeUndefined();
    expect(h["cross-origin-resource-policy"]).toBe("same-origin");
    expect(h["cross-origin-opener-policy"]).toBe("same-origin");
    expect(h["content-security-policy"]).toContain("connect-src 'self'");
    expect(h["content-security-policy"]).toContain("frame-ancestors 'self'");
  });

  test("traversal and unknown slugs are neutral 404s", async ({ request }) => {
    expect((await request.get("/_preview/demo/..%2f..%2fconfig.php")).status()).toBe(404);
    expect((await request.get("/_preview/no-such-preview/")).status()).toBe(404);
  });

  test("the main site is unaffected by the preview routes", async ({ request }) => {
    expect((await request.get("/")).status()).toBe(200);
  });
});
