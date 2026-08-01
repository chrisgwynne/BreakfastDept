// Editorial Composer — the art-director panel + make-beautiful-default.
//
// Verifies that a new case study begins from a rich starter composition (not a
// blank page), that the Composer panel surfaces real, structure-aware analysis
// (story health, uniqueness, rhythm, creative-director notes, a storyboard and
// layout ideas), and that applying a layout idea actually rebuilds the draft.
// Uses the no-network stub driver; no real client data.
const { test, expect } = require("@playwright/test");
const { execSync } = require("node:child_process");
const fs = require("node:fs");
const path = require("node:path");

const ADMIN = { email: "composer-admin@test.local", password: "composer-pw-1234" };
const TITLE = "Composer Demo";
const SLUG = "composer-demo";

function cleanup() {
  const work = path.join(process.cwd(), "content", "1_work");
  const paths = [
    path.join(work, "_drafts", SLUG),
    ...(fs.existsSync(work) ? fs.readdirSync(work).filter((d) => d.endsWith("_" + SLUG)).map((d) => path.join(work, d)) : []),
  ];
  for (const p of paths) fs.rmSync(p, { recursive: true, force: true });
}

test.describe("Editorial Composer", () => {
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

  test("a new project starts rich, and the Composer actively guides", async ({ page }, testInfo) => {
    test.skip(testInfo.project.name !== "desktop", "desktop-only");
    test.setTimeout(90000);
    await login(page);

    // Make beautiful the default: "Start from a composition" seeds a rich page.
    await page.goto("/breakfast-admin/portfolio");
    await page.getByTestId("pf-new-title").fill(TITLE);
    await page.getByTestId("pf-create").click();
    await page.waitForURL(new RegExp(`/portfolio/${SLUG}`), { timeout: 15000 });

    // The Composer panel reviews the page.
    await page.getByTestId("tab-composer").click();
    await expect(page.getByTestId("composer-panel")).toBeVisible();
    await expect(page.getByTestId("composer-story-score")).toBeVisible();
    await expect(page.getByTestId("composer-uniqueness")).toBeVisible();

    // The starter gave us a non-empty storyboard (a rich default, not blank).
    const cellsBefore = await page.getByTestId("composer-storyboard").locator("li[data-test^='cell-']").count();
    expect(cellsBefore).toBeGreaterThanOrEqual(5);

    // Creative-director notes reference the page (e.g. it needs images/screenshots).
    await expect(page.getByTestId("composer-notes")).toBeVisible();
    expect(await page.getByTestId("composer-notes").locator("li").count()).toBeGreaterThan(0);

    // Layout ideas are offered and are genuinely different directions.
    const ideas = page.getByTestId("composer-ideas").locator("article[data-test^='idea-']");
    expect(await ideas.count()).toBeGreaterThanOrEqual(3);

    // Apply a layout idea — the draft is rebuilt (storyboard changes).
    const beforeSig = await page.getByTestId("composer-storyboard").innerText();
    await page.locator("[data-test^='idea-apply-']").first().click();
    await expect(page.getByTestId("composer-panel")).toBeVisible();
    await expect
      .poll(async () => page.getByTestId("composer-storyboard").innerText(), { timeout: 15000 })
      .not.toBe(beforeSig);

    // The editor's block list reflects the applied composition.
    await page.getByTestId("tab-blocks").click();
    expect(await page.getByTestId("blocklist").locator("li").count()).toBeGreaterThanOrEqual(3);
  });
});
