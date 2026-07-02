/**
 * Formati etichetta standard per bobine termiche compatibili con la famiglia
 * Citizen CL-S700 (larghezza di stampa max ~104 mm). Misure in millimetri;
 * i dots dipendono dalla risoluzione della stampante.
 */
export const LABEL_FORMATS = [
  { id: '40x30', name: '40 × 30 mm', widthMm: 40, heightMm: 30 },
  { id: '50x30', name: '50 × 30 mm', widthMm: 50, heightMm: 30 },
  { id: '58x43', name: '58 × 43 mm', widthMm: 58, heightMm: 43 },
  { id: '60x40', name: '60 × 40 mm', widthMm: 60, heightMm: 40 },
  { id: '76x51', name: '76 × 51 mm (3″ × 2″)', widthMm: 76.2, heightMm: 50.8 },
  { id: '100x50', name: '100 × 50 mm', widthMm: 100, heightMm: 50 },
  { id: '102x51', name: '102 × 51 mm (4″ × 2″)', widthMm: 101.6, heightMm: 50.8 },
  { id: '102x76', name: '102 × 76 mm (4″ × 3″)', widthMm: 101.6, heightMm: 76.2 },
  { id: '100x100', name: '100 × 100 mm', widthMm: 100, heightMm: 100 },
  { id: '102x152', name: '102 × 152 mm (4″ × 6″ spedizioni)', widthMm: 101.6, heightMm: 152.4 },
]

/** CL-S700 stampa a 203 dpi, CL-S703 a 300 dpi. */
export const PRINTER_RESOLUTIONS = [
  { dpi: 203, label: '203 dpi (CL-S700)' },
  { dpi: 300, label: '300 dpi (CL-S703)' },
]

export const CUSTOM_FORMAT_ID = 'custom'

export function mmToDots(mm, dpi) {
  return Math.round((mm * dpi) / 25.4)
}

export function dotsToMm(dots, dpi) {
  if (!dpi) return 0
  return Math.round(((dots * 25.4) / dpi) * 10) / 10
}

export function findFormat(id) {
  return LABEL_FORMATS.find((format) => format.id === id) ?? null
}

/** Riconosce il formato corrente dai dots (tolleranza ±2 per arrotondamenti). */
export function detectFormat(template) {
  const dpi = template.dpi ?? 203

  for (const format of LABEL_FORMATS) {
    const widthMatches = Math.abs(mmToDots(format.widthMm, dpi) - (template.labelWidth ?? 0)) <= 2
    const heightMatches = Math.abs(mmToDots(format.heightMm, dpi) - (template.labelHeight ?? 0)) <= 2

    if (widthMatches && heightMatches) {
      return format.id
    }
  }

  return CUSTOM_FORMAT_ID
}

export function applyFormat(template, formatId) {
  const format = findFormat(formatId)

  if (!format) {
    return false
  }

  const dpi = template.dpi ?? 203
  template.labelWidth = mmToDots(format.widthMm, dpi)
  template.labelHeight = mmToDots(format.heightMm, dpi)

  return true
}

/**
 * Cambia la risoluzione mantenendo le misure fisiche: riscala dimensioni
 * etichetta, posizioni e dimensioni degli elementi.
 */
export function rescaleTemplateForDpi(template, newDpi) {
  const oldDpi = template.dpi ?? 203

  if (!newDpi || newDpi <= 0 || newDpi === oldDpi) {
    return false
  }

  const ratio = newDpi / oldDpi
  const scaled = (value, fallback) => Math.round((value ?? fallback) * ratio)

  template.labelWidth = scaled(template.labelWidth, 600)
  template.labelHeight = scaled(template.labelHeight, 400)

  for (const element of template.elements ?? []) {
    element.x = scaled(element.x, 0)
    element.y = scaled(element.y, 0)

    if (element.type === 'text') {
      element.fontHeight = Math.max(10, scaled(element.fontHeight, 30))
      element.fontWidth = Math.max(10, scaled(element.fontWidth, 30))
    } else if (element.type === 'barcode') {
      element.height = Math.max(20, scaled(element.height, 100))
      element.moduleWidth = Math.max(1, scaled(element.moduleWidth, 2))
    } else if (element.type === 'qr') {
      // ^BQ accetta magnification 1-10 (vedi ZplBuilder).
      element.magnification = Math.min(10, Math.max(1, scaled(element.magnification, 4)))
    } else if (element.type === 'image') {
      element.width = Math.max(8, scaled(element.width, 80))
      element.height = Math.max(8, scaled(element.height, 80))
    }
  }

  template.dpi = newDpi

  return true
}
