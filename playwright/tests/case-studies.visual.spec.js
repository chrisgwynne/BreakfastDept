// Visual-regression baselines for the case-study art-direction system.
//
// OPT-IN: only runs under the `visual` project (BF_VISUAL=1). Baselines are
// environment-sensitive; re-baseline per environment with:
//   BF_VISUAL=1 npx playwright test --project=visual --update-snapshots
//
// The two REAL, published case studies must look visibly different at desktop
// and mobile — different heroes, block sequences, palettes and endings.
// LocalMarkers is a typography-led wayfinding page (editorial hero, grid motif,
// layered composition, motif close); The Quirky Gift Co is a playful product
// collage (collage hero, sticker motif, stacked/rotated products, giant-shot
// close). Runs under reduced motion with animations disabled for determinism;
// the fixed consent bar is masked.
const { test, expect } = require("@playwright/test");

const CASES = ["localmarkers", "thequirkygiftco"];

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
