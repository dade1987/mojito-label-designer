import { describe, expect, it } from 'vitest'
import {
  buildElementDisplayValues,
  computeBarcodeBarsStyle,
  computeBarcodeStyle,
  computeScale,
  formatBarcodeValue,
  formatDisplayText,
} from '../canvasDisplay.js'

describe('canvasDisplay', () => {
  const dataSources = [
    { name: 'title', defaultValue: 'Hello' },
    { name: 'barcode', defaultValue: '111' },
  ]

  const elements = [
    { id: 't1', type: 'text', dataSource: 'title', prefix: 'T: ' },
    { id: 'b1', type: 'barcode', dataSource: 'barcode' },
  ]

  it('computeScale limita la larghezza', () => {
    expect(computeScale(600)).toBe(1)
    expect(computeScale(1400)).toBeLessThan(1)
    expect(computeScale(0)).toBe(1)
    expect(computeScale(600, 300)).toBe(0.5)
  })

  it('buildElementDisplayValues gestisce input vuoti', () => {
    expect(buildElementDisplayValues(null, {}, null)).toEqual({})
    expect(buildElementDisplayValues([], { title: 'A' }, [])).toEqual({})
  })

  it('formatDisplayText con suffix e valore mancante', () => {
    expect(formatDisplayText({ id: 't1', suffix: '!' }, {})).toBe('!')
    expect(formatDisplayText({ id: 't1', prefix: 'P' }, { t1: 'V' })).toBe('PV')
  })

  it('buildElementDisplayValues reagisce ai dataValues', () => {
    const first = buildElementDisplayValues(elements, { title: 'A', barcode: '111' }, dataSources)
    const second = buildElementDisplayValues(elements, { title: 'A', barcode: 'NEW' }, dataSources)

    expect(first.b1).toBe('111')
    expect(second.b1).toBe('NEW')
  })

  it('formatDisplayText e formatBarcodeValue', () => {
    const values = { t1: 'Hello', b1: '999' }

    expect(formatDisplayText(elements[0], values)).toBe('T: Hello')
    expect(formatBarcodeValue(elements[1], values)).toBe('999')
    expect(formatBarcodeValue({ id: 'x' }, {})).toBe('(barcode)')
  })

  it('computeBarcodeStyle cambia con valore più lungo', () => {
    const short = computeBarcodeStyle(elements[1], { b1: '1' }, 1)
    const long = computeBarcodeStyle(elements[1], { b1: '1234567890' }, 1)

    expect(Number.parseInt(long.width, 10)).toBeGreaterThan(Number.parseInt(short.width, 10))
  })

  it('computeBarcodeBarsStyle cambia pattern con valore diverso', () => {
    const a = computeBarcodeBarsStyle(elements[1], { b1: 'AAA' })
    const b = computeBarcodeBarsStyle(elements[1], { b1: 'ZZZ' })

    expect(a.background).not.toBe(b.background)
  })
})
