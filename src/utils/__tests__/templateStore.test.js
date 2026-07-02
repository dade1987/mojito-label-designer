import { describe, expect, it } from 'vitest'
import {
  buildValuesFromSources,
  countElementsUsingDataSource,
  createElement,
  createEmptyTemplate,
  describeElementForUi,
  disconnectElementFromSharedDataSource,
  findSharedDataSources,
  pruneUnusedDataSources,
  registerNewElement,
  renameDataSource,
  reassignElementToDedicatedDataSource,
  repairBrokenDataSourceReferences,
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
    expect(createElement('qr')).toMatchObject({
      magnification: 4,
      errorCorrection: 'M',
      dataSource: '',
    })
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

  it('registerNewElement assegna data source dedicato con prefisso QR', () => {
    const template = createEmptyTemplate()

    registerNewElement(template, createElement('qr', 10, 10))

    expect(template.elements[0].dataSource).toBe('qr_1')
    expect(
      template.dataSources.find((source) => source.name === 'qr_1')?.label
    ).toBe('QR 1')
  })

  it('registerNewElement assegna data source dedicato con prefisso Barcode', () => {
    const template = createEmptyTemplate()
    template.dataSources = []

    registerNewElement(template, createElement('barcode', 10, 10))

    expect(template.elements[0].dataSource).toBe('barcode_1')
    expect(
      template.dataSources.find((source) => source.name === 'barcode_1')?.label
    ).toBe('Barcode 1')
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

  it('findSharedDataSources elenca solo data source usati più volte', () => {
    const template = {
      dataSources: [
        { name: 'title', defaultValue: 'A' },
        { name: 'serial', defaultValue: 'B' },
      ],
      elements: [
        { id: 'a', type: 'text', dataSource: 'title', x: 1, y: 2 },
        { id: 'b', type: 'text', dataSource: 'title', x: 3, y: 4 },
        { id: 'c', type: 'barcode', dataSource: 'serial', x: 5, y: 6 },
      ],
    }

    expect(findSharedDataSources(template)).toEqual([
      {
        name: 'title',
        elements: [template.elements[0], template.elements[1]],
      },
    ])
  })

  it('disconnectElementFromSharedDataSource crea un data source dedicato', () => {
    const template = {
      dataSources: [{ name: 'title', label: 'Titolo', defaultValue: 'Condiviso' }],
      elements: [
        { id: 'a', type: 'text', dataSource: 'title', x: 10, y: 20 },
        { id: 'b', type: 'text', dataSource: 'title', x: 30, y: 40 },
      ],
    }

    const disconnected = disconnectElementFromSharedDataSource(template, template.elements[1], {
      title: 'Condiviso',
    })

    expect(disconnected).toBe(true)
    expect(template.elements[1].dataSource).toBe('text_1')
    expect(template.dataSources.find((source) => source.name === 'text_1')?.defaultValue).toBe(
      'Condiviso'
    )
    expect(countElementsUsingDataSource(template, 'title')).toBe(1)
  })

  it('describeElementForUi', () => {
    expect(describeElementForUi({ type: 'text', x: 10, y: 20 })).toBe('Testo (10, 20)')
    expect(describeElementForUi({ type: 'barcode', x: 0, y: 5 })).toBe('Barcode (0, 5)')
    expect(describeElementForUi({ type: 'qr', x: 1, y: 2 })).toBe('QR (1, 2)')
    expect(describeElementForUi({ type: 'image', x: 3, y: 4 })).toBe('image (3, 4)')
  })

  it('pruneUnusedDataSources rimuove qualsiasi data source non usato', () => {
    const template = {
      dataSources: [
        { name: 'barcode', defaultValue: '123' },
        { name: 'text_1', defaultValue: 'Orfano' },
        { name: 'title', defaultValue: 'Titolo' },
      ],
      elements: [{ id: 'b', type: 'barcode', dataSource: 'barcode' }],
    }

    pruneUnusedDataSources(template)

    expect(template.dataSources.map((source) => source.name)).toEqual(['barcode'])
  })

  it('renameDataSource aggiorna anche gli elementi collegati', () => {
    const template = {
      dataSources: [{ name: 'text_1', defaultValue: 'A' }],
      elements: [{ id: 't', type: 'text', dataSource: 'text_1' }],
    }

    expect(renameDataSource(template, 'text_1', 'codice')).toEqual({ ok: true, name: 'codice' })
    expect(template.dataSources[0].name).toBe('codice')
    expect(template.elements[0].dataSource).toBe('codice')
  })

  it('reassignElementToDedicatedDataSource crea sempre un nuovo campo', () => {
    const template = {
      dataSources: [{ name: 'text_7', label: 'Testo 7', defaultValue: 'Solo' }],
      elements: [{ id: 't', type: 'text', dataSource: 'text_7' }],
    }

    const nextName = reassignElementToDedicatedDataSource(template, template.elements[0], {
      text_7: 'Solo',
    })

    expect(nextName).toBe('text_1')
    expect(template.elements[0].dataSource).toBe('text_1')
    expect(template.dataSources.find((source) => source.name === 'text_1')?.defaultValue).toBe('Solo')
  })

  it('repairBrokenDataSourceReferences ricrea data source mancanti', () => {
    const template = {
      dataSources: [{ name: 'title', defaultValue: 'Titolo' }],
      elements: [{ id: 't', type: 'text', dataSource: 'fantasma' }],
    }

    repairBrokenDataSourceReferences(template)

    expect(template.elements[0].dataSource).toBe('text_1')
    expect(template.dataSources.some((source) => source.name === 'text_1')).toBe(true)
  })
})
