const { test, expect } = require("@playwright/test");

test("homepage loads with the P100 index and primary CTA", async ({ page }) => {
  await page.goto("/");
  await expect(page.locator("#home-heading")).toBeAttached();
  // The in-page numbered index is the primary navigation (Teletext model).
  await expect(page.locator(".tt-p100__directory a", { hasText: "START A PROJECT" })).toBeVisible();
});

test("primary navigation links work", async ({ page }) => {
  await page.goto("/");
  await page.locator(".tt-p100__directory a", { hasText: "SERVICES" }).click();
  await expect(page).toHaveURL(/\/services$/);
  await expect(page.locator("h1")).toBeVisible();
});

test("404 page returns friendly error", async ({ page }) => {
  const res = await page.goto("/no-such-page-here");
  expect(res.status()).toBe(404);
  await expect(page.locator("h1")).toBeVisible();
});

test("security headers present", async ({ page }) => {
  const res = await page.goto("/");
  const csp = res.headers()["content-security-policy"];
  expect(csp).toContain("default-src 'self'");
  expect(csp).not.toContain("unsafe-eval");
});
