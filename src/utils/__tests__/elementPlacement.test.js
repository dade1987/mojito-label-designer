import { describe, expect, it, beforeEach } from 'vitest'
import {
  canPlaceAt,
  defaultElementSize,
  findEmptyPlacement,
  getPreferredPlacementSlot,
  rememberPlacementSlot,
  slotKey,
} from '../elementPlacement.js'
import { getElementBounds, rectsIntersect } from '../canvasSelection.js'

describe('elementPlacement', () => {
  beforeEach(() => {
    localStorage.clear()
  })

  it('defaultElementSize per tipo', () => {
    expect(defaultElementSize('text').height).toBe(38)
    expect(defaultElementSize('barcode').height).toBe(130)
    expect(defaultElementSize('qr')).toEqual({ width: 100, height: 100 })
    expect(defaultElementSize('image').width).toBe(80)
  })

  it('findEmptyPlacement evita sovrapposizioni', () => {
    const template = {
      labelWidth: 200,
      labelHeight: 200,
      elements: [{ id: 'a', type: 'text', x: 12, y: 12, fontHeight: 30 }],
    }

    const placement = findEmptyPlacement(template, 'text', { a: 'Occupato' })
    const occupied = getElementBounds(template.elements[0], { a: 'Occupato' })
    const candidate = { ...placement, ...defaultElementSize('text') }

    expect(rectsIntersect(candidate, occupied)).toBe(false)
  })

  it('findEmptyPlacement preferisce slot memorizzato se libero', () => {
    const template = {
      id: 'layout-a',
      labelWidth: 600,
      labelHeight: 400,
      elements: [],
    }

    rememberPlacementSlot('layout-a', 'text', { x: 120, y: 80 })

    const placement = findEmptyPlacement(
      template,
      'text',
      {},
      getPreferredPlacementSlot('layout-a', 'text')
    )

    expect(placement).toEqual({ x: 120, y: 80 })
  })

  it('canPlaceAt rifiuta posizioni fuori etichetta', () => {
    expect(canPlaceAt(590, 10, defaultElementSize('text'), 600, 400, [])).toBe(false)
  })

  it('slotKey combina layout e tipo', () => {
    expect(slotKey('abc', 'barcode')).toBe('abc:barcode')
  })
})
