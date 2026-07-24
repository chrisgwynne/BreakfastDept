const { test, expect } = require("@playwright/test");
const { execSync } = require("child_process");
const fs = require("fs");
const path = require("path");

// Delivery — milestones + board. Proves creating milestones and tasks through
// the UI, moving a task across board columns, and that project progress
// recalculates from real task completion. Desktop-only (the dependency graph +
// state machines are covered project-agnostically by DeliveryTest).

const ADMIN = { email: "admin@test.local", password: "test-admin-pw-1234" };

test.describe("Standalone admin — delivery board", () => {
  test.beforeAll(() => {
    execSync(`php bin/console user:seed --email=${ADMIN.email} --password=${ADMIN.password} --role=admin`, { cwd: process.cwd(), stdio: "inherit" });
    try { fs.rmSync(path.join(process.cwd(), "storage", "accounts", ".logins"), { force: true }); } catch { /* none */ }
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

  test("milestones + board create and task move recalculates progress", async ({ page }, testInfo) => {
    test.skip(testInfo.project.name === "mobile", "desktop-only multi-step journey");
    await login(page);

    // Create a project via the API-backed UI, land on its detail page.
    await page.goto("/breakfast-admin/projects");
    await page.getByRole("button", { name: "New project" }).first().click();
    await page.locator(".sheet").getByPlaceholder("e.g. Roberts Cafe website").fill("Delivery Board " + Date.now());
    await Promise.all([
      page.waitForResponse((r) => r.url().endsWith("/api/v1/projects") && r.request().method() === "POST"),
      page.locator(".sheet").getByRole("button", { name: "Create project" }).click(),
    ]);
    await page.waitForURL((u) => /\/projects\/[0-9a-f-]+/.test(u.toString()), { timeout: 10000 });

    // Milestones tab → add one.
    await page.getByRole("tab", { name: "Milestones" }).click();
    await page.locator('[data-test="new-milestone"]').fill("Design phase");
    await Promise.all([
      page.waitForResponse((r) => /\/projects\/[^/]+\/milestones$/.test(r.url()) && r.request().method() === "POST"),
      page.getByRole("button", { name: "Add", exact: true }).click(),
    ]);
    await expect(page.getByText("Design phase")).toBeVisible();

    // Board tab → add a task, then move it to completed.
    await page.getByRole("tab", { name: "Board" }).click();
    await page.locator('[data-test="new-task"]').fill("Build homepage");
    await Promise.all([
      page.waitForResponse((r) => /\/projects\/[^/]+\/tasks$/.test(r.url()) && r.request().method() === "POST"),
      page.getByRole("button", { name: "Add task" }).click(),
    ]);
    // The new task appears in the backlog column; move it to in_progress then completed.
    const taskSelect = page.locator('[data-test="task-backlog"]').first().locator("select");
    await Promise.all([
      page.waitForResponse((r) => /\/project-tasks\/[^/]+\/move$/.test(r.url())),
      taskSelect.selectOption("in_progress"),
    ]);
    const inProg = page.locator('[data-test="task-in_progress"]').first().locator("select");
    await Promise.all([
      page.waitForResponse((r) => /\/project-tasks\/[^/]+\/move$/.test(r.url())),
      inProg.selectOption("completed"),
    ]);

    // Progress recalculated from the completed task (1/1 = 100%).
    await expect(page.locator(".phead__badges").getByText("100% done")).toBeVisible({ timeout: 8000 });
  });

  test("delivery endpoints reject unauthenticated callers", async ({ request }) => {
    const ms = await request.post("/breakfast-admin/api/v1/milestones/x/status", { data: { status: "active" } });
    expect(ms.status()).toBe(401);
    const tk = await request.post("/breakfast-admin/api/v1/project-tasks/x/move", { data: { status: "ready" } });
    expect(tk.status()).toBe(401);
  });
});
