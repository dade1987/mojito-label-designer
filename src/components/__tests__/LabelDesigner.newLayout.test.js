import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import LabelDesigner from '../LabelDesigner.vue'
import LabelCanvas from '../LabelCanvas.vue'

/**
 * Il pulsante "+ Nuovo layout" creava il layout ma poi si fermava a metà:
 * azzerava la selezione con un nome di variabile che nel componente non
 * esiste, quindi il pannello proprietà restava sull'elemento vecchio e il
 * messaggio "Nuovo layout pronto" non compariva mai. Qui si clicca il
 * pulsante per davvero e si controlla che arrivi fino in fondo.
 */

vi.mock('../../utils/api.js', () => ({
  fetchPrinters: async () => ({
    printers: ['Citizen CL-S703Z'],
    printerResolutions: {},
    printerModes: {},
    platform: 'Linux',
  }),
  fetchDefaultTemplate: async () => ({
    id: 'default',
    name: 'Default',
    labelWidth: 400,
    labelHeight: 200,
    dpi: 203,
    dataSources: [],
    elements: [{ id: 'e1', type: 'text', x: 0, y: 0, text: 'ciao' }],
  }),
  fetchTemplates: async () => ({ templates: [] }),
  fetchTemplate: async () => null,
  saveTemplate: async () => ({}),
  deleteTemplate: async () => ({}),
  previewZpl: async () => ({ zpl: '^XA^XZ' }),
  previewLabelImage: async () => ({ png: 'data:image/png;base64,AAAA', width: 400, height: 200 }),
  printLabel: async () => ({}),
  fetchAuthStatus: async () => ({ passwordRequired: false, authenticated: true }),
  checkPassword: async () => ({}),
}))

async function mountDesigner() {
  const wrapper = mount(LabelDesigner, { attachTo: document.body })
  await flushPromises()

  return wrapper
}

function newLayoutButton(wrapper) {
  return wrapper.findAll('button').find((button) => button.text().includes('Nuovo layout'))
}

describe('LabelDesigner — nuovo layout', () => {
  let errorSpy

  beforeEach(() => {
    localStorage.clear()
    sessionStorage.clear()
    errorSpy = vi.spyOn(console, 'error').mockImplementation(() => {})
    vi.spyOn(window, 'confirm').mockReturnValue(true)
  })

  afterEach(() => {
    vi.restoreAllMocks()
  })

  it('azzera la selezione, svuota il foglio e avvisa che il layout è pronto', async () => {
    const wrapper = await mountDesigner()

    // Selezione fatta dal canvas, come farebbe un click sull'elemento.
    wrapper.findComponent(LabelCanvas).vm.$emit('update:selectedIds', ['e1'])
    await flushPromises()
    expect(wrapper.find('.properties').exists()).toBe(true)

    await newLayoutButton(wrapper).trigger('click')
    await flushPromises()

    expect(window.confirm).toHaveBeenCalledTimes(1)
    expect(wrapper.find('.properties').exists()).toBe(false)
    expect(wrapper.findComponent(LabelCanvas).props('selectedIds')).toEqual([])
    expect(wrapper.findComponent(LabelCanvas).props('template').elements).toEqual([])
    expect(wrapper.find('.status').text()).toContain('Nuovo layout pronto')
    expect(errorSpy).not.toHaveBeenCalled()
  })

  it('se si rinuncia alla conferma non tocca niente', async () => {
    window.confirm.mockReturnValue(false)
    const wrapper = await mountDesigner()

    wrapper.findComponent(LabelCanvas).vm.$emit('update:selectedIds', ['e1'])
    await flushPromises()

    await newLayoutButton(wrapper).trigger('click')
    await flushPromises()

    expect(wrapper.find('.properties').exists()).toBe(true)
    expect(wrapper.findComponent(LabelCanvas).props('template').elements).toHaveLength(1)
    expect(wrapper.find('.status').exists()).toBe(false)
  })
})
