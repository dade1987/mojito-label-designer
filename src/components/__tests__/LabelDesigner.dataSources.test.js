import { describe, expect, it, vi, beforeEach } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import LabelDesigner from '../LabelDesigner.vue'

/**
 * Il pannello NAMED DATA SOURCES deve lasciar scrivere: prima, digitando nel
 * campo nome, ogni tasto mutava il nome via v-model, la key della riga
 * cambiava, Vue ricreava la riga e il focus usciva dal campo a ogni keydown.
 * In piu' la rinomina partiva col nome gia' mutato e falliva in silenzio:
 * gli elementi restavano legati al vecchio nome e al ricaricamento la
 * rinomina (e il valore) sparivano.
 */

// Funzioni semplici, NON vi.fn: il setup globale fa vi.restoreAllMocks()
// dopo ogni test e azzererebbe le implementazioni dal secondo test in poi.
vi.mock('../../utils/api.js', () => ({
  fetchPrinters: async () => ({
    printers: ['Apix 251 (600DPI)'],
    printerResolutions: {},
    platform: 'Linux',
  }),
  fetchDefaultTemplate: async () => ({
    id: 'default',
    name: 'Default',
    labelWidth: 600,
    labelHeight: 400,
    dpi: 203,
    dataSources: [
      { name: 'barcode_1', label: 'Barcode', defaultValue: 'ABC123' },
      { name: 'text_1', label: 'Testo', defaultValue: '999' },
    ],
    elements: [
      { id: 'e1', type: 'barcode', x: 0, y: 0, dataSource: 'barcode_1' },
      { id: 'e2', type: 'text', x: 0, y: 50, dataSource: 'text_1' },
    ],
  }),
  fetchTemplates: async () => ({ templates: [] }),
  fetchTemplate: async () => null,
  saveTemplate: async () => ({}),
  deleteTemplate: async () => ({}),
  previewZpl: async () => ({ zpl: '' }),
  printLabel: async () => ({}),
  fetchAuthStatus: async () => ({ passwordRequired: false, authenticated: true }),
  checkPassword: async () => ({}),
}))

async function mountDesigner() {
  const wrapper = mount(LabelDesigner, { attachTo: document.body })
  await flushPromises()
  return wrapper
}

function nameInputs(wrapper) {
  return wrapper.findAll('input[placeholder="nome_variabile"]')
}

function valueInputs(wrapper) {
  return wrapper.findAll('input[placeholder="Valore di test"]')
}

describe('LabelDesigner — pannello data source', () => {
  beforeEach(() => {
    localStorage.clear()
    sessionStorage.clear()
  })

  it('digitare nel campo nome non fa perdere il focus e non muta finché non confermi', async () => {
    const wrapper = await mountDesigner()
    const input = nameInputs(wrapper)[0]
    input.element.focus()

    // Simula la digitazione tasto per tasto (solo eventi input, come il browser).
    for (const partial of ['c', 'co', 'cod']) {
      input.element.value = partial
      await input.trigger('input')
    }

    expect(document.activeElement).toBe(input.element)
    expect(input.element.isConnected).toBe(true)
    // Il template non deve essere ancora stato toccato.
    expect(wrapper.vm.template.dataSources[0].name).toBe('barcode_1')
    expect(wrapper.vm.template.elements[0].dataSource).toBe('barcode_1')

    wrapper.unmount()
  })

  it('la conferma (change) rinomina la sorgente E aggiorna gli elementi collegati', async () => {
    const wrapper = await mountDesigner()
    const input = nameInputs(wrapper)[0]

    input.element.value = 'codice_lotto_interno'
    await input.trigger('change')
    await flushPromises()

    expect(wrapper.vm.template.dataSources[0].name).toBe('codice_lotto_interno')
    expect(wrapper.vm.template.elements[0].dataSource).toBe('codice_lotto_interno')

    wrapper.unmount()
  })

  it('un nome duplicato viene rifiutato e il campo torna al nome attuale', async () => {
    const wrapper = await mountDesigner()
    const input = nameInputs(wrapper)[0]

    input.element.value = 'text_1'
    await input.trigger('change')
    await flushPromises()

    expect(wrapper.vm.template.dataSources[0].name).toBe('barcode_1')
    expect(input.element.value).toBe('barcode_1')

    wrapper.unmount()
  })

  it('digitare nel campo valore non fa perdere il focus e il valore resta', async () => {
    const wrapper = await mountDesigner()
    const input = valueInputs(wrapper)[0]
    input.element.focus()

    for (const partial of ['C', 'CH', 'CHL']) {
      input.element.value = partial
      await input.trigger('input')
    }

    expect(document.activeElement).toBe(input.element)
    expect(input.element.isConnected).toBe(true)
    expect(wrapper.vm.template.dataSources[0].defaultValue).toBe('CHL')

    wrapper.unmount()
  })
})
