import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { useAuth } from '@/auth/AuthContext'
import { useSetKembaliBekas, type Npbg } from '@/features/npbg/api'
import { Badge } from '@/components/ui/badge'
import { cn } from '@/lib/utils'

/** Read-only "Kembali bekas" note for the NPBG list: ticked lines show where their return stands. */
export function KembaliBekasLabel({ npbg }: { npbg: Pick<Npbg, 'kembali_bekas' | 'kembali_bekas_status'> }) {
  if (!npbg.kembali_bekas) return <span className="text-muted-foreground">—</span>
  const done = npbg.kembali_bekas_status === 'CLEAR'
  return (
    <Badge variant={done ? 'success' : 'warning'} title="Masuk Tracking Pengembalian Bekas">
      {done ? 'Bekas sudah kembali' : 'Bekas belum kembali'}
    </Badge>
  )
}

/**
 * "Kembali Bekas" tick of one NPBG line: ticked = the line goes into Tracking
 * Pengembalian Bekas (sheet "Spare Part Lain"), PENDING until the RI back comes
 * in. Unticking is refused by the server once a return was recorded there.
 */
export function KembaliBekasToggle({ npbg, showLink = false }: { npbg: Pick<Npbg, 'id' | 'kembali_bekas' | 'kembali_bekas_status' | 'no_npbg'>; showLink?: boolean }) {
  const { hasPermission } = useAuth()
  const set = useSetKembaliBekas()
  const canEdit = hasPermission('used_return.update')
  const busy = set.isPending
  // What the server answered last: the list keeps several loaded pages and only the
  // current one refetches, so a row on an earlier page would otherwise show the old tick.
  const [saved, setSaved] = useState<{ kembali_bekas: boolean; kembali_bekas_status: string | null } | null>(null)
  useEffect(() => setSaved(null), [npbg.id, npbg.kembali_bekas, npbg.kembali_bekas_status])
  const current = saved ?? npbg
  const status = current.kembali_bekas_status
  // shows the click right away, before the server answers
  const checked = busy && set.variables ? set.variables.checked : current.kembali_bekas

  return (
    <span className="inline-flex items-center gap-2 whitespace-nowrap" onClick={(e) => e.stopPropagation()}>
      <label className={cn('inline-flex items-center gap-1.5 text-sm', canEdit && !busy ? 'cursor-pointer' : 'cursor-not-allowed opacity-70')}
        title={canEdit ? 'Centang bila barang bekasnya harus dikembalikan — masuk ke Tracking Pengembalian Bekas' : 'Tidak punya akses ubah Pengembalian Bekas'}>
        <input
          type="checkbox"
          className="size-4 accent-primary"
          aria-label="Kembali bekas"
          checked={checked}
          disabled={!canEdit || busy}
          onChange={(e) => set.mutate({ id: npbg.id, checked: e.target.checked }, { onSuccess: setSaved })}
        />
        {showLink && <span>Kembali bekas</span>}
      </label>
      {status && !busy && (
        <Badge variant={status === 'CLEAR' ? 'success' : 'warning'}>{status === 'CLEAR' ? 'Sudah kembali' : status === 'PENDING' ? 'Belum kembali' : status}</Badge>
      )}
      {showLink && current.kembali_bekas && !busy && hasPermission('used_return.view') && (
        <Link to={`/used-returns?sheet=other&search=${encodeURIComponent(npbg.no_npbg ?? '')}`} className="text-xs text-primary hover:underline">
          Lihat di Pengembalian Bekas
        </Link>
      )}
    </span>
  )
}
