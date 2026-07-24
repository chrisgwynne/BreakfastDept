const { test, expect } = require("@playwright/test");
const { execSync } = require("child_process");
const fs = require("fs");
const path = require("path");

// The calendar as a real scheduling tool: create/edit/delete events through the
// UI against tested endpoints, confirm a created event shows on the dashboard's
// upcoming list, and that the endpoints require authentication.

const ADMIN = { email: "admin@test.local", password: "test-admin-pw-1234" };

test.describe("Standalone admin — calendar", () => {
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

  // A datetime-local value a few days out at 10:00, so it lands in range.
  function soon(offsetDays) {
    const d = new Date();
    d.setDate(d.getDate() + offsetDays);
    d.setHours(10, 0, 0, 0);
    const pad = (n) => String(n).padStart(2, "0");
    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
  }

  test("create, edit and delete an event; it shows on the dashboard", async ({ page }) => {
    await login(page);
    await page.goto("/breakfast-admin/calendar");
    await expect(page.getByRole("heading", { name: "Calendar" })).toBeVisible({ timeout: 8000 });

    // Agenda view is easiest to assert against.
    await page.getByRole("button", { name: "Agenda", exact: true }).click();

    const title = "E2E Meeting " + Date.now();
    await page.getByRole("button", { name: "New event" }).first().click();
    await page.locator(".field", { hasText: "Title" }).locator("input").fill(title);
    await page.locator('input[type="datetime-local"]').first().fill(soon(2));
    await page.locator('input[type="datetime-local"]').nth(1).fill(soon(2).replace("T10:00", "T11:00"));

    const [createRes] = await Promise.all([
      page.waitForResponse((r) => r.url().endsWith("/api/v1/calendar/events") && r.request().method() === "POST"),
      page.getByRole("button", { name: "Create event" }).click(),
    ]);
    expect(createRes.status(), "create must succeed server-side").toBe(200);
    await expect(page.getByText("Event created")).toBeVisible({ timeout: 8000 });
    await expect(page.getByText(title)).toBeVisible({ timeout: 8000 });

    // Edit it.
    await page.getByText(title).first().click();
    await page.locator(".field", { hasText: "Title" }).locator("input").fill(title + " (moved)");
    const [editRes] = await Promise.all([
      page.waitForResponse((r) => /\/api\/v1\/calendar\/events\/[^/]+$/.test(r.url()) && r.request().method() === "PATCH"),
      page.getByRole("button", { name: "Save", exact: true }).click(),
    ]);
    expect(editRes.status()).toBe(200);
    await expect(page.getByText("Event updated")).toBeVisible({ timeout: 8000 });

    // It appears on the dashboard's upcoming list.
    await page.goto("/breakfast-admin/");
    await expect(page.getByText(title + " (moved)")).toBeVisible({ timeout: 8000 });

    // Delete it.
    await page.goto("/breakfast-admin/calendar");
    await page.getByRole("button", { name: "Agenda", exact: true }).click();
    await page.getByText(title + " (moved)").first().click();
    const [delRes] = await Promise.all([
      page.waitForResponse((r) => /\/api\/v1\/calendar\/events\/[^/]+$/.test(r.url()) && r.request().method() === "DELETE"),
      page.getByRole("button", { name: "Delete", exact: true }).click(),
    ]);
    expect(delRes.status()).toBe(200);
    await expect(page.getByText("Event deleted")).toBeVisible({ timeout: 8000 });
  });

  test("calendar endpoints reject unauthenticated callers", async ({ request }) => {
    const list = await request.get("/breakfast-admin/api/v1/calendar/events");
    expect(list.status()).toBe(401);
    const create = await request.post("/breakfast-admin/api/v1/calendar/events", { data: { title: "x" } });
    expect(create.status()).toBe(401);
  });
});
