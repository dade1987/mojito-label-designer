import {
  computeBarcodeStyle,
  formatDisplayText,
} from './canvasDisplay.js'

export function estimateTextWidth(text, fontHeight = 30) {
  return Math.max(20, String(text).length * (fontHeight * 0.55))
}

export function getElementBounds(element, displayValues) {
  const x = element.x ?? 0
  const y = element.y ?? 0

  if (element.type === 'text') {
    const text = formatDisplayText(element, displayValues) || 'Text'
    const fontHeight = element.fontHeight ?? 30

    return {
      x,
      y,
      width: estimateTextWidth(text, fontHeight),
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

  return { x, y, width: 40, height: 40 }
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
