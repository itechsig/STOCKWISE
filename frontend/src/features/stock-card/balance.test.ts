import { describe, expect, it } from 'vitest'
import { balanceBefore, linesFromSisa, parseQty } from './balance'
import type { StockCardRow } from './api'

const row = (id: number, date: string, masuk: number, keluar: number): StockCardRow =>
  ({ id, date, masuk, keluar, sisa: 0, doc_no: null, description: null, files: [], updated_at: null })

// card order: 01/10 +15 (id 1), 04/10 −2 (id 2), 04/10 −1 (id 5), 06/10 +0.3 (id 3)
const rows = [row(1, '2026-10-01', 15, 0), row(2, '2026-10-04', 0, 2), row(5, '2026-10-04', 0, 1), row(3, '2026-10-06', 0.1, 0), row(4, '2026-10-06', 0.2, 0)]

describe('Kartu Stock: Sisa typed in', () => {
  it('takes the balance before a line in card order', () => {
    expect(balanceBefore(rows, '2026-10-04', 2)).toBe(15)          // first line of the 4th
    expect(balanceBefore(rows, '2026-10-04', 5)).toBe(13)          // after the earlier line of its date
    expect(balanceBefore(rows, '2026-10-04')).toBe(12)             // a new line goes after the whole date
    expect(balanceBefore(rows, '2026-10-02')).toBe(15)             // back-dated new line
    expect(balanceBefore(rows, '2026-09-30')).toBe(0)
    expect(balanceBefore(rows, '2026-10-07')).toBe(12.3)           // no float tail
  })

  it('turns the typed Sisa into Masuk or Keluar, never both', () => {
    expect(linesFromSisa(12, 20)).toEqual({ qty_in: '8', qty_out: '' })
    expect(linesFromSisa(12, 9)).toEqual({ qty_in: '', qty_out: '3' })
    expect(linesFromSisa(12.3, 12.1)).toEqual({ qty_in: '', qty_out: '0.2' })
    expect(linesFromSisa(12, -2)).toEqual({ qty_in: '', qty_out: '14' }) // may go below zero, shown red
    expect(linesFromSisa(12, 12)).toEqual({ qty_in: '', qty_out: '' })   // nothing moved: not saved
  })

  it('reads typed numbers with a comma', () => {
    expect(parseQty('2,5')).toBe(2.5)
    expect(parseQty('')).toBe(0)
    expect(Number.isNaN(parseQty('abc'))).toBe(true)
  })
})
