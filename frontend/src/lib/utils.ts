import { clsx, type ClassValue } from 'clsx'
import { twMerge } from 'tailwind-merge'

export function cn(...inputs: ClassValue[]) {
  return twMerge(clsx(inputs))
}

/**
 * Copy text; also on plain http (the LAN address), where the browser blocks navigator.clipboard.
 * Returns whether it worked.
 */
export async function copyText(text: string): Promise<boolean> {
  try {
    if (navigator.clipboard && window.isSecureContext) {
      await navigator.clipboard.writeText(text)
      return true
    }
  } catch {
    // falls back below
  }
  const t = document.createElement('textarea')
  t.value = text
  t.setAttribute('readonly', '')
  t.style.position = 'fixed'
  t.style.opacity = '0'
  document.body.appendChild(t)
  t.select()
  let ok = false
  try { ok = document.execCommand('copy') } catch { ok = false }
  t.remove()
  return ok
}
