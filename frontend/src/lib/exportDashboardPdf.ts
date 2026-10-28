import logoUrl from '@/assets/logo.png'

async function loadImageDataUrl(url: string): Promise<string> {
  const res = await fetch(url)
  const blob = await res.blob()
  return new Promise((resolve, reject) => {
    const reader = new FileReader()
    reader.onload = () => resolve(reader.result as string)
    reader.onerror = reject
    reader.readAsDataURL(blob)
  })
}

interface Block {
  top: number
  bottom: number
}

/**
 * Cards/charts (`.card-surface`, `[data-pdf-block]` — see index.css and
 * components/ui/card.tsx) that a naive fixed-height slice would otherwise cut
 * straight through. Measured in canvas px, relative to `root`'s own top.
 */
function measureBlocks(root: HTMLElement, canvasScale: number): Block[] {
  const rootTop = root.getBoundingClientRect().top
  return Array.from(root.querySelectorAll<HTMLElement>('.card-surface, [data-pdf-block]')).map((el) => {
    const r = el.getBoundingClientRect()
    return { top: (r.top - rootTop) * canvasScale, bottom: (r.bottom - rootTop) * canvasScale }
  })
}

/**
 * Where a page would naturally end (`idealCut`) often falls inside a card or
 * chart. Pulls the cut back to right before that block starts instead — so
 * the whole thing moves to the next page rather than being split across two.
 * Only a block taller than a full page is left to be cut (no other option).
 */
function safeCut(blocks: Block[], pageStart: number, idealCut: number, hardMax: number): number {
  let cut = Math.min(idealCut, hardMax)
  let earliestTop = Infinity
  for (const b of blocks) {
    if (b.top > pageStart && b.top < cut && b.bottom > cut) {
      earliestTop = Math.min(earliestTop, b.top)
    }
  }
  return earliestTop === Infinity ? cut : earliestTop
}

/**
 * Captures `element` and saves it as a real downloadable PDF (not the browser
 * print dialog) — a cover header with the SIG logo and a centered title comes
 * first, then the captured content, paginated across as many A4 pages as
 * needed without cutting a card/chart in half (see safeCut). Anything with
 * the existing `print:hidden` class (filters, action buttons) is skipped too.
 */
export async function exportElementToPdf(
  element: HTMLElement,
  options: { title: string; subtitle: string; filename: string },
): Promise<void> {
  const [{ default: html2canvas }, { jsPDF }] = await Promise.all([import('html2canvas'), import('jspdf')])

  const scale = 2
  const canvas = await html2canvas(element, {
    scale,
    backgroundColor: '#ffffff',
    useCORS: true,
    ignoreElements: (node) => node.classList?.contains('print:hidden'),
  })
  const blocks = measureBlocks(element, scale)

  const doc = new jsPDF({ unit: 'mm', format: 'a4' })
  const pageW = doc.internal.pageSize.getWidth()
  const pageH = doc.internal.pageSize.getHeight()
  const margin = 12
  const contentW = pageW - margin * 2

  // ---- cover header: logo + centered title (page 1 only) ----
  let y = margin
  try {
    const logoData = await loadImageDataUrl(logoUrl)
    doc.addImage(logoData, 'PNG', margin, y, 16, 16)
  } catch {
    // logo optional — a missing/blocked asset shouldn't stop the export
  }
  doc.setFont('helvetica', 'bold')
  doc.setFontSize(15)
  doc.setTextColor(20, 20, 20)
  doc.text(options.title.toUpperCase(), pageW / 2, y + 7, { align: 'center' })
  doc.setFont('helvetica', 'normal')
  doc.setFontSize(11)
  doc.text(options.subtitle, pageW / 2, y + 13, { align: 'center' })
  doc.setFontSize(8.5)
  doc.setTextColor(120, 120, 120)
  doc.text(
    `Dicetak ${new Date().toLocaleString('id-ID', { dateStyle: 'long', timeStyle: 'short' })}`,
    pageW / 2,
    y + 18,
    { align: 'center' },
  )
  y += 23
  doc.setDrawColor(210, 210, 210)
  doc.line(margin, y, pageW - margin, y)
  y += 6

  // ---- captured content, sliced across pages at safe boundaries ----
  const pxPerMm = canvas.width / contentW
  let srcY = 0
  let firstPage = true
  while (srcY < canvas.height) {
    const availMm = firstPage ? pageH - margin - y : pageH - margin * 2
    const idealCut = srcY + availMm * pxPerMm
    const cut = Math.max(srcY + 1, safeCut(blocks, srcY, idealCut, canvas.height))
    const sliceH = Math.min(canvas.height - srcY, cut - srcY)
    if (sliceH <= 0) break

    const slice = document.createElement('canvas')
    slice.width = canvas.width
    slice.height = sliceH
    slice.getContext('2d')!.drawImage(canvas, 0, srcY, canvas.width, sliceH, 0, 0, canvas.width, sliceH)

    const sliceY = firstPage ? y : margin
    doc.addImage(slice.toDataURL('image/png'), 'PNG', margin, sliceY, contentW, sliceH / pxPerMm)

    srcY += sliceH
    if (srcY < canvas.height) {
      doc.addPage()
      firstPage = false
    }
  }

  doc.save(options.filename)
}
