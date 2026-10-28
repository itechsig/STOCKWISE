import { describe, expect, it } from 'vitest'
import { render, screen } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { DocText, docHref } from './DocLink'

describe('DocLink', () => {
  it('routes each Accurate document number to its list, filtered on that number', () => {
    expect(docHref('NA/26/IX/054')).toBe('/npbg?search=NA%2F26%2FIX%2F054')
    expect(docHref('ATK/26/VI/032')).toBe('/npbg?search=ATK%2F26%2FVI%2F032')
    expect(docHref('RI/NV/26/IX/039')).toBe('/ri?search=RI%2FNV%2F26%2FIX%2F039')
    expect(docHref('PPB/NA/26/IX/026')).toBe('/ppb?search=PPB%2FNA%2F26%2FIX%2F026')
    expect(docHref('BL/D/26/IX/065')).toBe('/purchase-orders?search=BL%2FD%2F26%2FIX%2F065')
    expect(docHref('STPP/SDA/26/IX/010')).toBeNull()
  })

  it('turns numbers inside a keterangan into links and keeps the rest as text', () => {
    render(
      <MemoryRouter>
        <DocText text="PENGEMBALIAN BAN BEKAS, REF NPBG: NA/26/VIII/222 & NA/26/VIII/179 (31.08.2026)" />
      </MemoryRouter>,
    )
    expect(screen.getByRole('link', { name: 'NA/26/VIII/222' })).toHaveAttribute('href', '/npbg?search=NA%2F26%2FVIII%2F222')
    expect(screen.getByRole('link', { name: 'NA/26/VIII/179' })).toBeInTheDocument()
    expect(screen.getByText(/PENGEMBALIAN BAN BEKAS/)).toBeInTheDocument()
  })
})
