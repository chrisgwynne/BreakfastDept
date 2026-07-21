const { test, expect } = require("@playwright/test");

test("contact form full conversion path", async ({ page }) => {
  // Unique per run so duplicate-detection / rate-limiting across projects
  // never turns a genuine submission into a deduped one.
  const unique = Date.now() + "-" + Math.floor(Math.random() * 1e6);
  await page.goto("/contact");
  await page.fill("#field-name", "Playwright Tester");
  await page.fill("#field-email", `pw-${unique}@example.co.uk`);
  await page.fill("#field-message", `Automated but valid enquiry ${unique}, long enough to pass validation.`);
  // Consent checkbox if present.
  const consent = page.locator("#field-consent");
  if (await consent.count()) await consent.check();
  // The timing guard requires a few seconds since render.
  await page.waitForTimeout(3500);
  await page.click('button[type="submit"]');
  await expect(page).toHaveURL(/thank-you/);
  await expect(page.locator("body")).toContainText(/ENQ-/);
});

test("invalid submission shows field errors, no redirect", async ({ page }) => {
  await page.goto("/contact");
  await page.fill("#field-email", "not-an-email");
  await page.waitForTimeout(3500);
  await page.click('button[type="submit"]');
  await expect(page).toHaveURL(/contact/);
  await expect(page.locator(".form__error-summary, .field__error").first()).toBeVisible();
});
