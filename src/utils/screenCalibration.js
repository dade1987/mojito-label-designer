const STORAGE_KEY = 'mojito-screen-pxpermm'

/**
 * Default: riferimento CSS (1in = 96px → 96/25.4 px per mm). È esatto solo su
 * schermi ~96 DPI; su monitor ad alta densità la dimensione reale va calibrata
 * col righello/carta, perché il browser non conosce i DPI fisici del monitor.
 */
export const DEFAULT_PX_PER_MM = 96 / 25.4

export function getScreenPxPerMm() {
  try {
    const raw = localStorage.getItem(STORAGE_KEY)
    if (raw === null) return DEFAULT_PX_PER_MM

    const value = Number.parseFloat(raw)
    return Number.isFinite(value) && value > 0 ? value : DEFAULT_PX_PER_MM
  } catch {
    return DEFAULT_PX_PER_MM
  }
}

export function setScreenPxPerMm(value) {
  const parsed = Number(value)
  if (!Number.isFinite(parsed) || parsed <= 0) return false

  try {
    localStorage.setItem(STORAGE_KEY, String(parsed))
    return true
  } catch {
    return false
  }
}

export function resetScreenCalibration() {
  try {
    localStorage.removeItem(STORAGE_KEY)
  } catch {
    // ignora (private mode / quota)
  }
}
