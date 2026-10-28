import { useEffect, useState } from 'react'
import { AlertTriangle } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { Modal } from '@/components/ui/modal'

interface ConfirmOptions {
  title?: string
  /** the question, e.g. "Batalkan request ini?" */
  message: string
  confirmLabel?: string
  cancelLabel?: string
  /** red confirm button for deleting / cancelling */
  danger?: boolean
}

type Pending = ConfirmOptions & { resolve: (ok: boolean) => void }
let show: ((p: Pending) => void) | null = null

/**
 * The app's confirmation dialog (instead of the browser's window.confirm):
 *   if (await confirmDialog({ message: 'Hapus link ini?', danger: true })) remove()
 * Needs <ConfirmHost /> mounted once (App).
 */
export function confirmDialog(opts: ConfirmOptions | string): Promise<boolean> {
  const o = typeof opts === 'string' ? { message: opts } : opts
  return new Promise((resolve) => {
    if (!show) return resolve(window.confirm(o.message)) // not mounted (tests): the browser's own
    show({ ...o, resolve })
  })
}

/** Run `action` once the user confirms. */
export function withConfirm(opts: ConfirmOptions | string, action: () => void) {
  void confirmDialog(opts).then((ok) => { if (ok) action() })
}

export function ConfirmHost() {
  const [p, setP] = useState<Pending | null>(null)
  useEffect(() => { show = setP; return () => { show = null } }, [])
  const close = (ok: boolean) => { p?.resolve(ok); setP(null) }
  const danger = p?.danger ?? /hapus|batal|tolak|nonaktif|keluar/i.test(p?.message ?? '')

  return (
    <Modal open={!!p} onClose={() => close(false)} className="max-w-md"
      title={<span className="flex items-center gap-2">{danger && <AlertTriangle className="size-4 text-destructive" />}{p?.title ?? 'Konfirmasi'}</span>}
      footer={<>
        <Button variant="outline" size="sm" onClick={() => close(false)}>{p?.cancelLabel ?? 'Batal'}</Button>
        <Button size="sm" variant={danger ? 'destructive' : 'default'} autoFocus onClick={() => close(true)}>{p?.confirmLabel ?? 'Ya, lanjutkan'}</Button>
      </>}>
      <p className="whitespace-pre-line text-sm">{p?.message}</p>
    </Modal>
  )
}
