import { expect, test } from '@playwright/test'

// PHASE 4 E2E — karyawan buat request, admin gudang review + reserve.
// Requires seeded backend (:8001).
test('karyawan creates a request and admin gudang reserves it', async ({ page, request }) => {
  // login as karyawan via UI
  await page.goto('/login')
  await page.getByLabel('Username / Email').fill('kariawan')
  await page.getByLabel('Password').fill('Password@26')
  await page.getByRole('button', { name: 'Masuk' }).click()
  await expect(page.getByText('Selamat datang')).toBeVisible()

  await page.getByRole('link', { name: 'Request Barang' }).click()
  await page.getByRole('link', { name: 'Buat Request' }).click()

  await page.getByLabel('Keperluan').fill('E2E test request')
  await page.getByPlaceholder('Cari barang (min 2 huruf)…').fill('BAN')
  // pick the first result
  await page.locator('ul button').first().click()
  await page.getByRole('button', { name: 'Kirim Request' }).click()

  // lands on detail page
  await expect(page.getByText('E2E test request')).toBeVisible()
  const number = await page.getByRole('heading', { name: /^REQ\// }).innerText()
  expect(number).toMatch(/^REQ\//)

  // admin gudang picks it up via API to keep the test fast
  const login = await request.post('http://127.0.0.1:8001/api/login', {
    data: { username: 'admingudang', password: 'Password@26' },
    headers: { Accept: 'application/json' },
  })
  const token = (await login.json()).token
  const auth = { Authorization: `Bearer ${token}`, Accept: 'application/json' }

  const list = await request.get('http://127.0.0.1:8001/api/requests?search=' + encodeURIComponent(number), {
    headers: auth,
  })
  const req = (await list.json()).data[0]
  expect(req.status).toBe('SUBMITTED')

  await request.post(`http://127.0.0.1:8001/api/requests/${req.id}/review`, { headers: auth })
  const detail = await (await request.get(`http://127.0.0.1:8001/api/requests/${req.id}`, { headers: auth })).json()
  const lineId = detail.data.items[0].id
  await request.post(`http://127.0.0.1:8001/api/requests/${req.id}/items/${lineId}/physical-check`, {
    headers: auth,
    data: { status: 'VERIFIED_MATCH' },
  })
  const reserved = await (
    await request.post(`http://127.0.0.1:8001/api/requests/${req.id}/reserve`, { headers: auth })
  ).json()
  expect(['RESERVED', 'PARTIAL', 'NEED_PURCHASE']).toContain(reserved.data.status)
})
