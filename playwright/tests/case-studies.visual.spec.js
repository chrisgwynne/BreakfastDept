// Visual-regression baselines for the case-study art-direction system.
//
// OPT-IN: only runs under the `visual` project (BF_VISUAL=1). Baselines are
// environment-sensitive; re-baseline per environment with:
//   BF_VISUAL=1 npx playwright test --project=visual --update-snapshots
//
// The three concept case studies collectively exercise every major layout type:
// cinematic / layered-devices / collage heroes, oversized + rotated + stacked +
// full-bleed screenshots, device arrangement, before/after (slider + split),
// annotated detail, giant + marks pull quotes, colour blocks, palette, specimen,
// facts and the ending variants. Runs under reduced motion with animations
// disabled for determinism; the fixed consent bar is masked.
const { test, expect } = require("@playwright/test");

const CASES = ["riverside-kitchen", "ironclad-roofing", "marlowe-and-fox"];

test.beforeEach(async ({ page }) => {
  await page.addInitScript(() => {
    try {
      localStorage.setItem("bf-consent", "declined");
    } catch {}
  });
});

// Force every image to load eagerly and wait for them all to decode, so a
// full-page capture is deterministic (no lazy-load timing races).
async function settleImages(page) {
  await page.evaluate(async () => {
    const imgs = Array.from(document.querySelectorAll("img"));
    imgs.forEach((i) => {
      i.loading = "eager";
      i.setAttribute("fetchpriority", "high");
    });
    await Promise.all(
      imgs.map((i) =>
        i.complete && i.naturalWidth > 0
          ? Promise.resolve()
          : i.decode().catch(() => {})
      )
    );
    // Settle fonts too.
    if (document.fonts && document.fonts.ready) await document.fonts.ready;
  });
  await page.waitForTimeout(300);
}

for (const slug of CASES) {
  test(`${slug} — full page (desktop)`, async ({ page }) => {
    await page.goto(`/work/${slug}`);
    await page.waitForLoadState("networkidle");
    await settleImages(page);
    await expect(page).toHaveScreenshot(`${slug}-desktop.png`, {
      fullPage: true,
      mask: [page.locator("#consent, .consent, [data-consent]")],
    });
  });

  test(`${slug} — full page (mobile width)`, async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto(`/work/${slug}`);
    await page.waitForLoadState("networkidle");
    await settleImages(page);
    await expect(page).toHaveScreenshot(`${slug}-mobile.png`, {
      fullPage: true,
      mask: [page.locator("#consent, .consent, [data-consent]")],
    });
  });
}
