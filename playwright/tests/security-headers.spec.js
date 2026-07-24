const { test, expect } = require("@playwright/test");

// Security-hardening regression (audit): the admin SPA shell and the standalone
// tokened client pages must ship a Content-Security-Policy with
// frame-ancestors 'none' (anti-clickjacking of sign/pay/approve flows) plus
// nosniff, and the client pages must not be cached and must not leak the
// capability token via Referer. These pages do NOT go through the public site
// layout, so the headers come from the app itself (independent of web server).

test.describe("Security headers", () => {
  test("admin SPA shell carries a strict CSP and frame protection", async ({ request }) => {
    const res = await request.get("/breakfast-admin");
    expect(res.status()).toBe(200);
    const csp = res.headers()["content-security-policy"] || "";
    expect(csp).toContain("frame-ancestors 'none'");
    expect(csp).toContain("object-src 'none'");
    expect(csp).toContain("base-uri 'self'");
    expect(res.headers()["x-frame-options"]).toBe("DENY");
    expect(res.headers()["x-content-type-options"]).toBe("nosniff");
    expect(res.headers()["cache-control"]).toContain("no-store");
  });

  test("tokened client page (portal) is CSP-protected, no-store, no-referrer", async ({ request }) => {
    const res = await request.get("/portal");
    expect(res.status()).toBe(200);
    const csp = res.headers()["content-security-policy"] || "";
    expect(csp).toContain("frame-ancestors 'none'");
    expect(csp).toContain("form-action 'self'");
    expect(res.headers()["x-frame-options"]).toBe("DENY");
    expect(res.headers()["referrer-policy"]).toBe("no-referrer");
    expect(res.headers()["cache-control"]).toContain("no-store");
    expect(res.headers()["x-robots-tag"]).toContain("noindex");
  });
});
