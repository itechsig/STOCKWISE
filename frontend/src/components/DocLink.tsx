import { Fragment, type ReactNode } from 'react'
import { Link } from 'react-router-dom'
import { cn } from '@/lib/utils'

/**
 * Accurate document numbers and where to open them (list page filtered on
 * that number, so every line of the document shows):
 *   RI/NV/26/IX/039 → RI · NA/26/IX/054, ATK/… → NPBG · PPB/NA/… → PPB · BL/D/… → PO
 */
const DOC_PATTERN = /\b(RI\/[A-Z]+\/\d{2}\/[IVX]+\/\d{3}|PPB\/[A-Z]+\/\d{2}\/[IVX]+\/\d{3}|BL\/[A-Z]+\/\d{2}\/[IVX]+\/\d{3}|(?:NA|ATK)\/\d{2}\/[IVX]+\/\d{3})\b/g

export function docHref(no: string): string | null {
  const n = no.trim().toUpperCase()
  const q = `?search=${encodeURIComponent(n)}`
  if (/^RI\//.test(n)) return `/ri${q}`
  if (/^PPB\//.test(n)) return `/ppb${q}`
  if (/^BL\//.test(n)) return `/purchase-orders${q}`
  if (/^(NA|ATK)\/\d{2}\//.test(n)) return `/npbg${q}`
  return null
}

/** One document number as a link (plain text when it isn't a known document). */
export function DocLink({ no, className }: { no: string | null | undefined; className?: string }) {
  if (!no) return <span className="text-muted-foreground">—</span>
  const href = docHref(no)
  if (!href) return <span className={cn('font-mono', className)}>{no}</span>
  return (
    <Link
      to={href}
      onClick={(e) => e.stopPropagation()}
      className={cn('font-mono text-primary underline-offset-2 hover:underline', className)}
      title={`Buka ${no}`}
    >
      {no}
    </Link>
  )
}

/** Free text (keterangan, "RI/…, RI/…" lists) with every document number inside turned into a link. */
export function DocText({ text, className }: { text: string | null | undefined; className?: string }) {
  if (!text) return <span className="text-muted-foreground">—</span>
  const parts = text.split(DOC_PATTERN)
  return (
    <span className={className}>
      {parts.map((p, i) => (i % 2 === 1 ? <DocLink key={i} no={p} /> : <Fragment key={i}>{p}</Fragment>))}
    </span>
  )
}

/**
 * A document number with its date underneath, as one compact table cell —
 * pairs "Nomor X" + "Tanggal X" into a single column instead of two, so wide
 * document-chain tables (PO/PPB/RI) read cleaner without losing either value.
 */
export function DocCell({ children, date }: { children: ReactNode; date?: string | null }) {
  return (
    <div className="leading-tight">
      <div className="whitespace-nowrap">{children}</div>
      {date && <div className="whitespace-nowrap text-[11px] text-muted-foreground">{date}</div>}
    </div>
  )
}
