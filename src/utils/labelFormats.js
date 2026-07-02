import { getElementBounds } from './canvasSelection.js'

/**
 * Formati etichetta standard per bobine termiche compatibili con la famiglia
 * Citizen CL-S700 (larghezza di stampa max ~104 mm). Misure in millimetri;
 * i dots dipendono dalla risoluzione della stampante.
 */
export const LABEL_FORMATS = [
  { id: '40x15', name: '40 × 15 mm (piccole)', widthMm: 40, heightMm: 15 },
  { id: '40x30', name: '40 × 30 mm', widthMm: 40, heightMm: 30 },
  { id: '50x30', name: '50 × 30 mm', widthMm: 50, heightMm: 30 },
  { id: '50x50', name: '50 × 50 mm (batterie)', widthMm: 50, heightMm: 50 },
  { id: '58x43', name: '58 × 43 mm', widthMm: 58, heightMm: 43 },
  { id: '60x40', name: '60 × 40 mm', widthMm: 60, heightMm: 40 },
  { id: '76x51', name: '76 × 51 mm (3″ × 2″)', widthMm: 76.2, heightMm: 50.8 },
  { id: '76x76', name: '76 × 76 mm (3″ × 3″)', widthMm: 76.2, heightMm: 76.2 },
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
 * Riscala posizioni e dimensioni degli elementi di un fattore uniforme.
 * Con floorDiscrete i valori discreti che governano la larghezza (modulo
 * barcode, magnification QR) vengono arrotondati per difetto: serve quando
 * si stringe un layout, dove arrotondare per eccesso lascerebbe fuori misura.
 */
export function scaleTemplateElements(template, ratio, floorDiscrete = false) {
  const scaled = (value, fallback) => Math.round((value ?? fallback) * ratio)
  const scaledDiscrete = (value, fallback) =>
    floorDiscrete ? Math.floor((value ?? fallback) * ratio) : Math.round((value ?? fallback) * ratio)

  for (const element of template.elements ?? []) {
    element.x = scaled(element.x, 0)
    element.y = scaled(element.y, 0)

    if (element.type === 'text') {
      element.fontHeight = Math.max(10, scaled(element.fontHeight, 30))
      element.fontWidth = Math.max(10, scaled(element.fontWidth, 30))
    } else if (element.type === 'barcode') {
      element.height = Math.max(20, scaled(element.height, 100))
      element.moduleWidth = Math.max(1, scaledDiscrete(element.moduleWidth, 2))
    } else if (element.type === 'qr') {
      // ^BQ accetta magnification 1-10 (vedi ZplBuilder).
      element.magnification = Math.min(10, Math.max(1, scaledDiscrete(element.magnification, 4)))
    } else if (element.type === 'image') {
      element.width = Math.max(8, scaled(element.width, 80))
      element.height = Math.max(8, scaled(element.height, 80))
    }
  }
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
  scaleTemplateElements(template, ratio)
  template.dpi = newDpi

  return true
}

/**
 * Riscala gli elementi che debordano perché rientrino nelle dimensioni
 * correnti dell'etichetta. Ritorna false se il layout è già tutto dentro.
 */
export function fitElementsToLabel(template, displayValues = {}) {
  const labelWidth = template.labelWidth ?? 600
  const labelHeight = template.labelHeight ?? 400

  let maxX = 0
  let maxY = 0

  for (const element of template.elements ?? []) {
    const bounds = getElementBounds(element, displayValues)
    maxX = Math.max(maxX, bounds.x + bounds.width)
    maxY = Math.max(maxY, bounds.y + bounds.height)
  }

  if (maxX <= 0 || maxY <= 0) {
    return false
  }

  const ratio = Math.min(labelWidth / maxX, labelHeight / maxY)

  if (ratio >= 1) {
    return false
  }

  scaleTemplateElements(template, ratio, true)

  return true
}

/**
 * Adatta il layout a un nuovo formato: riscala gli elementi in proporzione
 * (fattore unico, per non deformare) e imposta le nuove dimensioni.
 */
export function fitTemplateToSize(template, widthDots, heightDots) {
  const oldWidth = template.labelWidth ?? 600
  const oldHeight = template.labelHeight ?? 400

  if (!widthDots || !heightDots || widthDots <= 0 || heightDots <= 0 || oldWidth <= 0 || oldHeight <= 0) {
    return false
  }

  const ratio = Math.min(widthDots / oldWidth, heightDots / oldHeight)

  if (ratio !== 1) {
    scaleTemplateElements(template, ratio)
  }

  template.labelWidth = widthDots
  template.labelHeight = heightDots

  return true
}
