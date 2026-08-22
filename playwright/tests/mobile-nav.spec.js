const { test, expect } = require("@playwright/test");

test.use({ viewport: { width: 375, height: 720 } });

// There is deliberately no hamburger/menu-bar on this site — navigation is
// the in-page numbered index, the soft-key strip, and typed page numbers
// (the actual Teletext navigation model). This covers that mobile surface.
test("mobile index navigation works with no horizontal overflow", async ({ page }) => {
  await page.goto("/");

  const overflow = await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1);
  expect(overflow).toBeTruthy();

  // The numbered index list is the primary mobile navigation.
  await expect(page.locator(".tt-p100__directory a", { hasText: "OUR WORK" })).toBeVisible();

  // Typed page-number navigation: "/" opens the go-to-page overlay.
  await page.keyboard.press("/");
  await expect(page.locator("[data-tt-goto]")).toHaveAttribute("data-open", "true");
  await page.keyboard.press("Escape");
  await expect(page.locator("[data-tt-goto]")).not.toHaveAttribute("data-open", "true");
});
