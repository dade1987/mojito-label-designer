import { describe, expect, it } from 'vitest'
import {
  CUSTOM_FORMAT_ID,
  LABEL_FORMATS,
  PRINTER_RESOLUTIONS,
  applyFormat,
  detectFormat,
  dotsToMm,
  findFormat,
  fitElementsToLabel,
  fitTemplateToSize,
  mmToDots,
  rescaleTemplateForDpi,
  scaleTemplateElements,
} from '../labelFormats.js'

describe('labelFormats', () => {
  it('mmToDots e dotsToMm sono coerenti', () => {
    expect(mmToDots(100, 203)).toBe(799)
    expect(mmToDots(100, 300)).toBe(1181)
    expect(dotsToMm(799, 203)).toBe(100)
    expect(dotsToMm(100, 0)).toBe(0)
  })

  it('LABEL_FORMATS entro la larghezza di stampa (104 mm)', () => {
    for (const format of LABEL_FORMATS) {
      expect(format.widthMm).toBeLessThanOrEqual(104)
      expect(format.heightMm).toBeGreaterThan(0)
    }
  })

  it('findFormat trova per id', () => {
    expect(findFormat('60x40')?.widthMm).toBe(60)
    expect(findFormat('boh')).toBeNull()
  })

  it('applyFormat imposta i dots dal formato in mm', () => {
    const template = { labelWidth: 600, labelHeight: 400, dpi: 203 }

    expect(applyFormat(template, '60x40')).toBe(true)
    expect(template.labelWidth).toBe(mmToDots(60, 203))
    expect(template.labelHeight).toBe(mmToDots(40, 203))

    expect(applyFormat(template, 'inesistente')).toBe(false)
  })

  it('applyFormat usa 203 dpi come default', () => {
    const template = { labelWidth: 0, labelHeight: 0 }

    applyFormat(template, '50x30')

    expect(template.labelWidth).toBe(mmToDots(50, 203))
  })

  it('detectFormat riconosce il formato con tolleranza', () => {
    const template = { labelWidth: mmToDots(60, 203) + 1, labelHeight: mmToDots(40, 203), dpi: 203 }

    expect(detectFormat(template)).toBe('60x40')
    expect(detectFormat({ labelWidth: 600, labelHeight: 400, dpi: 203 })).toBe(CUSTOM_FORMAT_ID)
    expect(detectFormat({})).toBe(CUSTOM_FORMAT_ID)
  })

  it('rescaleTemplateForDpi mantiene le misure fisiche', () => {
    const template = {
      labelWidth: mmToDots(60, 203),
      labelHeight: mmToDots(40, 203),
      dpi: 203,
      elements: [
        { id: 't', type: 'text', x: 40, y: 40, fontHeight: 30, fontWidth: 30 },
        { id: 'b', type: 'barcode', x: 40, y: 200, height: 100, moduleWidth: 2 },
        { id: 'i', type: 'image', x: 10, y: 10, width: 80, height: 80 },
        { id: 'q', type: 'qr', x: 5, y: 5, magnification: 4 },
      ],
    }

    expect(rescaleTemplateForDpi(template, 300)).toBe(true)

    expect(template.dpi).toBe(300)
    expect(dotsToMm(template.labelWidth, 300)).toBeCloseTo(60, 0)
    expect(template.elements[0].fontHeight).toBe(Math.round(30 * (300 / 203)))
    expect(template.elements[1].moduleWidth).toBe(3)
    expect(template.elements[2].width).toBe(Math.round(80 * (300 / 203)))
    expect(template.elements[3].magnification).toBe(Math.round(4 * (300 / 203)))
    expect(detectFormat(template)).toBe('60x40')
  })

  it('rescaleTemplateForDpi limita la magnification QR a 1-10', () => {
    const template = {
      dpi: 203,
      elements: [{ id: 'q', type: 'qr', magnification: 9 }],
    }

    rescaleTemplateForDpi(template, 300)

    expect(template.elements[0].magnification).toBe(10)
  })

  it('rescaleTemplateForDpi rispetta i minimi e i default', () => {
    const template = {
      dpi: 300,
      elements: [
        { id: 'b', type: 'barcode' },
        { id: 't', type: 'text' },
        { id: 'x', type: 'boh' },
      ],
    }

    rescaleTemplateForDpi(template, 203)

    expect(template.labelWidth).toBe(Math.round(600 * (203 / 300)))
    expect(template.elements[0].moduleWidth).toBe(Math.max(1, Math.round(2 * (203 / 300))))
    expect(template.elements[1].fontHeight).toBe(Math.max(10, Math.round(30 * (203 / 300))))
  })

  it('rescaleTemplateForDpi ignora dpi non validi o invariati', () => {
    const template = { labelWidth: 600, labelHeight: 400, dpi: 203 }

    expect(rescaleTemplateForDpi(template, 203)).toBe(false)
    expect(rescaleTemplateForDpi(template, 0)).toBe(false)
    expect(template.labelWidth).toBe(600)
  })

  it('fitTemplateToSize riscala il layout in proporzione (fattore unico)', () => {
    const template = {
      labelWidth: 812,
      labelHeight: 406,
      dpi: 203,
      elements: [
        { id: 't', type: 'text', x: 40, y: 40, fontHeight: 40, fontWidth: 40 },
        { id: 'b', type: 'barcode', x: 40, y: 200, height: 100, moduleWidth: 2 },
      ],
    }

    expect(fitTemplateToSize(template, 400, 400)).toBe(true)

    // ratio = min(400/812, 400/406) = 0.4926
    expect(template.labelWidth).toBe(400)
    expect(template.labelHeight).toBe(400)
    expect(template.elements[0].x).toBe(Math.round(40 * (400 / 812)))
    expect(template.elements[0].fontHeight).toBe(Math.round(40 * (400 / 812)))
    expect(template.elements[1].moduleWidth).toBe(1)
  })

  it('fitTemplateToSize senza riscalatura se il formato non cambia', () => {
    const template = {
      labelWidth: 400,
      labelHeight: 400,
      elements: [{ id: 't', type: 'text', x: 40, y: 40, fontHeight: 40, fontWidth: 40 }],
    }

    expect(fitTemplateToSize(template, 400, 400)).toBe(true)
    expect(template.elements[0].fontHeight).toBe(40)

    expect(fitTemplateToSize(template, 0, 400)).toBe(false)
    expect(fitTemplateToSize({ labelWidth: 0, labelHeight: 400 }, 300, 300)).toBe(false)
  })

  it('fitElementsToLabel stringe il contenuto debordante', () => {
    const template = {
      labelWidth: 400,
      labelHeight: 400,
      elements: [
        // barcode largo 422 dots a modulo 2 → deborda da 400
        { id: 'b', type: 'barcode', x: 40, y: 200, height: 100, moduleWidth: 2, dataSource: 'v' },
      ],
    }

    expect(fitElementsToLabel(template, { b: 'CHL13230Q20S0426' })).toBe(true)
    expect(template.elements[0].moduleWidth).toBe(1)
    expect(template.elements[0].x).toBeLessThan(40)
    expect(template.labelWidth).toBe(400)
  })

  it('fitElementsToLabel non tocca layout già dentro o vuoti', () => {
    const inside = {
      labelWidth: 600,
      labelHeight: 400,
      elements: [{ id: 't', type: 'text', x: 10, y: 10, fontHeight: 30, dataSource: 'v' }],
    }

    expect(fitElementsToLabel(inside, { t: 'ciao' })).toBe(false)
    expect(inside.elements[0].fontHeight).toBe(30)

    expect(fitElementsToLabel({ labelWidth: 400, labelHeight: 400, elements: [] })).toBe(false)
  })

  it('scaleTemplateElements gestisce template senza elementi', () => {
    const template = {}

    scaleTemplateElements(template, 2)

    expect(template.elements).toBeUndefined()
  })

  it('PRINTER_RESOLUTIONS copre CL-S700 e CL-S703', () => {
    expect(PRINTER_RESOLUTIONS.map((resolution) => resolution.dpi)).toEqual([203, 300])
  })
})
