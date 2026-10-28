import { expect, test } from '@playwright/test'

// PHASE 2 E2E: real login against the seeded backend (:8001).
// Requires: php artisan migrate:fresh --seed  +  php artisan serve --port=8001
test.describe('authentication', () => {
  test('rejects bad credentials', async ({ page }) => {
    await page.goto('/login')
    await page.getByLabel('Username / Email').fill('superadmin')
    await page.getByLabel('Password').fill('wrong-password')
    await page.getByRole('button', { name: 'Masuk' }).click()

    await expect(page.getByRole('alert')).toBeVisible()
    await expect(page).toHaveURL(/\/login$/)
  })

  test('logs in and reaches the dashboard, then logs out', async ({ page }) => {
    await page.goto('/login')
    await page.getByLabel('Username / Email').fill('admingudang')
    await page.getByLabel('Password').fill('Password@26')
    await page.getByRole('button', { name: 'Masuk' }).click()

    await expect(page.getByText('Selamat datang, Admin Gudang')).toBeVisible()
    await expect(page.getByText(/\d+ permission aktif/)).toBeVisible()
    await expect(page.getByText('admin_gudang', { exact: true })).toBeVisible()

    await page.getByRole('button', { name: 'Keluar' }).click()
    await expect(page).toHaveURL(/\/login$/)
  })

  test('unauthenticated visit to a protected route redirects to login', async ({ page }) => {
    await page.goto('/')
    await expect(page).toHaveURL(/\/login$/)
  })
})
