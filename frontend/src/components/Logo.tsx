import logo from '@/assets/logo.png'
import { cn } from '@/lib/utils'

export function Logo({ className, withText = true }: { className?: string; withText?: boolean }) {
  return (
    <span className={cn('inline-flex items-center gap-2', className)}>
      <img src={logo} alt="STOCKWISE" className="h-7 w-7 object-contain" />
      {withText && <span className="font-semibold tracking-tight">STOCKWISE</span>}
    </span>
  )
}
