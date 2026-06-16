import { beforeEach, describe, expect, it, vi } from 'vitest'
import { reactive } from 'vue'
import {
  deleteLocalLayout,
  exportLayoutToFile,
  importLayoutFromFile,
  isDataSourceInUse,
  listLocalLayouts,
  loadLocalLayout,
  openLayoutFromFile,
  removeDataSource,
  sanitizeTemplateForSave,
  saveLayoutToFile,
  saveLocalLayout,
} from '../layoutStorage.js'

const STORAGE_KEY = 'mojito-layouts'

describe('layoutStorage', () => {
  beforeEach(() => {
    localStorage.clear()
    delete window.electronAPI
    vi.restoreAllMocks()
  })

  it('sanitizeTemplateForSave clona proxy Vue e rimuove defaultValue', () => {
    const template = reactive({
      id: 't1',
      name: 'Test',
      dataSources: [{ name: 'barcode', label: 'Barcode', defaultValue: 'ABC' }],
      elements: [{ id: '1', type: 'barcode', dataSource: 'barcode', imageData: 'data:image/png;base64,AA==' }],
    })

    const saved = sanitizeTemplateForSave(template)

    expect(saved.dataSources[0]).toEqual({ name: 'barcode', label: 'Barcode' })
    expect(saved.elements[0].imageData).toBe('data:image/png;base64,AA==')
  })

  it('save/load/delete local layout', () => {
    const saved = saveLocalLayout({ id: 'a', name: 'A', elements: [], dataSources: [] })
    expect(saved.id).toBe('a')
    expect(listLocalLayouts()).toHaveLength(1)
    expect(loadLocalLayout('a')?.name).toBe('A')

    saveLocalLayout({ id: 'a', name: 'A2', elements: [], dataSources: [] })
    expect(loadLocalLayout('a')?.name).toBe('A2')

    deleteLocalLayout('a')
    expect(listLocalLayouts()).toHaveLength(0)
  })

  it('listLocalLayouts gestisce storage corrotto', () => {
    localStorage.setItem(STORAGE_KEY, '{bad json')
    expect(listLocalLayouts()).toEqual([])
  })

  it('sanitizeTemplateForSave applica default', () => {
    const saved = sanitizeTemplateForSave({ elements: [], dataSources: [] })
    expect(saved.name).toBe('Etichetta senza nome')
    expect(saved.labelWidth).toBe(600)
    expect(saved.id).toBeTruthy()
  })

  it('exportLayoutToFile crea download', () => {
    const click = vi.fn()
    global.URL.createObjectURL = vi.fn(() => 'blob:1')
    global.URL.revokeObjectURL = vi.fn()
    vi.spyOn(document, 'createElement').mockReturnValue({ click, download: '' })

    exportLayoutToFile({ name: 'My Layout', elements: [], dataSources: [] })

    expect(click).toHaveBeenCalled()
    expect(global.URL.revokeObjectURL).toHaveBeenCalled()
  })

  it('saveLayoutToFile usa electron quando disponibile', async () => {
    window.electronAPI = {
      layout: {
        saveFile: vi.fn().mockResolvedValue({ canceled: false, filePath: '/tmp/x.json' }),
      },
    }

    const result = await saveLayoutToFile({ name: 'E', elements: [], dataSources: [] })

    expect(result.saved).toBe(true)
    expect(result.filePath).toBe('/tmp/x.json')
  })

  it('saveLayoutToFile electron canceled', async () => {
    window.electronAPI = {
      layout: { saveFile: vi.fn().mockResolvedValue({ canceled: true }) },
    }

    const result = await saveLayoutToFile({ name: 'E', elements: [], dataSources: [] })
    expect(result.saved).toBe(false)
  })

  it('saveLayoutToFile fallback browser export', async () => {
    vi.spyOn(document, 'createElement').mockReturnValue({ click: vi.fn(), download: '' })
    global.URL.createObjectURL = vi.fn(() => 'blob:1')
    global.URL.revokeObjectURL = vi.fn()

    const result = await saveLayoutToFile({ name: 'Browser', elements: [], dataSources: [] })
    expect(result.saved).toBe(true)
    expect(result.filePath).toBeNull()
  })

  it('openLayoutFromFile electron', async () => {
    window.electronAPI = {
      layout: {
        openFile: vi.fn().mockResolvedValue({
          canceled: false,
          filePath: '/tmp/a.json',
          content: { id: 'x', name: 'X', elements: [], dataSources: [] },
        }),
      },
    }

    const opened = await openLayoutFromFile()
    expect(opened?.name).toBe('X')
    expect(opened?.filePath).toBe('/tmp/a.json')
  })

  it('openLayoutFromFile canceled/null', async () => {
    window.electronAPI = {
      layout: { openFile: vi.fn().mockResolvedValue({ canceled: true }) },
    }
    expect(await openLayoutFromFile()).toBeNull()
    delete window.electronAPI
    expect(await openLayoutFromFile()).toBeNull()
  })

  it('importLayoutFromFile valido, invalido e senza id', async () => {
    const valid = await importLayoutFromFile({
      name: 'f.json',
      text: async () => JSON.stringify({ id: 'f', name: 'F', elements: [], dataSources: [] }),
    })
    expect(valid.name).toBe('F')

    const withoutId = await importLayoutFromFile({
      name: 'no-id.json',
      text: async () => JSON.stringify({
        name: 'NoId',
        elements: [],
        dataSources: [{ name: 'title' }],
      }),
    })
    expect(withoutId.id).toBeTruthy()
    expect(withoutId.dataSources[0].label).toBe('title')
    expect(withoutId.dataSources[0].defaultValue).toBe('')

    await expect(
      importLayoutFromFile({
        name: 'bad.json',
        text: async () => JSON.stringify({ id: 'bad' }),
      })
    ).rejects.toThrow('File layout non valido')
  })

  it('removeDataSource e isDataSourceInUse', () => {
    const template = reactive({
      dataSources: [
        { name: 'title', defaultValue: 'A' },
        { name: 'barcode', defaultValue: '123' },
      ],
      elements: [{ dataSource: 'barcode' }, { dataSource: 'title' }],
    })

    expect(isDataSourceInUse(template, 'barcode')).toBe(true)
    const next = removeDataSource(template, 'barcode')
    expect(next.dataSources).toHaveLength(1)
    expect(next.elements[0].dataSource).toBe('title')
  })
})
