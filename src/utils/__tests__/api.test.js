import { beforeEach, describe, expect, it, vi } from 'vitest'
import {
  deleteTemplate,
  fetchDefaultTemplate,
  fetchPrinters,
  fetchTemplate,
  fetchTemplates,
  previewZpl,
  printLabel,
  saveTemplate,
} from '../api.js'

describe('api', () => {
  beforeEach(() => {
    vi.restoreAllMocks()
  })

  it('fetchPrinters', async () => {
    vi.spyOn(global, 'fetch').mockResolvedValue({
      ok: true,
      json: async () => ({ printers: ['Citizen_CL_S703Z'], platform: 'Windows' }),
    })

    const result = await fetchPrinters()
    expect(result.printers).toContain('Citizen_CL_S703Z')
    expect(result.platform).toBe('Windows')
  })

  it('fetchDefaultTemplate e fetchTemplates', async () => {
    vi.spyOn(global, 'fetch')
      .mockResolvedValueOnce({ ok: true, json: async () => ({ id: 'default' }) })
      .mockResolvedValueOnce({ ok: true, json: async () => ({ templates: [] }) })

    expect((await fetchDefaultTemplate()).id).toBe('default')
    expect((await fetchTemplates()).templates).toEqual([])
  })

  it('fetchTemplate saveTemplate deleteTemplate preview print', async () => {
    vi.spyOn(global, 'fetch')
      .mockResolvedValueOnce({ ok: true, json: async () => ({ id: 'x' }) })
      .mockResolvedValueOnce({ ok: true, json: async () => ({ status: 'saved' }) })
      .mockResolvedValueOnce({ ok: true, json: async () => ({ status: 'deleted' }) })
      .mockResolvedValueOnce({ ok: true, json: async () => ({ zpl: '^XA^XZ' }) })
      .mockResolvedValueOnce({ ok: true, json: async () => ({ status: 'printed' }) })

    expect((await fetchTemplate('x')).id).toBe('x')
    expect((await saveTemplate({ id: 'x' })).status).toBe('saved')
    expect((await deleteTemplate('x')).status).toBe('deleted')
    expect((await previewZpl({ title: 'A' })).zpl).toBe('^XA^XZ')
    expect((await printLabel({ title: 'A' })).status).toBe('printed')
  })

  it('propaga errori HTTP', async () => {
    vi.spyOn(global, 'fetch').mockResolvedValue({
      ok: false,
      status: 500,
      json: async () => ({ error: 'Boom' }),
    })

    await expect(fetchPrinters()).rejects.toThrow('Boom')
  })

  it('errore generico se payload non json', async () => {
    vi.spyOn(global, 'fetch').mockResolvedValue({
      ok: false,
      status: 502,
      json: async () => {
        throw new Error('bad json')
      },
    })

    await expect(fetchPrinters()).rejects.toThrow('Errore HTTP 502')
  })
})
