import { beforeEach, describe, expect, it, vi } from 'vitest'
import {
  checkPassword,
  deleteTemplate,
  fetchAuthStatus,
  fetchDefaultTemplate,
  fetchPrinters,
  fetchTemplate,
  fetchTemplates,
  previewLabelImage,
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

  /**
   * Il server sovrascrive solo se glielo si chiede: "Salva con nome..." e il
   * primo salvataggio mandano overwrite=false, e un layout gia' esistente
   * con quell'identificativo torna indietro come 409 invece di sparire.
   */
  it('saveTemplate manda il flag overwrite solo quando richiesto', async () => {
    const fetchSpy = vi.spyOn(global, 'fetch').mockResolvedValue({
      ok: true,
      json: async () => ({ status: 'saved' }),
    })

    await saveTemplate({ id: 'x', name: 'X' })
    expect(JSON.parse(fetchSpy.mock.calls[0][1].body)).toEqual({ id: 'x', name: 'X' })

    await saveTemplate({ id: 'x', name: 'X' }, { overwrite: false })
    expect(JSON.parse(fetchSpy.mock.calls[1][1].body)).toEqual({ id: 'x', name: 'X', overwrite: false })

    await saveTemplate({ id: 'x', name: 'X' }, { overwrite: true })
    expect(JSON.parse(fetchSpy.mock.calls[2][1].body)).toEqual({ id: 'x', name: 'X', overwrite: true })
  })

  it('un 409 arriva come errore con status e messaggio del server', async () => {
    vi.spyOn(global, 'fetch').mockResolvedValue({
      ok: false,
      status: 409,
      json: async () => ({ error: 'Sul server esiste già il layout «A»', conflict: { id: 'a', name: 'A' } }),
    })

    await expect(saveTemplate({ id: 'a' }, { overwrite: false })).rejects.toMatchObject({
      status: 409,
      message: 'Sul server esiste già il layout «A»',
    })
  })

  it('fetchAuthStatus e checkPassword parlano con /api/auth', async () => {
    const fetchSpy = vi.spyOn(global, 'fetch')
      .mockResolvedValueOnce({ ok: true, json: async () => ({ passwordRequired: true, authenticated: false }) })
      .mockResolvedValueOnce({ ok: true, json: async () => ({ status: 'ok' }) })

    expect((await fetchAuthStatus()).passwordRequired).toBe(true)
    expect(fetchSpy.mock.calls[0][0]).toMatch(/\/api\/auth$/)

    expect((await checkPassword('segreta')).status).toBe('ok')
    expect(fetchSpy.mock.calls[1][0]).toMatch(/\/api\/auth\/check$/)
    expect(JSON.parse(fetchSpy.mock.calls[1][1].body)).toEqual({ password: 'segreta' })
  })

  it('senza window (es. worker) le richieste sono relative', async () => {
    const fetchSpy = vi.spyOn(global, 'fetch').mockResolvedValue({ ok: true, json: async () => ({}) })
    vi.stubGlobal('window', undefined)

    try {
      await fetchPrinters()
    } finally {
      vi.unstubAllGlobals()
    }

    expect(fetchSpy.mock.calls[0][0]).toBe('/api/printers')
  })

  it('con VITE_API_BASE le richieste vanno a quel server, senza doppia barra', async () => {
    const fetchSpy = vi.spyOn(global, 'fetch').mockResolvedValue({ ok: true, json: async () => ({}) })
    vi.stubEnv('VITE_API_BASE', 'http://stampa.local:8080//')
    vi.resetModules()

    try {
      const remote = await import('../api.js')
      await remote.fetchPrinters()
    } finally {
      vi.unstubAllEnvs()
      vi.resetModules()
    }

    expect(fetchSpy.mock.calls[0][0]).toBe('http://stampa.local:8080/api/printers')
  })

  it('ogni funzione chiama il suo endpoint con il metodo giusto e il body JSON', async () => {
    const fetchSpy = vi.spyOn(global, 'fetch').mockResolvedValue({ ok: true, json: async () => ({}) })
    const cases = [
      [() => fetchAuthStatus(), '/api/auth', undefined],
      [() => checkPassword('p'), '/api/auth/check', 'POST'],
      [() => fetchPrinters(), '/api/printers', undefined],
      [() => fetchDefaultTemplate(), '/api/template/default', undefined],
      [() => fetchTemplates(), '/api/templates', undefined],
      [() => fetchTemplate('a b'), '/api/templates/a%20b', undefined],
      [() => saveTemplate({ id: 'x' }), '/api/templates', 'POST'],
      [() => deleteTemplate('a b'), '/api/templates/a%20b', 'DELETE'],
      [() => previewZpl({ a: 1 }), '/api/zpl/preview', 'POST'],
      [() => previewLabelImage({ a: 1 }), '/api/label/preview', 'POST'],
      [() => printLabel({ a: 1 }), '/api/print', 'POST'],
    ]

    for (const [call, path, method] of cases) {
      fetchSpy.mockClear()
      await call()
      const [url, options] = fetchSpy.mock.calls[0]
      expect(url).toBe(`${window.location.origin}${path}`)
      expect(options.method).toBe(method)
      expect(options.headers['Content-Type']).toBe('application/json')
      if (method === 'POST') expect(JSON.parse(options.body)).toBeTypeOf('object')
    }
  })

  it('manda l\'intestazione di autenticazione memorizzata e lascia aggiungere altre intestazioni', async () => {
    const fetchSpy = vi.spyOn(global, 'fetch').mockResolvedValue({ ok: true, json: async () => ({}) })
    sessionStorage.setItem('mojito_auth_password', 'segreta')

    await fetchPrinters()

    const headers = fetchSpy.mock.calls[0][1].headers
    expect(headers['Content-Type']).toBe('application/json')
    expect(Object.values(headers)).toContain('segreta')
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

  it('previewLabelImage chiede il disegno dell\'etichetta', async () => {
    const fetchSpy = vi.spyOn(global, 'fetch').mockResolvedValue({
      ok: true,
      json: async () => ({ png: 'data:image/png;base64,AAA', width: 400, height: 200 }),
    })

    const result = await previewLabelImage({ template: { labelWidth: 400 }, values: {} })

    expect(result.png).toContain('data:image/png;base64,')
    expect(fetchSpy.mock.calls[0][0]).toContain('/api/label/preview')
    expect(fetchSpy.mock.calls[0][1].method).toBe('POST')
  })
})
