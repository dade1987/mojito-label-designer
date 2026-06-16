import { describe, expect, it } from 'vitest'
import {
  getElementBounds,
  normalizeRect,
  rectsIntersect,
  selectElementsInRect,
} from '../canvasSelection.js'

describe('canvasSelection', () => {
  it('normalizeRect gestisce drag in qualsiasi direzione', () => {
    expect(normalizeRect(10, 20, 50, 80)).toEqual({ x: 10, y: 20, width: 40, height: 60 })
    expect(normalizeRect(50, 80, 10, 20)).toEqual({ x: 10, y: 20, width: 40, height: 60 })
  })

  it('rectsIntersect rileva sovrapposizioni', () => {
    expect(rectsIntersect({ x: 0, y: 0, width: 10, height: 10 }, { x: 5, y: 5, width: 10, height: 10 })).toBe(
      true
    )
    expect(rectsIntersect({ x: 0, y: 0, width: 10, height: 10 }, { x: 20, y: 20, width: 10, height: 10 })).toBe(
      false
    )
  })

  it('selectElementsInRect seleziona elementi nel riquadro', () => {
    const elements = [
      { id: 'a', type: 'text', x: 10, y: 10, fontHeight: 30, dataSource: 't' },
      { id: 'b', type: 'text', x: 200, y: 200, fontHeight: 30, dataSource: 't' },
    ]
    const values = { a: 'Hello', b: 'Fuori' }

    const selected = selectElementsInRect(elements, values, { x: 0, y: 0, width: 120, height: 120 })

    expect(selected).toEqual(['a'])
  })

  it('getElementBounds stima testo e immagine', () => {
    const textBounds = getElementBounds(
      { id: 't', type: 'text', x: 5, y: 6, fontHeight: 30, prefix: 'A' },
      { t: 'B' }
    )
    expect(textBounds.x).toBe(5)
    expect(textBounds.width).toBeGreaterThan(20)

    const imageBounds = getElementBounds(
      { id: 'i', type: 'image', x: 1, y: 2, width: 50, height: 40 },
      {}
    )
    expect(imageBounds).toEqual({ x: 1, y: 2, width: 50, height: 40 })
  })
})
