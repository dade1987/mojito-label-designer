import { describe, expect, it } from 'vitest'
import {
  buildValuesFromSources,
  countElementsUsingDataSource,
  createElement,
  createEmptyTemplate,
  registerNewElement,
  resolveElementValue,
  updateElementTextValue,
} from '../templateStore.js'

describe('templateStore', () => {
  it('createEmptyTemplate', () => {
    const template = createEmptyTemplate()
    expect(template.elements).toEqual([])
    expect(template.dataSources).toHaveLength(4)
  })

  it('createElement per tutti i tipi', () => {
    const positioned = createElement('text', 10, 20)
    expect(positioned.x).toBe(10)
    expect(positioned.y).toBe(20)
    expect(createElement('text').dataSource).toBe('')
    expect(createElement('barcode').barcodeType).toBe('code128')
    expect(createElement('image').width).toBe(80)
    expect(createElement('unknown').type).toBe('unknown')
  })

  it('resolveElementValue preferisce dataValues', () => {
    const value = resolveElementValue(
      { type: 'text', dataSource: 'serial' },
      { serial: 'XYZ' },
      [{ name: 'serial', defaultValue: 'fallback' }]
    )
    expect(value).toBe('XYZ')
  })

  it('resolveElementValue usa defaultValue del data source', () => {
    expect(
      resolveElementValue(
        { type: 'text', dataSource: 'title' },
        {},
        [{ name: 'title', defaultValue: 'from-ds' }]
      )
    ).toBe('from-ds')
  })

  it('resolveElementValue usa staticValue e defaultValue', () => {
    expect(
      resolveElementValue(
        { type: 'text', dataSource: 'missing' },
        {},
        [{ name: 'other', defaultValue: 'x' }]
      )
    ).toBe('')

    expect(
      resolveElementValue(
        { type: 'text', dataSource: 'title', staticValue: 'STATIC' },
        {},
        []
      )
    ).toBe('STATIC')

    expect(resolveElementValue({ type: 'image' }, {}, [])).toBe('')
  })

  it('resolveElementValue ritorna stringa vuota senza fallback', () => {
    expect(resolveElementValue({ type: 'text', dataSource: 'missing' }, {}, [])).toBe('')
  })

  it('resolveElementValue accetta stringa vuota', () => {
    const value = resolveElementValue(
      { type: 'barcode', dataSource: 'barcode' },
      { barcode: '' },
      [{ name: 'barcode', defaultValue: 'FALLBACK' }]
    )
    expect(value).toBe('')
  })

  it('resolveElementValue usa suffix e staticValue numerico', () => {
    expect(
      resolveElementValue(
        { type: 'text', dataSource: 'title', staticValue: 42 },
        {},
        []
      )
    ).toBe('42')

    expect(
      resolveElementValue(
        { type: 'text', dataSource: 'missing', staticValue: '' },
        {},
        []
      )
    ).toBe('')
  })

  it('buildValuesFromSources', () => {
    expect(
      buildValuesFromSources([
        { name: 'title', defaultValue: 'Test' },
        { name: 'barcode' },
      ])
    ).toEqual({ title: 'Test', barcode: '' })
    expect(buildValuesFromSources([{ name: 'x' }])).toEqual({ x: '' })
  })

  it('registerNewElement assegna data source dedicato per ogni testo', () => {
    const template = createEmptyTemplate()

    registerNewElement(template, createElement('text', 10, 10))
    registerNewElement(template, createElement('text', 20, 20))

    expect(template.elements[0].dataSource).toBe('text_1')
    expect(template.elements[1].dataSource).toBe('text_2')
    expect(countElementsUsingDataSource(template, 'text_1')).toBe(1)
  })

  it('updateElementTextValue scollega elementi che condividono data source', () => {
    const template = {
      dataSources: [{ name: 'title', label: 'Titolo', defaultValue: 'Condiviso' }],
      elements: [
        { id: 'a', type: 'text', dataSource: 'title' },
        { id: 'b', type: 'text', dataSource: 'title' },
      ],
    }

    updateElementTextValue(template, template.elements[1], 'Solo B')

    expect(template.dataSources.find((source) => source.name === 'title')?.defaultValue).toBe(
      'Condiviso'
    )
    expect(template.elements[1].dataSource).toBe('text_1')
    expect(template.dataSources.find((source) => source.name === 'text_1')?.defaultValue).toBe(
      'Solo B'
    )
  })
})
