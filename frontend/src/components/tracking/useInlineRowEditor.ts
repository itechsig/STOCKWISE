import { useEffect, useRef, useState } from 'react'
import { apiErrorMessage } from '@/lib/api'

export type SaveState = 'idle' | 'dirty' | 'saving' | 'saved' | 'error'

/**
 * Generic "type directly into the row, it saves itself" editor — the same
 * pattern Kartu Stock (pages/StockCardPage.tsx) uses, generalised so every
 * tracking module's inline table can reuse it instead of a create/edit modal.
 *
 * - An existing row (`row` set) saves `saveDelay` ms after the last keystroke,
 *   and again on blur if the user left before that timer fired.
 * - A new row (`row` null) only saves once it `isValid` AND the user leaves —
 *   nothing is sent while it's still being typed or incomplete.
 */
export function useInlineRowEditor<TRow, TForm>({
  row, fromRow, emptyForm, isSame, invalid, save, onCreated, saveDelay = 900,
}: {
  row: TRow | null
  fromRow: (row: TRow) => TForm
  emptyForm: TForm
  isSame: (a: TForm, b: TForm) => boolean
  /** Why the form can't be saved yet (null = ok). */
  invalid: (form: TForm) => string | null
  save: (form: TForm) => Promise<unknown>
  onCreated?: (result: unknown) => void
  saveDelay?: number
}) {
  const [form, setForm] = useState<TForm>(() => (row ? fromRow(row) : emptyForm))
  const [state, setState] = useState<SaveState>('idle')
  const [error, setError] = useState<string | null>(null)
  const timer = useRef<ReturnType<typeof setTimeout> | undefined>(undefined)
  const latest = useRef(form)
  latest.current = form

  // the server's version (after any save) replaces the form unless the user is typing
  useEffect(() => {
    if (row && state !== 'dirty' && state !== 'saving') setForm(fromRow(row))
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [row, state])

  async function flush() {
    clearTimeout(timer.current)
    const f = latest.current
    if (row && isSame(f, fromRow(row))) {
      setState('idle')
      return
    }
    const why = invalid(f)
    if (why) {
      setState('dirty')
      return
    }
    setState('saving')
    setError(null)
    try {
      const result = await save(f)
      if (!row) {
        setForm(emptyForm)
        onCreated?.(result)
      } else if (!isSame(latest.current, f)) {
        // typed on while it was saving: keep the newer text and save it too
        setState('dirty')
        timer.current = setTimeout(() => void flush(), saveDelay)
        return
      }
      setState('saved')
      setTimeout(() => setState((s) => (s === 'saved' ? 'idle' : s)), 2000)
    } catch (e) {
      setState('error')
      setError(apiErrorMessage(e))
    }
  }

  function change<K extends keyof TForm>(k: K, v: TForm[K]) {
    setForm((f) => ({ ...f, [k]: v }))
    setState('dirty')
    clearTimeout(timer.current)
    if (row) timer.current = setTimeout(() => void flush(), saveDelay) // existing row: save while typing pauses
  }

  useEffect(() => () => clearTimeout(timer.current), [])
  const hint = state === 'dirty' ? invalid(form) : null
  return { form, change, flush, state, error, hint, setForm }
}
