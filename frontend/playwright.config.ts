import { defineConfig, devices } from '@playwright/test'

/**
 * E2E runs against a live frontend + backend.
 * Start both first:  (backend) php artisan serve   (frontend) npm run dev
 * or let Playwright boot the frontend via webServer below.
 */
export default defineConfig({
  testDir: './e2e',
  fullyParallel: false,
  workers: 1, // shared backend + login rate-limit — keep specs serial
  forbidOnly: !!process.env.CI,
  retries: process.env.CI ? 1 : 0,
  reporter: 'list',
  use: {
    baseURL: process.env.E2E_BASE_URL ?? 'http://127.0.0.1:5173',
    trace: 'on-first-retry',
  },
  projects: [{ name: 'chromium', use: { ...devices['Desktop Chrome'] } }],
  webServer: process.env.E2E_NO_SERVER
    ? undefined
    : {
        command: 'npm run dev',
        url: 'http://127.0.0.1:5173',
        reuseExistingServer: !process.env.CI,
        timeout: 60_000,
      },
})
