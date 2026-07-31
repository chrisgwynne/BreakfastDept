// Full standalone Breakfast Admin portfolio authoring journey.
//
// Drives the ENTIRE workflow through Breakfast Admin (not Kirby Panel, not the
// CLI): create → art-direct → capture (deterministic stub driver) → approve +
// privacy → attach → layered composition → crop derivative → blocks → Preview
// Studio (desktop/tablet/mobile/reduced-motion) → publish → verify the public
// page. Uses the no-network NullCaptureDriver (SCREENSHOT_CMD unset), so it is
// fully deterministic in CI. No real client data is used.
const { test, expect } = require("@playwright/test");
const { execSync } = require("node:child_process");
const fs = require("node:fs");
const path = require("node:path");

const ADMIN = { email: "pf-admin@test.local", password: "pf-admin-pw-1234" };
const TITLE = "Journey Demo";
const SLUG = "journey-demo";
// A literal public IP as the approved host → SafeUrl passes with no DNS, the
// stub driver returns a placeholder image. Deterministic offline.
const HOST = "93.184.216.34";

function cleanup() {
  const root = process.cwd();
  const work = path.join(root, "content", "1_work");
  const paths = [
    path.join(work, "_drafts", SLUG),
    ...(fs.existsSync(work) ? fs.readdirSync(work).filter((d) => d.endsWith("_" + SLUG)).map((d) => path.join(work, d)) : []),
    // Runtime capture/derivative state, so a fresh run starts with zero shots.
    path.join(root, "storage", "data", "case_study_screenshots"),
    path.join(root, "storage", "data", "case_study_derivatives"),
    path.join(root, "storage", "screenshots"),
    path.join(root, "storage", "derivatives"),
  ];
  for (const p of paths) fs.rmSync(p, { recursive: true, force: true });
}

test.describe("Breakfast Admin — portfolio authoring journey", () => {
  test.beforeAll(() => {
    execSync(`php bin/console user:seed --email=${ADMIN.email} --password=${ADMIN.password} --role=admin`, { cwd: process.cwd(), stdio: "ignore" });
    cleanup();
  });
  test.afterAll(() => cleanup());

  async function login(page) {
    await page.goto("/breakfast-admin/login");
    await page.locator("#email").fill(ADMIN.email);
    await page.locator("#password").fill(ADMIN.password);
    await page.getByRole("button", { name: /sign in|log in/i }).click();
    await page.waitForURL((u) => !u.toString().includes("/login"), { timeout: 15000 });
  }

  test("author a bespoke case study end to end and publish it", async ({ page }, testInfo) => {
    // Stateful, single-record journey — run it on one project only.
    test.skip(testInfo.project.name !== "desktop", "desktop-only journey");
    test.setTimeout(120000);
    await login(page);

    // 1. Create the record.
    await page.goto("/breakfast-admin/portfolio");
    await page.getByTestId("pf-new-title").fill(TITLE);
    await page.getByTestId("pf-create").click();
    await page.waitForURL(new RegExp(`/portfolio/${SLUG}`), { timeout: 15000 });

    // 2. Details: summary, approved host + paths.
    await page.getByTestId("tab-details").click();
    await page.getByTestId("f-summary").fill("A bespoke, art-directed case study built end to end in Breakfast Admin.");
    await page.getByTestId("f-hosts").fill(HOST);
    await page.getByTestId("f-paths").fill("/, /about");
    await page.getByTestId("pf-save").click();
    await expect(page.getByTestId("pf-msg")).toBeVisible();

    // 3. Art direction: Playful Collage.
    await page.getByTestId("tab-art").click();
    await page.getByTestId("ad-preset").selectOption("playful-collage");
    await page.getByTestId("pf-save").click();

    // 4. Capture desktop + mobile (stub driver).
    await page.getByTestId("tab-shots").click();
    await page.getByTestId("cap-viewport").selectOption("desktop");
    await page.getByTestId("cap-path").fill("/");
    await page.getByTestId("cap-go").click();
    await expect(page.getByTestId("shot-desktop").first()).toBeVisible({ timeout: 15000 });
    await page.getByTestId("cap-viewport").selectOption("mobile");
    await page.getByTestId("cap-go").click();
    await expect(page.getByTestId("shot-mobile").first()).toBeVisible({ timeout: 15000 });

    // 5. Approve public use + privacy review + attach, for every capture.
    for (const row of ["shot-desktop", "shot-mobile"]) {
      const tr = page.getByTestId(row).first();
      await tr.getByRole("button", { name: /approve use/i }).click();
      await expect(tr.getByRole("button", { name: /✓ public use/i })).toBeVisible();
      await tr.getByRole("button", { name: /privacy ok/i }).click();
      await expect(tr.getByRole("button", { name: /✓ privacy/i })).toBeVisible();
      await tr.getByRole("button", { name: /^attach$/i }).click();
    }
    // Two media files now exist.
    await page.getByTestId("tab-crop").click();
    await expect(page.getByTestId("crop-source").locator("option")).toHaveCount(3); // placeholder + 2

    // 6. Layered composition: desktop + two mobile layers, reorder, scale, rotate, per-breakpoint.
    await page.getByTestId("tab-composition").click();
    await page.getByTestId("layer-desktop").click();
    await page.getByTestId("layer-mobile").click();
    await page.getByTestId("layer-mobile").click();
    await page.getByTestId("layer-annotation").click();
    await expect(page.getByTestId("layers").locator("li")).toHaveCount(4);
    await page.getByTestId("layer-1-up").click(); // reorder
    await page.getByTestId("layer-0-scale").fill("1.15");
    await page.getByTestId("layer-1-rot").selectOption("5");
    await page.getByTestId("layer-2-mobile").uncheck(); // hide a layer on mobile

    // 7. Detail crop / derivative from an attached screenshot.
    await page.getByTestId("tab-crop").click();
    await page.getByTestId("crop-source").selectOption({ index: 1 });
    await page.getByTestId("crop-kind").selectOption("detail");
    await page.getByTestId("crop-w").fill("0.4");
    await page.getByTestId("crop-h").fill("0.4");
    await page.getByTestId("crop-create").click();
    await expect(page.getByTestId("derivs").locator("li")).toHaveCount(1, { timeout: 15000 });

    // 8. Blocks: giant pull quote, full-page capture, before/after.
    await page.getByTestId("tab-blocks").click();
    await page.getByTestId("add-quote").click();
    await page.getByTestId("add-fullpage").click();
    await page.getByTestId("add-beforeafter").click();
    await expect(page.getByTestId("blocklist").locator("li")).toHaveCount(3);

    // 9. Save everything.
    await page.getByTestId("pf-save").click();
    await expect(page.getByTestId("pf-msg")).toBeVisible();

    // 10. Preview Studio across devices + reduced motion.
    await page.getByTestId("tab-preview").click();
    for (const d of ["desktop", "tablet", "mobile"]) {
      await page.getByTestId(`dev-${d}`).click();
      await expect(page.getByTestId("bp-indicator")).toContainText(d);
    }
    await page.getByTestId("dev-rm").check();
    await page.getByTestId("prev-refresh").click();
    await expect(page.getByTestId("preview-frame")).toBeVisible();
    await expect(page.getByTestId("perf-estimate")).toBeVisible();
    await expect(page.getByTestId("block-nav")).toContainText("cs-quote");

    // 11. Publish (blockers must be resolved: summary + image + approved shots).
    await expect(page.getByTestId("pf-publish")).toBeEnabled();
    await page.getByTestId("pf-publish").click();
    await expect(page.getByTestId("pf-msg")).toContainText(/Published/i, { timeout: 15000 });

    // 12. Verify the public page.
    const resp = await page.goto(`/work/${SLUG}`);
    expect(resp.status()).toBe(200);
    await expect(page.locator("article.cs")).toHaveAttribute("data-ad-preset", "playful-collage");
    // Composition + a pull quote rendered.
    await expect(page.locator(".cs-comp, .cs-quote").first()).toBeVisible();
    // No horizontal overflow.
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
    expect(overflow).toBeLessThanOrEqual(0);
    // Private / source data absent.
    const html = await page.content();
    for (const marker of ["ad_preset", "approved_hosts", "storage/data", "preview_token_version", HOST]) {
      expect(html).not.toContain(marker);
    }
    // Attached screenshot derivatives/media are served.
    const broken = await page.evaluate(() => Array.from(document.querySelectorAll("article.cs img")).filter((i) => i.complete && i.naturalWidth === 0).length);
    expect(broken).toBe(0);
  });
});
