import { useEffect, useRef, useState } from 'react'
import { Download, Info } from 'lucide-react'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'

function slugify(s: string): string {
  const v = s.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '')
  return v || 'grafik'
}

/** Serialises an on-screen <svg> (a Recharts chart) to a standalone PNG and downloads it. */
export async function downloadSvgAsPng(svg: SVGSVGElement, filename: string): Promise<void> {
  const rect = svg.getBoundingClientRect()
  const width = Math.ceil(rect.width) || Number(svg.getAttribute('width')) || 600
  const height = Math.ceil(rect.height) || Number(svg.getAttribute('height')) || 400

  const clone = svg.cloneNode(true) as SVGSVGElement
  clone.setAttribute('xmlns', 'http://www.w3.org/2000/svg')
  clone.setAttribute('width', String(width))
  clone.setAttribute('height', String(height))

  const svgUrl = URL.createObjectURL(new Blob([new XMLSerializer().serializeToString(clone)], { type: 'image/svg+xml;charset=utf-8' }))
  try {
    const img = new Image()
    await new Promise<void>((resolve, reject) => {
      img.onload = () => resolve()
      img.onerror = () => reject(new Error('Gagal memuat grafik untuk diunduh.'))
      img.src = svgUrl
    })

    const scale = 2 // sharper than the on-screen size
    const canvas = document.createElement('canvas')
    canvas.width = width * scale
    canvas.height = height * scale
    const ctx = canvas.getContext('2d')
    if (!ctx) return
 ctx.fillStyle = '#ffffff' // charts have no background of their own white, same as print
    ctx.fillRect(0, 0, canvas.width, canvas.height)
    ctx.scale(scale, scale)
    ctx.drawImage(img, 0, 0, width, height)

    const blob: Blob | null = await new Promise((resolve) => canvas.toBlob(resolve, 'image/png'))
    if (!blob) return
    const url = URL.createObjectURL(blob)
    const a = document.createElement('a')
    a.href = url
    a.download = `${filename}.png`
    document.body.appendChild(a)
    a.click()
    a.remove()
    setTimeout(() => URL.revokeObjectURL(url), 10_000)
  } finally {
    URL.revokeObjectURL(svgUrl)
  }
}

/**
 * Card wrapper for a dashboard chart/list. When its content turns out to hold
 * an actual chart (an <svg> from Recharts), a small download button appears
 * next to the title — "download satu-satu" per chart, as PNG. Cards that only
 * hold a list/table (no <svg>) just don't show the button.
 */
export function ChartCard({
  title, subtitle, empty, children, className,
}: { title: React.ReactNode; subtitle?: string; empty?: boolean; children: React.ReactNode; className?: string }) {
  const ref = useRef<HTMLDivElement>(null)
  const [hasChart, setHasChart] = useState(false)

  useEffect(() => {
    setHasChart(!empty && !!ref.current?.querySelector('svg'))
  }, [children, empty])

  function download() {
    const svg = ref.current?.querySelector('svg')
    if (svg) void downloadSvgAsPng(svg as unknown as SVGSVGElement, slugify(typeof title === 'string' ? title : 'grafik'))
  }

  return (
    <Card className={className}>
      <CardHeader className="flex flex-row items-start justify-between gap-2 pb-2 space-y-0">
        <div>
          <CardTitle className="text-base">{title}</CardTitle>
          {subtitle && <p className="text-xs text-muted-foreground">{subtitle}</p>}
        </div>
        {hasChart && (
          <button
            type="button"
            title="Unduh grafik (PNG)"
            onClick={download}
            className="shrink-0 rounded p-1 text-muted-foreground hover:bg-muted hover:text-primary print:hidden"
          >
            <Download className="size-4" />
          </button>
        )}
      </CardHeader>
      <CardContent ref={ref}>
        {empty ? (
          <div className="flex h-[180px] flex-col items-center justify-center gap-2 text-sm text-muted-foreground">
            <Info className="size-5" />
            Tidak ada data untuk ditampilkan.
          </div>
        ) : children}
      </CardContent>
    </Card>
  )
}
