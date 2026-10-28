import { cva, type VariantProps } from 'class-variance-authority'
import type { HTMLAttributes } from 'react'
import { cn } from '@/lib/utils'

const badgeVariants = cva(
  // soft status chip: tinted fill + hairline ring, the same five meanings everywhere
  'inline-flex items-center whitespace-nowrap rounded-md px-2 py-0.5 text-xs font-medium ring-1 ring-inset',
  {
    variants: {
      variant: {
        default: 'bg-blue-50 text-blue-700 ring-blue-200',
        neutral: 'bg-slate-100 text-slate-700 ring-slate-200',
        success: 'bg-emerald-50 text-emerald-700 ring-emerald-200',
        warning: 'bg-amber-50 text-amber-800 ring-amber-200',
        danger: 'bg-red-50 text-red-700 ring-red-200',
      },
    },
    defaultVariants: { variant: 'default' },
  },
)

export function Badge({
  className,
  variant,
  ...props
}: HTMLAttributes<HTMLSpanElement> & VariantProps<typeof badgeVariants>) {
  return <span className={cn(badgeVariants({ variant }), className)} {...props} />
}

export function StatusBadge({ status }: { status: string }) {
  const map: Record<string, { label: string; variant: 'success' | 'danger' | 'neutral' }> = {
    AMAN: { label: 'Aman', variant: 'success' },
    TIDAK_AMAN: { label: 'Tidak Aman', variant: 'danger' },
    BEP: { label: 'Stok Habis', variant: 'neutral' },
  }
  const s = map[status] ?? { label: status, variant: 'neutral' as const }
  return <Badge variant={s.variant}>{s.label}</Badge>
}

export function PriorityBadge({ level }: { level: string }) {
  const map: Record<string, 'danger' | 'warning' | 'neutral'> = {
    HIGH: 'danger',
    MEDIUM: 'warning',
    LOW: 'neutral',
  }
  return <Badge variant={map[level] ?? 'neutral'}>{level}</Badge>
}
