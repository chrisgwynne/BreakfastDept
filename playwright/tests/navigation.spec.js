const { test, expect } = require("@playwright/test");

test("homepage loads with hero and primary CTA", async ({ page }) => {
  await page.goto("/");
  await expect(page.locator("h1.hero__title")).toBeVisible();
  // Scope to the hero (the nav CTA is intentionally hidden on mobile).
  await expect(page.locator(".hero__actions a.btn").first()).toBeVisible();
});

test("primary navigation links work", async ({ page }) => {
  await page.goto("/");
  await page.getByRole("link", { name: "Services", exact: true }).first().click();
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
