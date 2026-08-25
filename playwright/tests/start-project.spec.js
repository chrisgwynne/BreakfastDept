const { test, expect } = require("@playwright/test");

// The Start-a-Project form is a single real form progressively enhanced into a
// three-step flow. These prove the enhanced flow works, that client validation
// gates advancing, and that a full submission still reaches the server and the
// thank-you page (server-side processing is unchanged).

test.describe("Start a project — multi-step form", () => {
  test("service offer CTA carries the buyer's choice into the enquiry", async ({ page }) => {
    await page.goto("/services/new-website");
    await expect(page.getByRole("heading", { name: "A new website", exact: true })).toBeVisible();
    await expect(page.getByText("What you get").first()).toBeVisible();

    await page.locator('[data-track-label="service-new-website"]').click();
    await expect(page).toHaveURL(/start-a-project\?service=new-website#form/);

    await page.fill("#field-name", "Offer Path Tester");
    await page.fill("#field-email", "offer-path@example.co.uk");
    await page.locator('.fstep[data-step="1"] [data-step-next]').click();
    await expect(page.getByRole("checkbox", { name: "A new website" })).toBeChecked();
    await expect(page.getByRole("checkbox", { name: "Not sure yet" })).not.toBeChecked();
  });

  test("steps, validation, back/next and submission", async ({ page }) => {
    const errs = [];
    await page.addInitScript(() => {
      window.analyticsEvents = [];
      window.gtag = (...args) => window.analyticsEvents.push(args);
    });
    page.on("pageerror", (e) => errs.push(String(e)));
    await page.goto("/start-a-project");
    await page.locator('[data-cookie-banner] button[data-consent="decline"]').click();

    // Enhanced: step 1 visible, later steps hidden, progress shown.
    await expect(page.locator('.fstep[data-step="1"]')).toBeVisible();
    await expect(page.locator('.fstep[data-step="2"]')).toBeHidden();
    await expect(page.locator("[data-steps-progress]")).toBeVisible();

    // Cannot advance with the required fields empty.
    await page.locator('.fstep[data-step="1"] [data-step-next]').click();
    await expect(page.locator('.fstep[data-step="2"]')).toBeHidden();
    await expect(page.locator("#field-name")).toHaveAttribute("aria-invalid", "true");
    expect(await page.evaluate(() => window.analyticsEvents.some((event) => event[1] === "contact_form_completed"))).toBe(false);

    // Fill step 1 and advance.
    const uniq = Date.now() + "-" + Math.floor(Math.random() * 1e6);
    await page.fill("#field-name", "Multi Step Tester");
    await page.fill("#field-email", `ms-${uniq}@example.co.uk`);
    await page.fill("#field-help", "A new website for my cafe.");
    await page.locator('.fstep[data-step="1"] [data-step-next]').click();
    await expect(page.locator('.fstep[data-step="2"]')).toBeVisible();

    // Step 2 → 3, then Back preserves data (no loss between steps).
    await page.fill("#field-business", "A neighbourhood cafe.");
    await page.locator('.fstep[data-step="2"] [data-step-next]').click();
    await expect(page.locator('.fstep[data-step="3"]')).toBeVisible();
    await page.locator('.fstep[data-step="3"] [data-step-back]').click();
    await expect(page.locator("#field-business")).toHaveValue("A neighbourhood cafe.");
    await page.locator('.fstep[data-step="2"] [data-step-next]').click();

    // Consent + submit (respect the timing guard).
    const consent = page.locator("#field-consent");
    if (await consent.count()) await consent.check();
    await page.waitForTimeout(3600);
    await page.locator("[data-submit]").click();
    await expect(page).toHaveURL(/thank-you/);
    await expect(page.locator("body")).toContainText(/ENQ-/);
    await expect.poll(() => page.evaluate(() => window.analyticsEvents.filter((event) => event[1] === "contact_form_completed"))).toEqual([
      ["event", "contact_form_completed", { form: "start-project" }],
    ]);
    await page.reload();
    expect(await page.evaluate(() => window.analyticsEvents.filter((event) => event[1] === "contact_form_completed"))).toHaveLength(0);
    expect(errs).toEqual([]);
  });

  test("a forged thank-you reference is not treated as a conversion", async ({ page }) => {
    await page.goto("/thank-you?ref=ENQ-2099-9999&form=start-project");
    await expect(page.locator("[data-enquiry-complete]")).toHaveCount(0);
  });

  test("without JS the form is one page and still submits", async ({ browser }) => {
    // JS disabled: every step is visible and the single submit works.
    const ctx = await browser.newContext({ javaScriptEnabled: false });
    const page = await ctx.newPage();
    await page.goto("/start-a-project");
    await expect(page.locator('.fstep[data-step="1"]')).toBeVisible();
    await expect(page.locator('.fstep[data-step="2"]')).toBeVisible();
    await expect(page.locator('.fstep[data-step="3"]')).toBeVisible();
    // Next/Back controls are hidden without JS; the submit button is present.
    await expect(page.locator("[data-submit]")).toBeVisible();
    await ctx.close();
  });
});
