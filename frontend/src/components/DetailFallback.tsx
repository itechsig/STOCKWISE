import { FileQuestion } from 'lucide-react'

/**
 * What a detail page shows before it has its record: a skeleton while loading, or —
 * once loading ended without one (deleted, wrong link, no access) — a clear message
 * instead of "Memuat…" forever.
 */
export function DetailFallback({ loading }: { loading: boolean }) {
  if (loading) {
    return (
      <div className="space-y-3" aria-busy="true" aria-label="Memuat">
        <div className="h-7 w-56 animate-pulse rounded-md bg-muted" />
        <div className="h-4 w-80 max-w-full animate-pulse rounded bg-muted" />
        <div className="h-40 animate-pulse rounded-lg border bg-card" />
      </div>
    )
  }
  return (
    <div className="flex flex-col items-center gap-2 rounded-lg border bg-card px-6 py-14 text-center">
      <FileQuestion className="size-9 text-muted-foreground" />
      <p className="font-medium">Data tidak ditemukan</p>
      <p className="max-w-sm text-sm text-muted-foreground">Dokumen ini mungkin sudah dihapus, linknya salah, atau Anda tidak punya akses.</p>
    </div>
  )
}
