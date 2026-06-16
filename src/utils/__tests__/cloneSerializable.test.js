import { describe, expect, it } from 'vitest'
import { reactive } from 'vue'
import { cloneTemplateState, toPlainObject } from '../cloneSerializable.js'

describe('cloneSerializable', () => {
  it('toPlainObject gestisce null, undefined e oggetti plain', () => {
    expect(toPlainObject(null)).toBeNull()
    expect(toPlainObject(undefined)).toBeUndefined()

    const source = { a: 1, b: [{ c: 2 }] }
    expect(toPlainObject(source)).toEqual(source)
    expect(toPlainObject(source)).not.toBe(source)
  })

  it('clona proxy Vue senza errori', () => {
    const template = reactive({
      name: 'Test',
      elements: [{ id: '1', type: 'barcode', dataSource: 'barcode' }],
      dataSources: [{ name: 'barcode', defaultValue: '123' }],
    })

    const cloned = cloneTemplateState(template)
    expect(cloned.elements).toEqual([{ id: '1', type: 'barcode', dataSource: 'barcode' }])
    expect(cloned.dataSources).toEqual([{ name: 'barcode', defaultValue: '123' }])
    expect(cloned).not.toBe(template)
  })
})
