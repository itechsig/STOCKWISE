import { useEffect, useRef, useState } from 'react'
import { Eraser, PenLine } from 'lucide-react'
import { Button } from '@/components/ui/button'

/**
 * Sign with a finger / stylus / mouse. Strokes are black on white so the saved
 * PNG reads the same in light and dark mode. onChange gets the PNG data URL,
 * or null while empty.
 */
export function SignaturePad({ onChange, height = 180 }: { onChange: (dataUrl: string | null) => void; height?: number }) {
  const canvas = useRef<HTMLCanvasElement>(null)
  const drawing = useRef(false)
  const last = useRef<{ x: number; y: number } | null>(null)
  const [empty, setEmpty] = useState(true)

  // size the bitmap to the element (sharp on high-DPI phones), white background
  useEffect(() => {
    const c = canvas.current
    if (!c) return
    const ratio = Math.max(1, window.devicePixelRatio || 1)
    c.width = Math.round(c.clientWidth * ratio)
    c.height = Math.round(height * ratio)
    const ctx = c.getContext('2d')!
    ctx.scale(ratio, ratio)
    ctx.fillStyle = '#ffffff'
    ctx.fillRect(0, 0, c.width, c.height)
    ctx.lineWidth = 2.5
    ctx.lineCap = 'round'
    ctx.lineJoin = 'round'
    ctx.strokeStyle = '#111827'
  }, [height])

  const point = (e: React.PointerEvent<HTMLCanvasElement>) => {
    const r = e.currentTarget.getBoundingClientRect()
    return { x: e.clientX - r.left, y: e.clientY - r.top }
  }

  const down = (e: React.PointerEvent<HTMLCanvasElement>) => {
    e.currentTarget.setPointerCapture(e.pointerId)
    drawing.current = true
    last.current = point(e)
    const ctx = e.currentTarget.getContext('2d')!
    ctx.beginPath()
    ctx.arc(last.current.x, last.current.y, 1.2, 0, Math.PI * 2)
    ctx.fillStyle = '#111827'
    ctx.fill()
  }

  const move = (e: React.PointerEvent<HTMLCanvasElement>) => {
    if (!drawing.current || !last.current) return
    const p = point(e)
    const ctx = e.currentTarget.getContext('2d')!
    ctx.beginPath()
    ctx.moveTo(last.current.x, last.current.y)
    ctx.lineTo(p.x, p.y)
    ctx.stroke()
    last.current = p
    if (empty) setEmpty(false)
  }

  const up = () => {
    if (!drawing.current) return
    drawing.current = false
    last.current = null
    const c = canvas.current
    if (c && !empty) onChange(c.toDataURL('image/png'))
  }

  const clear = () => {
    const c = canvas.current
    if (!c) return
    const ctx = c.getContext('2d')!
    ctx.save()
    ctx.setTransform(1, 0, 0, 1, 0, 0)
    ctx.fillStyle = '#ffffff'
    ctx.fillRect(0, 0, c.width, c.height)
    ctx.restore()
    setEmpty(true)
    onChange(null)
  }

  return (
    <div className="space-y-1.5">
      <div className="relative overflow-hidden rounded-lg border-2 border-dashed bg-white">
        <canvas ref={canvas} style={{ height }} className="block w-full touch-none cursor-crosshair"
          onPointerDown={down} onPointerMove={move} onPointerUp={up} onPointerCancel={up} onPointerLeave={up}
          aria-label="Area tanda tangan" />
        {empty && (
          <span className="pointer-events-none absolute inset-0 flex flex-col items-center justify-center gap-1 text-sm text-slate-400">
            <PenLine className="size-5" /> Tanda tangan di sini
          </span>
        )}
        <span aria-hidden className="pointer-events-none absolute inset-x-6 bottom-8 border-b border-slate-300" />
      </div>
      <div className="flex justify-end">
        <Button type="button" variant="ghost" size="sm" onClick={clear} disabled={empty}><Eraser className="size-4" /> Ulangi</Button>
      </div>
    </div>
  )
}
