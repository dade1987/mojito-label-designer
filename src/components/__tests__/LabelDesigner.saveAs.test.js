import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import LabelDesigner from '../LabelDesigner.vue'
import LabelCanvas from '../LabelCanvas.vue'

/**
 * Salvare non deve mai buttare via un layout per sbaglio.
 *
 * Prima "Salva server" e "Salva locale" scrivevano sempre sopra il layout con
 * lo stesso identificativo: chi apriva un'etichetta, la rinominava e salvava
 * perdeva l'originale senza avviso; e chi partiva dal template di default lo
 * salvava sempre con lo stesso identificativo, pestando quello delle altre
 * postazioni. Ora si chiede conferma quando il nome è cambiato, c'è
 * "Salva con nome…" per crearne uno nuovo, e il server rifiuta (409) le
 * sovrascritture non richieste.
 */

const serverTemplates = []
const fullTemplates = {}
const saveCalls = []
let nextSaveError = null

vi.mock('../../utils/api.js', () => ({
  fetchPrinters: async () => ({
    printers: ['Citizen CL-S703Z'],
    printerResolutions: {},
    printerModes: {},
    platform: 'Linux',
  }),
  fetchDefaultTemplate: async () => ({
    id: 'cavallini-service',
    name: 'Cavallini Service',
    labelWidth: 400,
    labelHeight: 200,
    dpi: 203,
    dataSources: [],
    elements: [],
  }),
  fetchTemplates: async () => ({ templates: serverTemplates.map((item) => ({ ...item })) }),
  fetchTemplate: async (id) => (fullTemplates[id] ? { ...fullTemplates[id] } : null),
  saveTemplate: async (template, options) => {
    saveCalls.push([JSON.parse(JSON.stringify(template)), options])

    if (nextSaveError) {
      const error = nextSaveError
      nextSaveError = null
      throw error
    }

    const stored = JSON.parse(JSON.stringify(template))
    delete stored.overwrite
    fullTemplates[stored.id] = stored
    const index = serverTemplates.findIndex((item) => item.id === stored.id)
    const summary = { id: stored.id, name: stored.name, updatedAt: '2026-09-04T10:00:00+02:00' }
    if (index >= 0) serverTemplates[index] = summary
    else serverTemplates.push(summary)

    return { status: 'saved', template: stored }
  },
  deleteTemplate: async () => ({}),
  previewZpl: async () => ({ zpl: '^XA^XZ' }),
  previewLabelImage: async () => ({ png: 'data:image/png;base64,AAAA', width: 400, height: 200 }),
  printLabel: async () => ({}),
  fetchAuthStatus: async () => ({ passwordRequired: false, authenticated: true }),
  checkPassword: async () => ({}),
}))

const layoutA = {
  id: 'srv-1',
  name: 'Etichetta A',
  labelWidth: 400,
  labelHeight: 200,
  dpi: 203,
  dataSources: [{ name: 'seriale', label: 'Seriale', defaultValue: '1' }],
  elements: [{ id: 'e1', type: 'text', x: 0, y: 0, dataSource: 'seriale' }],
}

async function mountDesigner() {
  const wrapper = mount(LabelDesigner, { attachTo: document.body })
  await flushPromises()

  return wrapper
}

async function mountWithServerLayoutOpen() {
  localStorage.setItem('mojito-last-layout-id', 'server:srv-1')

  return mountDesigner()
}

function button(wrapper, text) {
  return wrapper.findAll('button').find((node) => node.text().trim().startsWith(text))
}

function nameInput(wrapper) {
  return wrapper.findAll('label').find((node) => node.text().startsWith('Nome layout')).find('input')
}

function layoutSelect(wrapper) {
  return wrapper
    .findAll('select')
    .find((select) => select.findAll('option').some((option) => option.attributes('value')?.startsWith('server:')))
}

function currentTemplate(wrapper) {
  return wrapper.findComponent(LabelCanvas).props('template')
}

function status(wrapper) {
  return wrapper.find('.status')
}

describe('LabelDesigner — salvataggio senza sovrascrivere', () => {
  beforeEach(() => {
    localStorage.clear()
    sessionStorage.clear()
    serverTemplates.length = 0
    serverTemplates.push({ id: 'srv-1', name: 'Etichetta A', updatedAt: '2026-09-01T10:00:00+02:00' })
    for (const key of Object.keys(fullTemplates)) delete fullTemplates[key]
    fullTemplates['srv-1'] = { ...layoutA }
    saveCalls.length = 0
    nextSaveError = null
    vi.spyOn(console, 'error').mockImplementation(() => {})
  })

  afterEach(() => {
    vi.restoreAllMocks()
  })

  describe('il template di partenza', () => {
    it('non porta con sé l\'identificativo del default: il primo salvataggio crea un layout nuovo', async () => {
      const wrapper = await mountDesigner()

      expect(currentTemplate(wrapper).name).toBe('Cavallini Service')
      expect(currentTemplate(wrapper).id).not.toBe('cavallini-service')
      expect(currentTemplate(wrapper).id).toMatch(/^[0-9a-f-]{36}$/)
    })

    it('salvandolo sul server non chiede nulla e non chiede di sovrascrivere', async () => {
      const confirmSpy = vi.spyOn(window, 'confirm')
      const wrapper = await mountDesigner()

      await button(wrapper, 'Salva server').trigger('click')
      await flushPromises()

      expect(confirmSpy).not.toHaveBeenCalled()
      expect(saveCalls).toHaveLength(1)
      expect(saveCalls[0][0].id).not.toBe('cavallini-service')
      expect(saveCalls[0][1]).toEqual({ overwrite: false })
      expect(status(wrapper).text()).toBe('Layout salvato sul server: Cavallini Service')
    })
  })

  describe('"Salva server"', () => {
    it('aggiorna senza chiedere il layout aperto se il nome non è cambiato', async () => {
      const confirmSpy = vi.spyOn(window, 'confirm')
      const wrapper = await mountWithServerLayoutOpen()
      expect(currentTemplate(wrapper).id).toBe('srv-1')

      await button(wrapper, 'Salva server').trigger('click')
      await flushPromises()

      expect(confirmSpy).not.toHaveBeenCalled()
      expect(saveCalls).toHaveLength(1)
      expect(saveCalls[0][0].id).toBe('srv-1')
      expect(saveCalls[0][1]).toEqual({ overwrite: true })
      expect(status(wrapper).text()).toBe('Layout salvato sul server: Etichetta A')
    })

    it('se il nome è cambiato chiede prima di perdere l\'originale, e su Annulla non salva', async () => {
      const confirmSpy = vi.spyOn(window, 'confirm').mockReturnValue(false)
      const wrapper = await mountWithServerLayoutOpen()

      await nameInput(wrapper).setValue('Etichetta B')
      await button(wrapper, 'Salva server').trigger('click')
      await flushPromises()

      expect(confirmSpy).toHaveBeenCalledTimes(1)
      expect(confirmSpy.mock.calls[0][0]).toContain('«Etichetta A»')
      expect(confirmSpy.mock.calls[0][0]).toContain('«Etichetta B»')
      expect(confirmSpy.mock.calls[0][0]).toContain('sul server')
      expect(saveCalls).toHaveLength(0)
      expect(status(wrapper).text()).toContain('Salva con nome')
      expect(currentTemplate(wrapper).id).toBe('srv-1')
    })

    it('se il nome è cambiato e si conferma, sovrascrive', async () => {
      vi.spyOn(window, 'confirm').mockReturnValue(true)
      const wrapper = await mountWithServerLayoutOpen()

      await nameInput(wrapper).setValue('Etichetta B')
      await button(wrapper, 'Salva server').trigger('click')
      await flushPromises()

      expect(saveCalls).toHaveLength(1)
      expect(saveCalls[0][0]).toMatchObject({ id: 'srv-1', name: 'Etichetta B' })
      expect(saveCalls[0][1]).toEqual({ overwrite: true })
      expect(status(wrapper).text()).toBe('Layout salvato sul server: Etichetta B')
    })

    it('se un\'altra postazione ha creato lo stesso identificativo nel frattempo, mostra il rifiuto del server', async () => {
      const wrapper = await mountDesigner()
      nextSaveError = Object.assign(new Error('Sul server esiste già il layout «Altro» con questo identificativo'), {
        status: 409,
      })

      await button(wrapper, 'Salva server').trigger('click')
      await flushPromises()

      expect(status(wrapper).classes()).toContain('error')
      expect(status(wrapper).text()).toContain('«Altro»')
    })
  })

  describe('"Salva con nome…"', () => {
    it('propone un nome libero, salva una copia con identificativo nuovo e la apre', async () => {
      const promptSpy = vi.spyOn(window, 'prompt').mockReturnValue('Etichetta A bis')
      const wrapper = await mountWithServerLayoutOpen()

      await button(wrapper, 'Salva con nome').trigger('click')
      await flushPromises()

      expect(promptSpy).toHaveBeenCalledTimes(1)
      expect(promptSpy.mock.calls[0][1]).toBe('Etichetta A (copia)')
      expect(saveCalls).toHaveLength(1)
      const [saved, options] = saveCalls[0]
      expect(saved.id).not.toBe('srv-1')
      expect(saved.name).toBe('Etichetta A bis')
      expect(saved.elements).toEqual(layoutA.elements)
      expect(options).toEqual({ overwrite: false })

      expect(currentTemplate(wrapper).id).toBe(saved.id)
      expect(currentTemplate(wrapper).name).toBe('Etichetta A bis')
      expect(localStorage.getItem('mojito-last-layout-id')).toBe(`server:${saved.id}`)
      expect(layoutSelect(wrapper).element.value).toBe(`server:${saved.id}`)
      expect(wrapper.text()).toContain('[Server] Etichetta A bis')
      expect(status(wrapper).text()).toBe('Nuovo layout salvato sul server: Etichetta A bis')
      expect(fullTemplates['srv-1'].name).toBe('Etichetta A')
    })

    it('propone il nome così com\'è se sul server non c\'è ancora', async () => {
      const promptSpy = vi.spyOn(window, 'prompt').mockReturnValue(null)
      const wrapper = await mountDesigner()

      await button(wrapper, 'Salva con nome').trigger('click')
      await flushPromises()

      expect(promptSpy.mock.calls[0][1]).toBe('Cavallini Service')
      expect(saveCalls).toHaveLength(0)
    })

    it('con Annulla non salva niente', async () => {
      vi.spyOn(window, 'prompt').mockReturnValue(null)
      const wrapper = await mountWithServerLayoutOpen()

      await button(wrapper, 'Salva con nome').trigger('click')
      await flushPromises()

      expect(saveCalls).toHaveLength(0)
      expect(currentTemplate(wrapper).id).toBe('srv-1')
    })

    it('senza nome non salva e lo dice', async () => {
      vi.spyOn(window, 'prompt').mockReturnValue('   ')
      const wrapper = await mountWithServerLayoutOpen()

      await button(wrapper, 'Salva con nome').trigger('click')
      await flushPromises()

      expect(saveCalls).toHaveLength(0)
      expect(status(wrapper).classes()).toContain('error')
      expect(status(wrapper).text()).toContain('nome')
    })

    it('rifiuta un nome già usato da un altro layout sul server', async () => {
      vi.spyOn(window, 'prompt').mockReturnValue(' etichetta a ')
      const wrapper = await mountWithServerLayoutOpen()

      await button(wrapper, 'Salva con nome').trigger('click')
      await flushPromises()

      expect(saveCalls).toHaveLength(0)
      expect(status(wrapper).classes()).toContain('error')
      expect(status(wrapper).text()).toContain('«Etichetta A»')
    })

    it('mostra l\'errore se il server rifiuta', async () => {
      vi.spyOn(window, 'prompt').mockReturnValue('Etichetta C')
      const wrapper = await mountWithServerLayoutOpen()
      nextSaveError = new Error('Impossibile salvare il template.')

      await button(wrapper, 'Salva con nome').trigger('click')
      await flushPromises()

      expect(status(wrapper).classes()).toContain('error')
      expect(status(wrapper).text()).toBe('Impossibile salvare il template.')
      expect(currentTemplate(wrapper).id).toBe('srv-1')
    })
  })

  describe('"Salva locale"', () => {
    it('salva la copia locale senza chiedere se il nome non è cambiato', async () => {
      const confirmSpy = vi.spyOn(window, 'confirm')
      const wrapper = await mountWithServerLayoutOpen()

      await button(wrapper, 'Salva locale').trigger('click')
      await flushPromises()
      await button(wrapper, 'Salva locale').trigger('click')
      await flushPromises()

      expect(confirmSpy).not.toHaveBeenCalled()
      const stored = JSON.parse(localStorage.getItem('mojito-layouts'))
      expect(stored).toHaveLength(1)
      expect(stored[0]).toMatchObject({ id: 'srv-1', name: 'Etichetta A' })
    })

    it('se il nome è cambiato chiede prima di sovrascrivere la copia locale', async () => {
      const confirmSpy = vi.spyOn(window, 'confirm').mockReturnValue(false)
      const wrapper = await mountWithServerLayoutOpen()

      await button(wrapper, 'Salva locale').trigger('click')
      await flushPromises()
      await nameInput(wrapper).setValue('Etichetta B')
      await button(wrapper, 'Salva locale').trigger('click')
      await flushPromises()

      expect(confirmSpy).toHaveBeenCalledTimes(1)
      expect(confirmSpy.mock.calls[0][0]).toContain('in locale')
      expect(confirmSpy.mock.calls[0][0]).toContain('«Etichetta A»')
      expect(JSON.parse(localStorage.getItem('mojito-layouts'))[0].name).toBe('Etichetta A')
      expect(status(wrapper).text()).toContain('Salva con nome')

      confirmSpy.mockReturnValue(true)
      await button(wrapper, 'Salva locale').trigger('click')
      await flushPromises()

      expect(JSON.parse(localStorage.getItem('mojito-layouts'))[0].name).toBe('Etichetta B')
      expect(status(wrapper).text()).toBe('Layout salvato in locale: Etichetta B')
    })
  })
})
