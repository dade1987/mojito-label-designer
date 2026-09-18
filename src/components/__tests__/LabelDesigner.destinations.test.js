import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import LabelDesigner from '../LabelDesigner.vue'

/**
 * Dal designer si sceglie dove stampare: le stampanti del server, quelle di
 * rete e quelle collegate ai PC di reparto (stampate dal loro agente).
 */

const printCalls = []
let printResult = {}

vi.mock('../../utils/api.js', () => ({
  fetchPrinters: async () => ({
    printers: ['pc:SURFACE9|Citizen CL-S703', 'Brother', 'Citizen_CL_S703Z', 'ip:192.168.1.50:9100'],
    printerLabels: {
      'pc:SURFACE9|Citizen CL-S703': 'SURFACE9 · Citizen CL-S703',
      'ip:192.168.1.50:9100': 'Rete · 192.168.1.50:9100',
    },
    printerModes: {
      'pc:SURFACE9|Citizen CL-S703': 'zpl',
      'ip:192.168.1.50:9100': 'zpl',
    },
    printerResolutions: {},
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
  printLabel: async (body) => {
    printCalls.push(body)
    return printResult
  },
  fetchAuthStatus: async () => ({ passwordRequired: false, authenticated: true }),
  checkPassword: async () => ({}),
}))

async function mountDesigner() {
  const wrapper = mount(LabelDesigner, { attachTo: document.body })
  await flushPromises()
  return wrapper
}

function printerSelect(wrapper) {
  return wrapper.find('.printer-field select')
}

describe('LabelDesigner: dove stampare', () => {
  let wrapper

  beforeEach(() => {
    printCalls.length = 0
    printResult = {}
    localStorage.clear()
  })

  afterEach(() => {
    wrapper?.unmount()
    vi.restoreAllMocks()
  })

  it('divide le stampanti per dove si trovano, con nomi leggibili', async () => {
    wrapper = await mountDesigner()

    const groups = printerSelect(wrapper).findAll('optgroup')
    expect(groups.map((g) => g.attributes('label'))).toEqual([
      'Stampanti del server',
      'Stampanti di rete',
      'Stampanti dei PC (agente di stampa)',
    ])
    expect(groups[2].text()).toContain('SURFACE9 · Citizen CL-S703')
    expect(groups[1].text()).toContain('Rete · 192.168.1.50:9100')
  })

  /**
   * Anche "SURFACE9 · Citizen" contiene "citizen": la stampante di serie deve
   * restare quella del server, o si stamperebbe in reparto per sbaglio.
   */
  it('di serie sceglie la Citizen del server, non quella del PC', async () => {
    wrapper = await mountDesigner()

    expect(printerSelect(wrapper).element.value).toBe('Citizen_CL_S703Z')
  })

  it('stampando su un PC dice che l\'etichetta e\' in coda per il suo agente', async () => {
    printResult = { status: 'queued', printed: 1 }
    wrapper = await mountDesigner()

    await printerSelect(wrapper).setValue('pc:SURFACE9|Citizen CL-S703')
    await flushPromises()
    const printButton = wrapper.findAll('button').find((b) => b.text() === 'Stampa etichetta')
    await printButton.trigger('click')
    await flushPromises()

    expect(printCalls.at(-1).printer).toBe('pc:SURFACE9|Citizen CL-S703')
    expect(wrapper.text()).toContain('Etichetta in coda per SURFACE9 · Citizen CL-S703: la stampa l\'agente di quel PC')
    // Rete e PC ricevono ZPL: il layout si e' messo in ZPL da solo.
    expect(printCalls.at(-1).printMode).toBe('zpl')
  })

  it('si aggiunge una stampante di rete e il browser se la ricorda', async () => {
    vi.spyOn(window, 'prompt').mockReturnValue('192.168.1.60')
    wrapper = await mountDesigner()

    await wrapper.find('.add-network-printer').trigger('click')
    await flushPromises()

    expect(printerSelect(wrapper).element.value).toBe('ip:192.168.1.60:9100')
    expect(printerSelect(wrapper).text()).toContain('Rete · 192.168.1.60:9100')
    expect(JSON.parse(localStorage.getItem('mojito.networkPrinters'))).toEqual(['ip:192.168.1.60:9100'])

    // Ricaricando il designer c'e' ancora.
    wrapper.unmount()
    wrapper = await mountDesigner()
    expect(printerSelect(wrapper).text()).toContain('Rete · 192.168.1.60:9100')
  })

  it('un indirizzo sbagliato non aggiunge niente e spiega perche\'', async () => {
    vi.spyOn(window, 'prompt').mockReturnValue('stampante.local')
    wrapper = await mountDesigner()

    await wrapper.find('.add-network-printer').trigger('click')
    await flushPromises()

    expect(wrapper.text()).toContain('non e\' un indirizzo IP')
    expect(printerSelect(wrapper).element.value).toBe('Citizen_CL_S703Z')
  })

  it('annullando la richiesta non cambia niente', async () => {
    vi.spyOn(window, 'prompt').mockReturnValue(null)
    wrapper = await mountDesigner()

    await wrapper.find('.add-network-printer').trigger('click')
    await flushPromises()

    expect(printerSelect(wrapper).element.value).toBe('Citizen_CL_S703Z')
    expect(localStorage.getItem('mojito.networkPrinters')).toBeNull()
  })
})
