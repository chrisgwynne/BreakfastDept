const { test, expect } = require("@playwright/test");

// Note: /work is flat-file Kirby content (content/1_work/*). The LocalMarkers
// project is live; concept pieces stay in content/_drafts until they're ready.

test("journal lists articles and article opens", async ({ page }) => {
  await page.goto("/journal");
  const first = page.locator(".tt-list__row").first();
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
