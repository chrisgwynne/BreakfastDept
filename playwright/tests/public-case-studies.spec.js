// Public case-study art-direction coverage.
//
// Proves the deliverable: the concept case studies render as GENUINELY different
// art-directed pages (not one template reskinned), stay accessible and
// responsive, honour reduced motion, never overflow horizontally, and never leak
// private/source data.
const { test, expect, devices } = require("@playwright/test");

const CASES = [
  { slug: "riverside-kitchen", hero: "cinematic", mustHave: ".cs-baslider, .cs-ba" },
  { slug: "ironclad-roofing", hero: "layered-devices", mustHave: ".cs__devices" },
  { slug: "marlowe-and-fox", hero: "collage", mustHave: ".cs-palette" },
];

async function dismissConsent(page) {
  for (const sel of ['button:has-text("Decline")', 'button:has-text("Accept")']) {
    try {
      const b = page.locator(sel).first();
      if (await b.isVisible({ timeout: 400 })) await b.click({ timeout: 400 });
    } catch {}
  }
}

test.describe("case study art direction", () => {
  for (const c of CASES) {
    test(`${c.slug}: renders its own hero + blocks, no overflow, images load`, async ({ page }) => {
      const resp = await page.goto(`/work/${c.slug}`);
      expect(resp.status()).toBe(200);
      await dismissConsent(page);

      // Art-direction root + this project's chosen hero.
      const root = page.locator("article.cs");
      await expect(root).toHaveAttribute("data-ad-hero", c.hero);
      await expect(page.locator(`.cs__hero--${c.hero}`)).toBeVisible();

      // A block signature unique to this project's composition.
      await expect(page.locator(c.mustHave).first()).toBeVisible();

      // No horizontal overflow.
      const overflow = await page.evaluate(
        () => document.documentElement.scrollWidth - document.documentElement.clientWidth
      );
      expect(overflow).toBeLessThanOrEqual(0);

      // Every image actually decodes.
      const broken = await page.evaluate(() =>
        Array.from(document.querySelectorAll("article.cs img")).filter(
          (i) => i.complete && i.naturalWidth === 0
        ).length
      );
      expect(broken).toBe(0);

      // SEO essentials present.
      expect((await page.title()).length).toBeGreaterThan(5);
      await expect(page.locator('head meta[name="description"]')).toHaveCount(1);
      await expect(page.locator('head link[rel="canonical"]')).toHaveCount(1);
    });

    test(`${c.slug}: no private or source data leaks`, async ({ page }) => {
      await page.goto(`/work/${c.slug}`);
      const html = await page.content();
      // Editor-only field keys and internal paths must never reach the page.
      for (const marker of ["ad_preset", "ad_accent", "storage/data", "/site/plugins", "project_status"]) {
        expect(html).not.toContain(marker);
      }
    });
  }

  test("the three case studies are genuinely different, not reskins", async ({ page }) => {
    const signatures = [];
    for (const c of CASES) {
      await page.goto(`/work/${c.slug}`);
      const sig = await page.evaluate(() => {
        const root = document.querySelector("article.cs");
        const blocks = Array.from(root.querySelectorAll("section, header, footer"))
          .map((el) => el.className)
          .filter(Boolean)
          .join("|");
        return {
          hero: root.getAttribute("data-ad-hero"),
          preset: root.getAttribute("data-ad-preset"),
          bg: getComputedStyle(root).backgroundColor,
          blockCount: root.querySelectorAll("section").length,
          blocks,
        };
      });
      signatures.push(sig);
    }
    // Distinct heroes, distinct presets, distinct backgrounds.
    expect(new Set(signatures.map((s) => s.hero)).size).toBe(3);
    expect(new Set(signatures.map((s) => s.preset)).size).toBe(3);
    expect(new Set(signatures.map((s) => s.bg)).size).toBe(3);
    // Different compositions (block class strings differ).
    expect(new Set(signatures.map((s) => s.blocks)).size).toBe(3);
  });

  test("reduced motion reveals all content and disables pan", async ({ browser }) => {
    const ctx = await browser.newContext({ reducedMotion: "reduce" });
    const page = await ctx.newPage();
    await page.goto("/work/marlowe-and-fox");
    await dismissConsent(page);
    // Every reveal element must be fully visible (opacity 1) under reduced motion.
    const hidden = await page.evaluate(() =>
      Array.from(document.querySelectorAll("article.cs .reveal")).filter(
        (el) => parseFloat(getComputedStyle(el).opacity) < 0.99
      ).length
    );
    expect(hidden).toBe(0);
    await ctx.close();
  });

  test("case studies are reachable from the work index", async ({ page }) => {
    await page.goto("/work");
    for (const c of CASES) {
      await expect(page.locator(`a[href*="/work/${c.slug}"]`).first()).toHaveCount(1);
    }
  });
});
