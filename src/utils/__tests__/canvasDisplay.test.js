import { describe, expect, it } from 'vitest'
import {
  CODE128_WIDTHS,
  FONT_CAP_TOP_EM,
  FONT_SIZE_RATIO,
  FONT_WIDTH_RATIO,
  buildElementDisplayValues,
  code128Bars,
  code39Bars,
  computeBarcodeMetrics,
  computeBarcodeStyle,
  computeFitScale,
  computeScale,
  computeTextStyle,
  encodeCode128,
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

  it('computeFitScale adatta l\'etichetta allo spazio disponibile', () => {
    // limitato dalla larghezza disponibile
    expect(computeFitScale(600, 400, 300, 10000)).toBe(0.5)
    // ingrandisce sugli schermi grandi (limite = larghezza)
    expect(computeFitScale(600, 400, 1200, 10000)).toBe(2)
    // limitato dall'altezza disponibile
    expect(computeFitScale(600, 400, 10000, 400)).toBe(1)
    // cap massimo per evitare ingrandimenti assurdi
    expect(computeFitScale(100, 100, 100000, 100000)).toBe(3)
    // senza area nota: fallback al vecchio cap a 700px
    expect(computeFitScale(1400, 400, 0, 0)).toBe(0.5)
    expect(computeFitScale(600, 400, 0, 0)).toBe(1)
    // ignora l'altezza se non nota
    expect(computeFitScale(600, 400, 900, 0)).toBe(1.5)
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

  it('CODE128_WIDTHS: 107 simboli da 11 moduli, stop da 13', () => {
    expect(CODE128_WIDTHS).toHaveLength(107)

    for (const [index, widths] of CODE128_WIDTHS.entries()) {
      const modules = widths.split('').reduce((acc, digit) => acc + Number(digit), 0)
      expect(modules).toBe(index === 106 ? 13 : 11)
    }
  })

  it('encodeCode128 subset B con start, checksum e stop', () => {
    // 'A' = 33, 'B' = 34; checksum = (104 + 33×1 + 34×2) mod 103 = 102
    expect(encodeCode128('AB')).toEqual([104, 33, 34, 102, 106])
  })

  it('encodeCode128 sostituisce caratteri fuori dal subset B', () => {
    expect(encodeCode128('\n')[1]).toBe(0)
  })

  it('code128Bars produce la larghezza reale in moduli', () => {
    const { bars, totalModules } = code128Bars('CHL13230Q20S0426')

    expect(totalModules).toBe(11 * (16 + 2) + 13)
    expect(bars[0]).toEqual({ x: 0, width: 2 })
    expect(bars.at(-1).x + bars.at(-1).width).toBe(totalModules)
  })

  it('code39Bars produce la larghezza reale in moduli', () => {
    const { bars, totalModules } = code39Bars('ABC')

    expect(totalModules).toBe((3 + 2) * 16 - 1)
    expect(bars[0].x).toBe(0)
  })

  it('computeBarcodeMetrics code128 con riga interpretazione', () => {
    const metrics = computeBarcodeMetrics(
      { id: 'b1', moduleWidth: 2, height: 100 },
      { b1: 'CHL13230Q20S0426' }
    )

    expect(metrics.widthDots).toBe(211 * 2)
    expect(metrics.barsHeightDots).toBe(100)
    expect(metrics.textFontDots).toBe(20)
    expect(metrics.totalHeightDots).toBe(100 + 5 + 20)
    expect(metrics.showText).toBe(true)
  })

  it('computeBarcodeMetrics code39 senza testo', () => {
    const metrics = computeBarcodeMetrics(
      { id: 'b1', barcodeType: 'code39', showText: false },
      { b1: 'AB' }
    )

    expect(metrics.widthDots).toBe(((2 + 2) * 16 - 1) * 2)
    expect(metrics.totalHeightDots).toBe(100)
    expect(metrics.showText).toBe(false)
  })

  it('computeBarcodeMetrics usa un placeholder con valore vuoto', () => {
    const metrics = computeBarcodeMetrics({ id: 'b1' }, {})

    expect(metrics.value).toBe('123456')
    expect(metrics.totalModules).toBe(11 * (6 + 2) + 13)
  })

  it('computeBarcodeStyle riflette le metriche reali', () => {
    const style = computeBarcodeStyle({ id: 'b1' }, { b1: '123456' }, 1)

    expect(style.width).toBe(`${(11 * 8 + 13) * 2}px`)
    expect(style.height).toBe(`${100 + 5 + 20}px`)
  })

  it('computeBarcodeStyle cambia con valore più lungo', () => {
    const short = computeBarcodeStyle(elements[1], { b1: '1' }, 1)
    const long = computeBarcodeStyle(elements[1], { b1: '1234567890' }, 1)

    expect(Number.parseInt(long.width, 10)).toBeGreaterThan(Number.parseInt(short.width, 10))
  })

  it('computeTextStyle mappa fontHeight con la calibrazione Triumvirate', () => {
    const style = computeTextStyle({ fontHeight: 40, fontWidth: 40 }, 1)

    expect(style.fontSize).toBe(`${Math.round(40 * FONT_SIZE_RATIO * 100) / 100}px`)
    expect(style.fontWeight).toBe('700')
    expect(style.textDecoration).toBe('none')
    expect(style.transform).toBe(
      `translateY(-${FONT_CAP_TOP_EM}em) scaleX(${Math.round(FONT_WIDTH_RATIO * 10000) / 10000})`
    )
    expect(style.transformOrigin).toBe('left top')
    expect(style.textShadow).toBeUndefined()
  })

  it('computeTextStyle applica scala, stretch, bold e underline', () => {
    const style = computeTextStyle(
      { fontHeight: 30, fontWidth: 45, bold: true, underline: true },
      0.5
    )

    expect(style.fontSize).toBe(`${Math.round(30 * FONT_SIZE_RATIO * 0.5 * 100) / 100}px`)
    expect(style.transform).toContain(`scaleX(${Math.round(1.5 * FONT_WIDTH_RATIO * 10000) / 10000})`)
    expect(style.textShadow).toBe('0.5px 0 0 currentColor')
    expect(style.textDecoration).toBe('underline')
  })

  it('computeTextStyle usa i default ZPL (30 dots)', () => {
    const style = computeTextStyle({})

    expect(style.fontSize).toBe(`${Math.round(30 * FONT_SIZE_RATIO * 100) / 100}px`)
  })

  it('la calibrazione riproduce le misure Labelary di riferimento', () => {
    // A fontHeight 100: cap-height resa = 100 × ratio × (72/100 cap Roboto) ≈ 77
    expect(100 * FONT_SIZE_RATIO * 0.72).toBeCloseTo(77, 1)
    // Campione largo 1057px a 100px → con size ratio e scaleX torna 1064 dots
    expect(10.57 * FONT_SIZE_RATIO * FONT_WIDTH_RATIO * 100).toBeCloseTo(1064, 0)
  })
})
