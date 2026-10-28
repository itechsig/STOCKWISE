import { describe, expect, it } from 'vitest'
import { fireEvent, render, screen } from '@testing-library/react'
import { Link, MemoryRouter, Route, Routes, useNavigate } from 'react-router-dom'
import { useRemembered } from './listMemory'

function List() {
  const [q, setQ] = useRemembered('test-list', 'q', '')
  return (
    <div>
      <input aria-label="cari" value={q} onChange={(e) => setQ(e.target.value)} />
      <Link to="/detail">buka detail</Link>
    </div>
  )
}

function Detail() {
  const navigate = useNavigate()
  return (
    <div>
      <button type="button" onClick={() => navigate(-1)}>kembali</button>
      <Link to="/list">menu daftar</Link>
    </div>
  )
}

function App() {
  return (
    <MemoryRouter initialEntries={['/list']}>
      <Routes>
        <Route path="/list" element={<List />} />
        <Route path="/detail" element={<Detail />} />
      </Routes>
    </MemoryRouter>
  )
}

describe('useRemembered (list filters kept across Back)', () => {
  it('restores the filter when coming back, starts clean when opened again from a link', () => {
    render(<App />)
    fireEvent.change(screen.getByLabelText('cari'), { target: { value: 'BAUT' } })

    // list -> detail -> Back: the search is still there
    fireEvent.click(screen.getByText('buka detail'))
    fireEvent.click(screen.getByText('kembali'))
    expect(screen.getByLabelText('cari')).toHaveValue('BAUT')

    // list -> detail -> open the list fresh (sidebar / link): clean
    fireEvent.click(screen.getByText('buka detail'))
    fireEvent.click(screen.getByText('menu daftar'))
    expect(screen.getByLabelText('cari')).toHaveValue('')
  })
})
