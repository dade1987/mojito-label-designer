import { describe, expect, it } from 'vitest'
import {
  asUnsavedStartingPoint,
  findLayoutByName,
  findOverwriteTarget,
  overwriteQuestion,
  prepareSaveAs,
  proposeSaveAsName,
} from '../layoutSaveGuard.js'

/**
 * "Salva" scriveva sempre sopra il layout con lo stesso identificativo:
 * chi apriva un'etichetta, la rinominava e salvava perdeva l'originale
 * senza nessun avviso. Queste funzioni decidono quando fermarsi a chiedere
 * e come si crea un layout nuovo a partire da quello aperto.
 */

const layouts = [
  { id: 'a', name: 'Etichetta A' },
  { id: 'b', name: 'Etichetta B' },
]

describe('findOverwriteTarget', () => {
  it('non trova nulla se l\'identificativo non è ancora salvato', () => {
    expect(findOverwriteTarget(layouts, { id: 'nuovo', name: 'Etichetta A' })).toBeNull()
    expect(findOverwriteTarget([], { id: 'a', name: 'Etichetta A' })).toBeNull()
    expect(findOverwriteTarget(undefined, { id: 'a', name: 'Etichetta A' })).toBeNull()
  })

  it('con lo stesso nome è un normale aggiornamento', () => {
    expect(findOverwriteTarget(layouts, { id: 'a', name: 'Etichetta A' })).toEqual({
      id: 'a',
      name: 'Etichetta A',
      renamed: false,
    })
  })

  it('con un nome diverso segnala che si sta per perdere l\'originale', () => {
    expect(findOverwriteTarget(layouts, { id: 'a', name: 'Etichetta nuova' })).toEqual({
      id: 'a',
      name: 'Etichetta A',
      renamed: true,
    })
  })

  it('spazi attorno al nome non contano come rinomina', () => {
    expect(findOverwriteTarget(layouts, { id: 'a', name: '  Etichetta A ' }).renamed).toBe(false)
  })

  it('un layout senza id non può sovrascrivere niente, nemmeno un salvataggio vecchio senza id', () => {
    expect(findOverwriteTarget(layouts, { name: 'Etichetta A' })).toBeNull()
    expect(findOverwriteTarget([{ name: 'Senza id' }], { name: 'Altro' })).toBeNull()
    expect(findOverwriteTarget(layouts, null)).toBeNull()
  })
})

describe('findLayoutByName', () => {
  it('cerca il nome ignorando maiuscole e spazi ai bordi', () => {
    expect(findLayoutByName(layouts, ' etichetta a ')).toEqual({ id: 'a', name: 'Etichetta A' })
    expect(findLayoutByName(layouts, 'Etichetta C')).toBeNull()
    expect(findLayoutByName(undefined, 'Etichetta A')).toBeNull()
  })

  it('può escludere il layout che si sta salvando', () => {
    expect(findLayoutByName(layouts, 'Etichetta A', { excludeId: 'a' })).toBeNull()
    expect(findLayoutByName(layouts, 'Etichetta A', { excludeId: 'b' })).toEqual({ id: 'a', name: 'Etichetta A' })
  })

  it('un nome vuoto non trova nulla', () => {
    expect(findLayoutByName([{ id: 'x', name: '' }], '')).toBeNull()
    expect(findLayoutByName(layouts, undefined)).toBeNull()
  })
})

describe('proposeSaveAsName', () => {
  it('propone il nome così com\'è se è libero', () => {
    expect(proposeSaveAsName('Etichetta C', layouts)).toBe('Etichetta C')
  })

  it('aggiunge "(copia)" se il nome è già preso, e numera le copie successive', () => {
    expect(proposeSaveAsName('Etichetta A', layouts)).toBe('Etichetta A (copia)')
    expect(
      proposeSaveAsName('Etichetta A', [...layouts, { id: 'c', name: 'Etichetta A (copia)' }])
    ).toBe('Etichetta A (copia 2)')
    expect(
      proposeSaveAsName('Etichetta A', [
        ...layouts,
        { id: 'c', name: 'Etichetta A (copia)' },
        { id: 'd', name: 'Etichetta A (copia 2)' },
      ])
    ).toBe('Etichetta A (copia 3)')
  })

  it('senza nome parte da "Etichetta senza nome"', () => {
    expect(proposeSaveAsName('', [])).toBe('Etichetta senza nome')
    expect(proposeSaveAsName(undefined, [])).toBe('Etichetta senza nome')
    expect(proposeSaveAsName('   ', [])).toBe('Etichetta senza nome')
  })
})

describe('prepareSaveAs', () => {
  it('crea una copia con identificativo nuovo e il nome scelto', () => {
    const template = {
      id: 'a',
      name: 'Etichetta A',
      labelWidth: 400,
      labelHeight: 300,
      dpi: 300,
      printMode: 'graphic',
      dataSources: [{ name: 'seriale', defaultValue: '1' }],
      elements: [{ id: 'e1', type: 'text', dataSource: 'seriale' }],
    }

    const copy = prepareSaveAs(template, '  Etichetta A bis ')

    expect(copy.id).not.toBe('a')
    expect(copy.id).toMatch(/^[0-9a-f-]{36}$/)
    expect(copy.name).toBe('Etichetta A bis')
    expect(copy.labelWidth).toBe(400)
    expect(copy.printMode).toBe('graphic')
    expect(copy.dataSources).toEqual([{ name: 'seriale', label: 'seriale', defaultValue: '1' }])
    expect(copy.elements).toEqual(template.elements)
    expect(copy.elements).not.toBe(template.elements)
    expect(template.id).toBe('a')
    expect(template.name).toBe('Etichetta A')
  })

  it('due copie di seguito hanno identificativi diversi', () => {
    const template = { id: 'a', name: 'A', elements: [] }
    expect(prepareSaveAs(template, 'B').id).not.toBe(prepareSaveAs(template, 'C').id)
  })
})

describe('overwriteQuestion', () => {
  it('dice cosa si perde, cosa lo sostituisce e come evitarlo', () => {
    const question = overwriteQuestion({ id: 'a', name: 'Etichetta A' }, 'Etichetta nuova', 'sul server')

    expect(question).toContain('sul server')
    expect(question).toContain('«Etichetta A»')
    expect(question).toContain('«Etichetta nuova»')
    expect(question).toContain('Salva con nome')
  })
})

describe('asUnsavedStartingPoint', () => {
  it('toglie l\'identificativo del template di partenza, così il primo salvataggio crea un layout nuovo', () => {
    const start = asUnsavedStartingPoint({ id: 'cavallini-service', name: 'Cavallini Service', elements: [] })

    expect(start).not.toHaveProperty('id')
    expect(start.name).toBe('Cavallini Service')
    expect(start.elements).toEqual([])
  })

  it('con un template nullo restituisce null', () => {
    expect(asUnsavedStartingPoint(null)).toBeNull()
  })
})
