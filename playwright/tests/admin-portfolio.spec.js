const { test, expect } = require("@playwright/test");

// Portfolio is flat-file Kirby content (content/1_work/*) — no database module,
// no admin API. These cover the public /work section and case-study pages.

test.describe("Public work (flat-file portfolio)", () => {
  test("/work renders the work section and a case study, with no JS errors", async ({ page }) => {
    const errs = [];
    page.on("pageerror", (e) => errs.push(String(e)));

    const res = await page.goto("/work");
    expect(res.status()).toBe(200);
    await expect(page.locator("h1")).toBeVisible();
    // The LocalMarkers project is a committed page and renders as a card.
    await expect(page.getByText("LocalMarkers").first()).toBeVisible();

    const caseRes = await page.goto("/work/localmarkers");
    expect(caseRes.status()).toBe(200);
    await expect(page.getByRole("heading", { name: "LocalMarkers" })).toBeVisible();

    // No horizontal overflow, no page errors.
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth + 1);
    expect(overflow).toBe(false);
    expect(errs).toEqual([]);
  });

  test("an unknown case-study slug 404s", async ({ request }) => {
    const res = await request.get("/work/definitely-not-a-real-project-xyz");
    expect(res.status()).toBe(404);
  });
});
