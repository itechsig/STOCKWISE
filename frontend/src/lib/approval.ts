/** A Manager step the Direktur approved for them (bypass): its log note says so. */
export const isAutoApproval = (note?: string | null) => !!note && note.startsWith('Disetujui otomatis')

export const BYPASS_TEXT = 'Manajer: disetujui otomatis (bypass Direktur)'

const STEP_TEXT: Record<string, string> = {
  DIAJUKAN: 'Diajukan',
  APPROVE_MANAGER: 'Disetujui Manajer',
  APPROVE: 'Disetujui Direktur',
  REJECT: 'Ditolak',
  RETURN: 'Dikembalikan untuk revisi',
  DIAJUKAN_ULANG: 'Diajukan ulang (sudah direvisi)',
  PO_TANPA_APPROVAL: 'PO dibuat tanpa approval',
  DIBELI: 'Sudah dibeli',
}

/**
 * One approval log entry in plain words (PPB / NPBG / PO: action only; Request Barang: stage + action),
 * the bypassed Manager step said clearly.
 */
export function stepText(action: string, note?: string | null, stage?: string | null): string {
  if ((action === 'APPROVE_MANAGER' || (stage === 'MANAGER' && action === 'APPROVE')) && isAutoApproval(note)) return BYPASS_TEXT
  if (stage === 'MANAGER' && action === 'APPROVE') return 'Disetujui Manajer'
  return STEP_TEXT[action] ?? action
}
