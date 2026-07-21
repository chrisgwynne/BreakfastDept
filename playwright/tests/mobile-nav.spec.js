const { test, expect } = require("@playwright/test");

test.use({ viewport: { width: 375, height: 720 } });

test("mobile menu toggles and no horizontal overflow", async ({ page }) => {
  await page.goto("/");
  const toggle = page.locator("[data-nav-toggle]");
  await expect(toggle).toBeVisible();
  await toggle.click();
  await expect(page.locator("[data-nav]")).toHaveAttribute("data-open", "true");
  // Escape closes.
  await page.keyboard.press("Escape");
  await expect(page.locator("[data-nav]")).toHaveAttribute("data-open", "false");

  // No horizontal overflow.
  const overflow = await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1);
  expect(overflow).toBeTruthy();
});
