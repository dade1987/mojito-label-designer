import { resolveElementValue } from './templateStore.js'

export function computeScale(labelWidth, maxWidth = 700) {
  const width = labelWidth || 600
  return Math.min(1, maxWidth / width)
}

export function buildElementDisplayValues(elements, dataValues, dataSources) {
  const values = {}

  for (const element of elements ?? []) {
    values[element.id] = resolveElementValue(element, dataValues, dataSources ?? [])
  }

  return values
}

export function formatDisplayText(element, displayValues) {
  const value = displayValues[element.id] ?? ''
  return `${element.prefix ?? ''}${value}${element.suffix ?? ''}`
}

export function formatBarcodeValue(element, displayValues) {
  return displayValues[element.id] || '(barcode)'
}

export function computeBarcodeStyle(element, displayValues, scale) {
  const value = displayValues[element.id] || '123456'
  const minWidth = 80
  const dynamicWidth = Math.max(minWidth, Math.min(320, value.length * 10))

  return {
    width: `${dynamicWidth * scale}px`,
    height: `${(element.height ?? 100) * scale}px`,
  }
}

export function computeBarcodeBarsStyle(element, displayValues) {
  const value = displayValues[element.id] || '123456'
  const seed = value.split('').reduce((acc, char) => acc + char.charCodeAt(0), 0)

  return {
    background: `repeating-linear-gradient(
      90deg,
      #000 0 ${2 + (seed % 2)}px,
      #fff ${2 + (seed % 2)}px ${5 + (seed % 3)}px,
      #000 ${5 + (seed % 3)}px ${7 + (seed % 2)}px,
      #fff ${7 + (seed % 2)}px ${11 + (seed % 4)}px
    )`,
  }
}
