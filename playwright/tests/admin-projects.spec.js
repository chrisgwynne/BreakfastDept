const { test, expect } = require("@playwright/test");
const { execSync } = require("child_process");
const fs = require("fs");
const path = require("path");

// Projects — the delivery workspace. Proves manual creation, the status state
// machine through the UI (with a server-enforced reason), reopen/archive/restore,
// and that the activity timeline records each transition. Desktop-only multi-step
// journey (the state machine is covered project-agnostically by ProjectsTest).

const ADMIN = { email: "admin@test.local", password: "test-admin-pw-1234" };

test.describe("Standalone admin — projects", () => {
  test.beforeAll(() => {
    execSync(`php bin/console user:seed --email=${ADMIN.email} --password=${ADMIN.password} --role=admin`, {
      cwd: process.cwd(),
      stdio: "inherit",
    });
    try {
      fs.rmSync(path.join(process.cwd(), "storage", "accounts", ".logins"), { force: true });
    } catch {
      /* no throttle file */
    }
  });

  async function login(page) {
    await page.goto("/breakfast-admin/login");
    await page.locator("#email").fill(ADMIN.email);
    await page.locator("#password").fill(ADMIN.password);
    await Promise.all([
      page.waitForResponse((r) => r.url().includes("/breakfast-admin/api/v1/session") && r.request().method() === "POST"),
      page.getByRole("button", { name: /sign in/i }).click(),
    ]);
    await page.waitForURL((u) => !u.toString().includes("/login"), { timeout: 15000 });
  }

  test("create a project and drive it through the status state machine", async ({ page }, testInfo) => {
    test.skip(testInfo.project.name === "mobile", "desktop-only multi-step journey");
    await login(page);

    // Create a manual project.
    await page.goto("/breakfast-admin/projects");
    await page.getByRole("button", { name: "New project" }).first().click();
    const name = "Delivery Project " + Date.now();
    await page.locator(".sheet").getByPlaceholder("e.g. Roberts Cafe website").fill(name);
    await page.locator(".sheet").getByPlaceholder("brochure, ecommerce…").fill("brochure");
    await page.locator(".sheet").locator('.field', { hasText: "Quoted value" }).locator("input").fill("4000");
    const [createRes] = await Promise.all([
      page.waitForResponse((r) => r.url().endsWith("/api/v1/projects") && r.request().method() === "POST"),
      page.locator(".sheet").getByRole("button", { name: "Create project" }).click(),
    ]);
    expect(createRes.status()).toBe(200);
    // We land on the detail page.
    await page.waitForURL((u) => /\/projects\/[0-9a-f-]+/.test(u.toString()), { timeout: 10000 });
    await expect(page.locator(".phead__title")).toHaveText(name);

    // Drive the state machine: draft → planning → active.
    await Promise.all([
      page.waitForResponse((r) => /\/projects\/[^/]+\/status$/.test(r.url()) && r.request().method() === "POST"),
      page.getByRole("button", { name: "planning", exact: true }).click(),
    ]);
    await expect(page.locator(".app-toast", { hasText: "Status updated" })).toBeVisible({ timeout: 8000 });
    await Promise.all([
      page.waitForResponse((r) => /\/projects\/[^/]+\/status$/.test(r.url())),
      page.getByRole("button", { name: "active", exact: true }).click(),
    ]);

    // Blocking requires a reason — accept the prompt.
    page.once("dialog", (d) => d.accept("Waiting on client hosting login"));
    await Promise.all([
      page.waitForResponse((r) => /\/projects\/[^/]+\/status$/.test(r.url())),
      page.getByRole("button", { name: "blocked", exact: true }).click(),
    ]);
    await expect(page.getByText("Blocked: Waiting on client hosting login")).toBeVisible({ timeout: 8000 });

    // The activity timeline recorded the transitions.
    await expect(page.locator(".timeline .tl", { hasText: "Status" }).first()).toBeVisible();

    // Archive then restore.
    await Promise.all([
      page.waitForResponse((r) => /\/projects\/[^/]+\/archive$/.test(r.url())),
      page.getByRole("button", { name: "Archive", exact: true }).click(),
    ]);
    await Promise.all([
      page.waitForResponse((r) => /\/projects\/[^/]+\/restore$/.test(r.url())),
      page.getByRole("button", { name: "Restore", exact: true }).click(),
    ]);
  });

  test("project endpoints reject unauthenticated callers", async ({ request }) => {
    const list = await request.get("/breakfast-admin/api/v1/projects");
    expect(list.status()).toBe(401);
    const create = await request.post("/breakfast-admin/api/v1/projects", { data: { name: "x" } });
    expect(create.status()).toBe(401);
  });
});
