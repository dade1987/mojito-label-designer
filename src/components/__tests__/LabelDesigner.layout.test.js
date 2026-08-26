import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import LabelDesigner from '../LabelDesigner.vue'

/**
 * I pannelli laterali stanno tutti a sinistra, uno sotto l'altro, e ognuno si
 * apre e si chiude dal titolo: la colonna di destra era stretta, sempre da
 * scorrere, e rubava larghezza al canvas.
 */

vi.mock('../../utils/api.js', () => ({
  fetchPrinters: async () => ({
    printers: ['Munbyn ITPP941P'],
    printerResolutions: {},
    printerModes: { 'Munbyn ITPP941P': 'graphic' },
    platform: 'Linux',
  }),
  fetchDefaultTemplate: async () => ({
    id: 'default',
    name: 'Default',
    labelWidth: 400,
    labelHeight: 200,
    dpi: 203,
    dataSources: [{ name: 'seriale', label: 'Seriale', defaultValue: '1' }],
    elements: [{ id: 'e1', type: 'text', x: 0, y: 0, dataSource: 'seriale' }],
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

describe('LabelDesigner — pannelli laterali', () => {
  beforeEach(() => {
    localStorage.clear()
    sessionStorage.clear()
  })

  it('c\'è una sola colonna di pannelli, a sinistra del canvas', async () => {
    const wrapper = await mountDesigner()

    expect(wrapper.findAll('.workspace .panel')).toHaveLength(1)
    expect(wrapper.find('.panel').classes()).toContain('left')
    expect(wrapper.find('.panel.right').exists()).toBe(false)
  })

  it('apre con stampa, layout e proprietà nell\'ordine in cui si usano', async () => {
    const wrapper = await mountDesigner()

    const titles = wrapper.findAll('.panel .section > summary').map((node) => node.text())

    expect(titles.slice(0, 3)).toEqual(['Stampa manuale', 'Layout', 'Proprietà'])
    expect(titles).toContain('Etichetta')
    expect(titles).toContain('Elementi')
  })

  it('ogni sezione si può aprire e nascondere dal suo titolo', async () => {
    const wrapper = await mountDesigner()
    const sections = wrapper.findAll('.panel .section')

    expect(sections.length).toBeGreaterThan(4)

    for (const section of sections) {
      expect(section.element.tagName).toBe('DETAILS')
      expect(section.find('summary').exists()).toBe(true)
    }
  })

  it('nessuna sezione resta fuori dal pannello scorrevole', async () => {
    const wrapper = await mountDesigner()

    const inScroll = wrapper.findAll('.panel-scroll .section').length
    const total = wrapper.findAll('.panel .section').length

    expect(inScroll).toBe(total)
  })
})
