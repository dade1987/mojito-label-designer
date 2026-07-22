import {
  TEXT_ADVANCE_RATIO,
  computeBarcodeStyle,
  formatDisplayText,
} from './canvasDisplay.js'

export function estimateTextWidth(text, fontHeight = 30, fontWidth = fontHeight) {
  return Math.max(20, String(text).length * (fontWidth * TEXT_ADVANCE_RATIO))
}

export function qrPlaceholderSize(element) {
  const magnification = Math.max(1, Math.min(10, element.magnification ?? 4))

  return magnification * 25
}

export function getElementBounds(element, displayValues) {
  const x = element.x ?? 0
  const y = element.y ?? 0

  if (element.type === 'text') {
    const text = formatDisplayText(element, displayValues) || 'Text'
    const fontHeight = element.fontHeight ?? 30
    const fontWidth = element.fontWidth ?? fontHeight

    return {
      x,
      y,
      width: estimateTextWidth(text, fontHeight, fontWidth),
      height: fontHeight + 8,
    }
  }

  if (element.type === 'barcode') {
    const style = computeBarcodeStyle(element, displayValues, 1)

    return {
      x,
      y,
      width: Number.parseFloat(style.width) || 80,
      height: Number.parseFloat(style.height) || 100,
    }
  }

  if (element.type === 'image') {
    return {
      x,
      y,
      width: element.width ?? 80,
      height: element.height ?? 80,
    }
  }

  if (element.type === 'qr') {
    const size = qrPlaceholderSize(element)

    return { x, y, width: size, height: size }
  }

  return { x, y, width: 40, height: 40 }
}

export function pointInBounds(point, bounds) {
  return (
    point.x >= bounds.x &&
    point.x <= bounds.x + bounds.width &&
    point.y >= bounds.y &&
    point.y <= bounds.y + bounds.height
  )
}

/**
 * Id degli elementi il cui riquadro contiene il punto, in ordine di stacking
 * (ultimo = quello disegnato sopra), così com'è l'ordine di template.elements.
 */
export function elementsAtPoint(elements, displayValues, point) {
  return (elements ?? [])
    .filter((element) => pointInBounds(point, getElementBounds(element, displayValues)))
    .map((element) => element.id)
}

/**
 * Dato lo stack sotto il puntatore (ordine di stacking, ultimo = sopra) e la
 * selezione corrente, restituisce l'id dell'elemento immediatamente sotto a
 * quello corrente (con wrap in cima). Serve a raggiungere elementi coperti:
 * ogni Alt+clic scende di un livello. Se la selezione non è nello stack (o è
 * assente) parte dall'elemento in cima.
 */
export function cycleStackSelection(stackIds, currentId) {
  if (!stackIds || stackIds.length === 0) {
    return null
  }

  const top = stackIds[stackIds.length - 1]
  const index = stackIds.indexOf(currentId)

  if (index === -1) {
    return top
  }

  const belowIndex = (index - 1 + stackIds.length) % stackIds.length

  return stackIds[belowIndex]
}

export function normalizeRect(startX, startY, endX, endY) {
  const x = Math.min(startX, endX)
  const y = Math.min(startY, endY)

  return {
    x,
    y,
    width: Math.abs(endX - startX),
    height: Math.abs(endY - startY),
  }
}

export function rectsIntersect(a, b) {
  return (
    a.x < b.x + b.width &&
    a.x + a.width > b.x &&
    a.y < b.y + b.height &&
    a.y + a.height > b.y
  )
}

export function selectElementsInRect(elements, displayValues, rect) {
  return elements
    .filter((element) => rectsIntersect(rect, getElementBounds(element, displayValues)))
    .map((element) => element.id)
}
