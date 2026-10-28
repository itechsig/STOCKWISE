import { useState } from 'react'
import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Check, Loader2, MessageSquarePlus, Pencil, X } from 'lucide-react'
import { api, apiErrorMessage } from '@/lib/api'
import { Textarea } from '@/components/ui/textarea'

/**
 * "Catatan" column: a free note any user can put on a row of a data table.
 * Stored per table (entity) + row key in `record_notes`, never on the data itself.
 */
export interface RowNote {
  note: string
  by: string | null
  at: string | null
}

/** Notes of the rows on screen (fetched in chunks, one query per table). */
export function useRowNotes(entity: string | undefined, keys: (string | number)[]) {
  const ids = [...new Set(keys.map(String))]
  return useQuery({
    queryKey: ['record-notes', entity, ids.join(',')],
    enabled: !!entity && ids.length > 0,
    placeholderData: keepPreviousData,
    staleTime: 30_000,
    queryFn: async () => {
      const chunks: string[][] = []
      for (let i = 0; i < ids.length; i += 200) chunks.push(ids.slice(i, i + 200))
      const parts = await Promise.all(
        chunks.map(async (c) => (await api.get<{ data: Record<string, RowNote> }>('/api/record-notes', { params: { entity, keys: c.join(',') } })).data.data),
      )
      return Object.assign({}, ...parts) as Record<string, RowNote>
    },
  })
}

export function NoteCell({ entity, rowKey, note }: { entity: string; rowKey: string | number; note?: RowNote }) {
  const qc = useQueryClient()
  const [editing, setEditing] = useState(false)
  const [text, setText] = useState('')
  const save = useMutation({
    mutationFn: async (value: string) => (await api.put('/api/record-notes', { entity, key: String(rowKey), note: value })).data,
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['record-notes', entity] })
      setEditing(false)
    },
  })

  if (editing) {
    return (
      // clicks inside the editor must not open the row
      <div className="w-60 space-y-1" onClick={(e) => e.stopPropagation()}>
        <Textarea autoFocus rows={3} value={text} maxLength={2000} className="text-xs"
          onChange={(e) => setText(e.target.value)}
          onKeyDown={(e) => {
            if (e.key === 'Escape') setEditing(false)
            if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) save.mutate(text)
          }} />
        {save.isError && <p className="text-xs text-destructive">{apiErrorMessage(save.error)}</p>}
        <div className="flex justify-end gap-1">
          <button type="button" className="rounded p-1 text-muted-foreground hover:bg-muted" onClick={() => setEditing(false)} aria-label="Batal">
            <X className="size-4" />
          </button>
          <button type="button" className="rounded p-1 text-primary hover:bg-muted" disabled={save.isPending} onClick={() => save.mutate(text)} aria-label="Simpan catatan">
            {save.isPending ? <Loader2 className="size-4 animate-spin" /> : <Check className="size-4" />}
          </button>
        </div>
      </div>
    )
  }

  const open = (e: React.MouseEvent) => {
    e.stopPropagation()
    setText(note?.note ?? '')
    setEditing(true)
  }
  if (!note) {
    return (
      <button type="button" onClick={open} title="Tambah catatan"
        className="inline-flex items-center gap-1 rounded px-1 text-xs text-muted-foreground/70 hover:bg-muted hover:text-foreground">
        <MessageSquarePlus className="size-3.5" /> Catatan
      </button>
    )
  }
  return (
    <button type="button" onClick={open} className="group block w-56 text-left"
 title={`${note.note}${note.by ? `\n${note.by}` : ''}${note.at ? `, ${new Date(note.at).toLocaleString('id-ID')}` : ''}`}>
      <span className="line-clamp-2 whitespace-pre-line text-xs">{note.note}</span>
      <span className="flex items-center gap-1 text-[11px] text-muted-foreground">
        {note.by ?? ''} <Pencil className="size-3 opacity-0 group-hover:opacity-100" />
      </span>
    </button>
  )
}
