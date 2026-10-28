import { useMemo, useState, useEffect } from 'react'
import { useCategoryTree } from '@/features/inventory/api'
import { Select } from '@/components/ui/select'
import { Label } from '@/components/ui/label'
import type { CategoryNode } from '@/types/inventory'

function findPath(nodes: CategoryNode[], id: number): CategoryNode[] | null {
  for (const node of nodes) {
    if (node.id === id) return [node]
    const inChild = findPath(node.children, id)
    if (inChild) return [node, ...inChild]
  }
  return null
}

const LABELS = ['Kategori Induk', 'Kategori Anak 1', 'Kategori Anak 2', 'Kategori Anak 3']

/** 4 select bertingkat — sama seperti kolom Kategori Induk/Anak 1/2/3 di DATA.xlsx. */
export function CategoryPicker({
  value,
  onChange,
}: {
  value: number | null
  onChange: (categoryId: number | null) => void
}) {
  const { data: tree, isLoading } = useCategoryTree()
  const [path, setPath] = useState<(number | null)[]>([null, null, null, null])

  useEffect(() => {
    if (!tree) return
    if (value == null) {
      setPath([null, null, null, null])
      return
    }
    const found = findPath(tree, value)
    if (found) {
      const ids = found.map((n) => n.id)
      setPath([ids[0] ?? null, ids[1] ?? null, ids[2] ?? null, ids[3] ?? null])
    }
  }, [tree, value])

  const levels = useMemo(() => {
    if (!tree) return [[], [], [], []] as CategoryNode[][]
    const l1 = tree
    const l2 = l1.find((n) => n.id === path[0])?.children ?? []
    const l3 = l2.find((n) => n.id === path[1])?.children ?? []
    const l4 = l3.find((n) => n.id === path[2])?.children ?? []
    return [l1, l2, l3, l4]
  }, [tree, path])

  function pick(levelIdx: number, id: number | null) {
    const next = [...path]
    next[levelIdx] = id
    for (let i = levelIdx + 1; i < 4; i++) next[i] = null
    setPath(next)
    const deepest = [...next].reverse().find((v) => v != null) ?? null
    onChange(deepest ?? null)
  }

  if (isLoading) return <p className="text-sm text-muted-foreground">Memuat kategori…</p>

  return (
    <div className="grid grid-cols-2 gap-3">
      {levels.map((options, i) => {
        if (i > 0 && levels[i - 1].length > 0 && path[i - 1] == null) return null
        if (i > 0 && options.length === 0) return null

        return (
          <div key={i} className="space-y-1.5">
            <Label>{LABELS[i]}</Label>
            <Select value={path[i] ?? ''} onChange={(e) => pick(i, e.target.value ? Number(e.target.value) : null)}>
              <option value="">— pilih —</option>
              {options.map((o) => (
                <option key={o.id} value={o.id}>
                  {o.name}
                </option>
              ))}
            </Select>
          </div>
        )
      })}
    </div>
  )
}
