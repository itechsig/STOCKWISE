import { AlertTriangle } from 'lucide-react'
import type { ItemRequest, RequestForm } from '@/features/itemRequests/api'
import { Badge } from '@/components/ui/badge'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'

/** The field a form asks besides the items (e.g. "Untuk unit / mesin / lokasi"); urgency is admin gudang's call, never asked here. */
export function FormExtraFields({ form, detail, onDetail, idPrefix = 'rq' }: {
  form: RequestForm
  detail: string
  onDetail: (v: string) => void
  idPrefix?: string
}) {
  if (!form.detail_label) return null
  return (
    <div className="space-y-1.5">
      <Label htmlFor={`${idPrefix}-detail`}>{form.detail_label}</Label>
      <Input id={`${idPrefix}-detail`} value={detail} maxLength={200} onChange={(e) => onDetail(e.target.value)} />
    </div>
  )
}

/** Which form a request came from, with an Urgent mark for urgent ATK. */
export function FormBadge({ request }: { request: Pick<ItemRequest, 'form_label' | 'form_type' | 'urgency'> }) {
  return (
    <span className="inline-flex flex-wrap items-center gap-1">
      <Badge variant="neutral">{request.form_label}</Badge>
      {request.urgency === 'URGENT' && <Badge variant="danger"><AlertTriangle className="mr-1 size-3" />Urgent</Badge>}
    </span>
  )
}
