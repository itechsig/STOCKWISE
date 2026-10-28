import { expect, test } from '@playwright/test'

// PHASE 1 smoke: SPA boots and reaches the backend.
// Requires backend running at :8001 (php artisan serve --port=8001) with `/api/ping`.
test('health page shows backend connected', async ({ page }) => {
  await page.goto('/health')
  await expect(page.getByText('STOCKWISE — Health Check')).toBeVisible()
  await expect(page.getByText('connected')).toBeVisible({ timeout: 10_000 })
})
