const { test, expect } = require("@playwright/test");

// Note: the concept-work portfolio is unpublished (drafts) until real client
// projects exist, so there is deliberately no public /work section.

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
