import type { StockCardRow } from './api'

/** 4 decimals, like the database (DECIMAL 18,4) — no 0.1 + 0.2 tails. */
export const round4 = (n: number) => Math.round(n * 10_000) / 10_000

/** A typed quantity ("12", "2,5", "") as a number; NaN when it isn't one. */
export const parseQty = (s: string) => (s.trim() === '' ? 0 : Number(s.trim().replace(',', '.')))

/**
 * The card's balance just before a line dated `date`, in card order (date, then as
 * written — the backend's order). `selfId`: the line itself, which then only follows
 * the earlier lines of its own date; a new line (no id) comes after all of them.
 */
export function balanceBefore(rows: StockCardRow[], date: string, selfId?: number): number {
  let sum = 0
  for (const r of rows) {
    if (r.id === selfId) continue
    if (r.date < date || (r.date === date && (selfId == null || r.id < selfId))) sum += r.masuk - r.keluar
  }
  return round4(sum)
}

/**
 * Masuk / Keluar that turn the balance `before` into the typed Sisa: up = Masuk,
 * down = Keluar, never both — so what is stored stays Masuk / Keluar and the Sisa
 * keeps being computed from them.
 */
export function linesFromSisa(before: number, sisa: number): { qty_in: string; qty_out: string } {
  const diff = round4(sisa - before)
  if (diff > 0) return { qty_in: String(diff), qty_out: '' }
  if (diff < 0) return { qty_in: '', qty_out: String(-diff) }
  return { qty_in: '', qty_out: '' }
}
