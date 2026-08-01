// Public case-study art-direction coverage (real portfolio pieces).
//
// Verifies the two real case studies render through the art-direction system:
// their own hero, accessible + responsive, reduced-motion honoured, no horizontal
// overflow, no private/source-data leaks.
const { test, expect } = require("@playwright/test");

const CASES = ["localmarkers", "thequirkygiftco"];

async function dismissConsent(page) {
  for (const sel of ['button:has-text("Decline")', 'button:has-text("Accept")']) {
    try {
      const b = page.locator(sel).first();
      if (await b.isVisible({ timeout: 400 })) await b.click({ timeout: 400 });
    } catch {}
  }
}

test.describe("case study art direction", () => {
  for (const slug of CASES) {
    test(`${slug}: renders through the art-direction system, no overflow, images load`, async ({ page }) => {
      const resp = await page.goto(`/work/${slug}`);
      expect(resp.status()).toBe(200);
      await dismissConsent(page);

      // Art-direction root + a resolved hero variant.
      const root = page.locator("article.cs");
      await expect(root).toHaveAttribute("data-ad-hero", /.+/);
      await expect(page.locator(".cs__hero")).toBeVisible();

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

    test(`${slug}: no private or source data leaks`, async ({ page }) => {
      await page.goto(`/work/${slug}`);
      const html = await page.content();
      for (const marker of ["ad_preset", "ad_accent", "approved_hosts", "storage/data", "/site/plugins", "preview_token_version"]) {
        expect(html).not.toContain(marker);
      }
    });
  }

  test("reduced motion reveals all content", async ({ browser }) => {
    const ctx = await browser.newContext({ reducedMotion: "reduce" });
    const page = await ctx.newPage();
    await page.goto("/work/thequirkygiftco");
    await dismissConsent(page);
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
    for (const slug of CASES) {
      await expect(page.locator(`a[href*="/work/${slug}"]`).first()).toHaveCount(1);
    }
  });

  // The acceptance test that the previous implementation was missing: prove the
  // two REAL published pages are genuinely different, from the rendered HTML —
  // not from configuration. This is what stops both pages collapsing back into
  // one template with different words.
  test("the two published pages are structurally different", async ({ page }) => {
    // Distinctive, non-leaking root markers — one selector per block type that
    // only that block emits (utility classes like .cs-text are deliberately
    // excluded because several blocks reuse them internally).
    const MARKERS = {
      composition: ".cs-comp",
      annotated: ".cs-annot__notes",
      facts: ".cs-facts",
      numbered: ".cs-numbered",
      stack: ".cs-stack",
      rotated: ".cs-rotated",
      fullbleed: ".cs-fullbleed",
      palette: ".cs-palette",
      specimen: ".cs-specimen",
      interlude: ".cs-interlude",
    };

    async function fingerprint(slug) {
      await page.goto(`/work/${slug}`);
      const root = page.locator("article.cs");
      const attr = async (n) => (await root.getAttribute(n)) || "";
      const present = {};
      for (const [k, sel] of Object.entries(MARKERS)) {
        present[k] = (await page.locator(`article.cs ${sel}`).count()) > 0;
      }
      return {
        hero: await attr("data-ad-hero"),
        ending: await attr("data-ad-ending"),
        preset: await attr("data-ad-preset"),
        pullquote: await attr("data-ad-pullquote"),
        motif: await attr("data-ad-motif"),
        present,
        blockKinds: Object.keys(present).filter((k) => present[k]),
        imgCount: await page.locator("article.cs img").count(),
      };
    }

    const lm = await fingerprint("localmarkers");
    const qg = await fingerprint("thequirkygiftco");

    // Different hero, ending, preset, pull-quote and motif — no shared template.
    expect(lm.hero).not.toBe(qg.hero);
    expect(lm.ending).not.toBe(qg.ending);
    expect(lm.preset).not.toBe(qg.preset);
    expect(lm.pullquote).not.toBe(qg.pullquote);
    expect(lm.motif).not.toBe(qg.motif);

    // Each page carries several distinctive block types...
    expect(lm.blockKinds.length).toBeGreaterThanOrEqual(3);
    expect(qg.blockKinds.length).toBeGreaterThanOrEqual(3);

    // ...and each has crafted blocks the other does NOT use (disjoint vocab).
    const lmOnly = lm.blockKinds.filter((k) => !qg.present[k]);
    const qgOnly = qg.blockKinds.filter((k) => !lm.present[k]);
    expect(lmOnly.length).toBeGreaterThanOrEqual(2);
    expect(qgOnly.length).toBeGreaterThanOrEqual(2);

    // The distinctive-block signatures are not identical.
    expect(lm.blockKinds.sort().join(",")).not.toBe(qg.blockKinds.sort().join(","));

    // Both actually show multiple images (not a single-hero fallback).
    expect(lm.imgCount).toBeGreaterThanOrEqual(3);
    expect(qg.imgCount).toBeGreaterThanOrEqual(3);
  });
});
