import { useMemo, useState } from 'react'
import { describe, expect, it } from 'vitest'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { useInfiniteRows, useInfiniteSlice } from './InfiniteRows'

const PAGES: Record<string, string[][]> = {
  all: [['a1', 'a2'], ['a3', 'a4'], ['a5']],
  filtered: [['b1']],
}

function Harness() {
  const [page, setPage] = useState(1)
  const [filter, setFilter] = useState<'all' | 'filtered'>('all')
  // stable per (filter, page), like a react-query result
  const data = useMemo(() => {
    const pages = PAGES[filter]
    return { data: pages[page - 1] ?? [], meta: { page, last_page: pages.length, total: pages.flat().length, per_page: 2 } }
  }, [filter, page])
  const { rows, footer } = useInfiniteRows(data, page, setPage)
  return (
    <div>
      <ul>{rows.map((r) => <li key={r}>{r}</li>)}</ul>
      {footer}
      <button type="button" onClick={() => { setFilter('filtered'); setPage(1) }}>filter</button>
    </div>
  )
}

const items = (ul: HTMLElement[]) => ul.map((l) => l.textContent)

describe('useInfiniteRows (numbered pages)', () => {
  it('shows one page at a time and starts over on a new filter', async () => {
    render(<Harness />)
    expect(items(screen.getAllByRole('listitem'))).toEqual(['a1', 'a2'])
    expect(screen.getByText('1–2')).toBeInTheDocument()

    await userEvent.click(screen.getByRole('button', { name: 'Halaman berikutnya' }))
    expect(items(screen.getAllByRole('listitem'))).toEqual(['a3', 'a4'])
    await userEvent.click(screen.getByRole('button', { name: '3' }))
    expect(items(screen.getAllByRole('listitem'))).toEqual(['a5'])
    expect(screen.getByRole('button', { name: 'Halaman berikutnya' })).toBeDisabled()

    await userEvent.click(screen.getByRole('button', { name: 'filter' }))
    expect(items(screen.getAllByRole('listitem'))).toEqual(['b1'])
    expect(screen.queryByRole('navigation', { name: 'Halaman' })).not.toBeInTheDocument()
  })
})

function SliceHarness({ list }: { list: string[] }) {
  const { rows, footer } = useInfiniteSlice(list, 2)
  return <div><ul>{rows.map((r) => <li key={r}>{r}</li>)}</ul>{footer}</div>
}

describe('useInfiniteSlice', () => {
  it('pages a loaded list', async () => {
    render(<SliceHarness list={['x1', 'x2', 'x3']} />)
    expect(items(screen.getAllByRole('listitem'))).toEqual(['x1', 'x2'])
    await userEvent.click(screen.getByRole('button', { name: '2' }))
    expect(items(screen.getAllByRole('listitem'))).toEqual(['x3'])
  })
})
