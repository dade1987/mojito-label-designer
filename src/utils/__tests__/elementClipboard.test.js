import { describe, expect, it } from 'vitest'
import {
  duplicateElementInTemplate,
  duplicateElementsInTemplate,
} from '../templateStore.js'

describe('element duplicate', () => {
  it('duplicateElementInTemplate crea copia con data source dedicato', () => {
    const template = {
      id: 'layout-1',
      labelWidth: 600,
      labelHeight: 400,
      dataSources: [{ name: 'title', defaultValue: 'Ciao' }],
      elements: [{ id: 'a', type: 'text', x: 10, y: 20, dataSource: 'title', fontHeight: 30 }],
    }

    const copy = duplicateElementInTemplate(template, template.elements[0], { title: 'Ciao' })

    expect(copy.id).not.toBe('a')
    expect(copy.x !== 10 || copy.y !== 20).toBe(true)
    expect(copy.dataSource).not.toBe('title')
    expect(template.elements).toHaveLength(2)
    expect(
      template.dataSources.find((source) => source.name === copy.dataSource)?.defaultValue
    ).toBe('Ciao')
  })

  it('duplicateElementsInTemplate duplica selezione multipla', () => {
    const template = {
      id: 'layout-1',
      labelWidth: 600,
      labelHeight: 400,
      dataSources: [{ name: 'title', defaultValue: 'A' }],
      elements: [
        { id: 'a', type: 'text', x: 10, y: 20, dataSource: 'title', fontHeight: 30 },
        { id: 'b', type: 'text', x: 200, y: 200, dataSource: 'title', fontHeight: 30 },
      ],
    }

    const copies = duplicateElementsInTemplate(template, template.elements, { title: 'A' })

    expect(copies).toHaveLength(2)
    expect(template.elements).toHaveLength(4)
    expect(new Set(copies.map((element) => element.dataSource)).size).toBe(2)
  })

  it('duplicateElementInTemplate copia anche le immagini', () => {
    const template = {
      id: 'layout-1',
      labelWidth: 600,
      labelHeight: 400,
      dataSources: [],
      elements: [
        {
          id: 'img',
          type: 'image',
          x: 30,
          y: 40,
          width: 80,
          height: 80,
          imageData: 'data:image/png;base64,AA==',
        },
      ],
    }

    const copy = duplicateElementInTemplate(template, template.elements[0])

    expect(copy.type).toBe('image')
    expect(copy.imageData).toBe('data:image/png;base64,AA==')
    expect(copy.id).not.toBe('img')
  })
})
