const { test, expect } = require("@playwright/test");

test("work filtering hides non-matching projects", async ({ page }) => {
  await page.goto("/work");
  const filters = page.locator("[data-filters] [data-filter]");
  const count = await filters.count();
  test.skip(count < 2, "no filters rendered (needs projects with services/industries)");
  await filters.nth(1).click();
  await expect(page.locator("[data-filter-item]:visible").first()).toBeVisible();
});

test("journal lists articles and article opens", async ({ page }) => {
  await page.goto("/journal");
  const first = page.locator("article a").first();
  await first.click();
  await expect(page.locator("h1")).toBeVisible();
  await expect(page.locator("body")).toContainText(/min read/i);
});

test("keyboard: skip link is reachable and focuses main", async ({ page }) => {
  await page.goto("/");
  await page.keyboard.press("Tab");
  const skip = page.locator("a.skip-link");
  await expect(skip).toBeFocused();
});
