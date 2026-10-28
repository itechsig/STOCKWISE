import { expect, test } from '@playwright/test'

// PHASE 3 E2E — requires seeded backend (:8001) with imported items.
test('admin gudang browses items and inventory analysis', async ({ page }) => {
  await page.goto('/login')
  await page.getByLabel('Username / Email').fill('admingudang')
  await page.getByLabel('Password').fill('Password@26')
  await page.getByRole('button', { name: 'Masuk' }).click()
  await expect(page.getByText('Selamat datang, Admin Gudang')).toBeVisible()

  await page.getByRole('link', { name: 'Master Barang' }).click()
  await expect(page.getByRole('heading', { name: 'Master Barang' })).toBeVisible()
  await expect(page.getByText(/\d+ barang/)).toBeVisible()

  await page.getByRole('link', { name: 'Analisis Inventory' }).click()
  await expect(page.getByRole('heading', { name: /Analisis Inventory/ })).toBeVisible()
  await expect(page.getByText('Threshold Lead Time (p75)')).toBeVisible()
})

test('karyawan cannot reach the items page', async ({ page }) => {
  await page.goto('/login')
  await page.getByLabel('Username / Email').fill('kariawan')
  await page.getByLabel('Password').fill('Password@26')
  await page.getByRole('button', { name: 'Masuk' }).click()
  await expect(page.getByText('Selamat datang')).toBeVisible()

  // no nav link, and direct visit is blocked
  await expect(page.getByRole('link', { name: 'Master Barang' })).toHaveCount(0)
  await page.goto('/items')
  await expect(page.getByText(/tidak punya akses/i)).toBeVisible()
})
