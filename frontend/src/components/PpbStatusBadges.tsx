import { Badge } from '@/components/ui/badge'
import { PPB_AMEND, PPB_LINE_STATUS, type Ppb } from '@/features/ppb/api'

/** Status of a PPB line: Amend (when it is) + the status Accurate holds — e.g. "Amend · Requested". */
export function PpbStatusBadges({ ppb, withHint }: { ppb: Pick<Ppb, 'line_status' | 'is_amend' | 'amend_source'>; withHint?: boolean }) {
  const s = ppb.line_status ? PPB_LINE_STATUS[ppb.line_status] : undefined
  const amendHint = ppb.amend_source ? PPB_AMEND.hint[ppb.amend_source] : undefined
  if (!ppb.line_status && !ppb.is_amend) return <span className="text-muted-foreground">—</span>
  return (
    <div className="space-y-1">
      <div className="flex flex-wrap items-center gap-1">
        {ppb.is_amend && <Badge variant="danger" title={amendHint}>{PPB_AMEND.label}</Badge>}
        {ppb.line_status && <Badge variant={s?.variant ?? 'neutral'} title={s?.hint}>{s?.label ?? ppb.line_status}</Badge>}
      </div>
      {withHint && (
        <div className="max-w-[260px] space-y-0.5 text-xs text-muted-foreground">
          {ppb.is_amend && amendHint && <p>Amend: {amendHint}</p>}
          {s && <p>{s.label}: {s.hint}</p>}
        </div>
      )}
    </div>
  )
}
