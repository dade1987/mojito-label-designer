import { buildValuesFromSources } from './templateStore.js'
import { getElementBounds, rectsIntersect } from './canvasSelection.js'

const LAST_SLOTS_KEY = 'mojito-last-placement-slots'
const lastSlots = loadLastSlotsFromStorage()

function loadLastSlotsFromStorage() {
  try {
    const raw = localStorage.getItem(LAST_SLOTS_KEY)
    if (!raw) return new Map()

    const parsed = JSON.parse(raw)
    return new Map(Object.entries(parsed))
  } catch {
    return new Map()
  }
}

function persistLastSlots() {
  try {
    localStorage.setItem(LAST_SLOTS_KEY, JSON.stringify(Object.fromEntries(lastSlots)))
  } catch {
    // ignore quota / private mode
  }
}

export function defaultElementSize(type) {
  switch (type) {
    case 'text':
      return { width: 120, height: 38 }
    case 'barcode':
      // Code 128 di 6 cifre a modulo 2 (~202 dots) più riga interpretazione.
      return { width: 210, height: 130 }
    case 'qr':
      // Magnification di default 4 → vedi qrPlaceholderSize() in canvasSelection.js.
      return { width: 100, height: 100 }
    case 'image':
      return { width: 80, height: 80 }
    default:
      return { width: 40, height: 40 }
  }
}

export function slotKey(templateId, type) {
  return `${templateId}:${type}`
}

export function getPreferredPlacementSlot(templateId, type) {
  return lastSlots.get(slotKey(templateId, type)) ?? null
}

export function rememberPlacementSlot(templateId, type, position) {
  lastSlots.set(slotKey(templateId, type), { x: position.x, y: position.y })
  persistLastSlots()
}

export function canPlaceAt(x, y, size, labelWidth, labelHeight, occupiedBounds, gap = 8) {
  const candidate = { x, y, width: size.width, height: size.height }

  if (x < 0 || y < 0 || x + size.width > labelWidth || y + size.height > labelHeight) {
    return false
  }

  return !occupiedBounds.some((bounds) =>
    rectsIntersect(candidate, {
      x: bounds.x - gap,
      y: bounds.y - gap,
      width: bounds.width + gap * 2,
      height: bounds.height + gap * 2,
    })
  )
}

export function findEmptyPlacement(template, type, displayValues = {}, preferred = null) {
  const labelWidth = template.labelWidth ?? 600
  const labelHeight = template.labelHeight ?? 400
  const size = defaultElementSize(type)
  const step = 20
  const margin = 12
  const occupied = (template.elements ?? []).map((element) =>
    getElementBounds(element, displayValues)
  )

  const tryPoint = (x, y) =>
    canPlaceAt(x, y, size, labelWidth, labelHeight, occupied)

  if (preferred && tryPoint(preferred.x, preferred.y)) {
    return { x: preferred.x, y: preferred.y }
  }

  for (let y = margin; y + size.height <= labelHeight - margin; y += step) {
    for (let x = margin; x + size.width <= labelWidth - margin; x += step) {
      if (tryPoint(x, y)) {
        return { x, y }
      }
    }
  }

  return {
    x: Math.max(0, labelWidth - size.width - margin),
    y: Math.max(0, labelHeight - size.height - margin),
  }
}

export function placeNewElement(template, element, displayValues = {}) {
  const preferred = getPreferredPlacementSlot(template.id ?? 'draft', element.type)
  const position = findEmptyPlacement(template, element.type, displayValues, preferred)
  element.x = position.x
  element.y = position.y
  rememberPlacementSlot(template.id ?? 'draft', element.type, position)

  return element
}

export function buildDisplayValuesForTemplate(template) {
  return buildValuesFromSources(template.dataSources ?? [])
}
