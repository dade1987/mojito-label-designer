import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import LabelDesigner from '../LabelDesigner.vue'

/**
 * La strada di stampa segue la stampante: quando il server la conosce, il
 * layout si mette da solo su ZPL o su stampa normale, e se qualcuno la cambia
 * a mano resta l'avviso con il rimedio a un click.
 */

vi.mock('../../utils/api.js', () => ({
  fetchPrinters: async () => ({
    printers: ['Citizen_CL_S703Z', 'Munbyn ITPP941P', 'Stampante Ufficio'],
    printerResolutions: {},
    printerModes: { Citizen_CL_S703Z: 'zpl', 'Munbyn ITPP941P': 'graphic' },
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

function printerSelect(wrapper) {
  return wrapper.find('.printer-field select')
}

function printModeSelect(wrapper) {
  return wrapper.findAll('select').find((select) =>
    select.findAll('option').some((option) => option.attributes('value') === 'graphic'),
  )
}

describe('LabelDesigner — strada di stampa automatica', () => {
  beforeEach(() => {
    localStorage.clear()
    sessionStorage.clear()
  })

  it('parte dalla Citizen, che parla ZPL', async () => {
    const wrapper = await mountDesigner()

    expect(printerSelect(wrapper).element.value).toBe('Citizen_CL_S703Z')
    expect(wrapper.vm.template.printMode ?? 'zpl').toBe('zpl')
    expect(wrapper.text()).toContain('impostato in automatico')
  })

  it('passando alla Munbyn il layout va da solo in stampa normale', async () => {
    const wrapper = await mountDesigner()

    await printerSelect(wrapper).setValue('Munbyn ITPP941P')
    await flushPromises()

    expect(wrapper.vm.template.printMode).toBe('graphic')
    expect(wrapper.find('.status').text()).toContain('stampa normale')
  })

  it('se si forza a mano una strada diversa avvisa e la rimette a posto con un click', async () => {
    const wrapper = await mountDesigner()

    await printerSelect(wrapper).setValue('Munbyn ITPP941P')
    await flushPromises()
    await printModeSelect(wrapper).setValue('zpl')
    await flushPromises()

    expect(wrapper.vm.template.printMode).toBe('zpl')
    const warning = wrapper.find('.warn-box')
    expect(warning.text()).toContain('Munbyn ITPP941P vuole')

    await warning.find('button').trigger('click')
    await flushPromises()

    expect(wrapper.vm.template.printMode).toBe('graphic')
  })

  it('con una stampante sconosciuta lascia decidere e non avvisa', async () => {
    const wrapper = await mountDesigner()

    await printerSelect(wrapper).setValue('Stampante Ufficio')
    await flushPromises()

    expect(wrapper.vm.template.printMode ?? 'zpl').toBe('zpl')
    expect(wrapper.find('.warn-box').exists()).toBe(false)
    expect(wrapper.text()).not.toContain('impostato in automatico')
  })
})

describe('LabelDesigner — sezioni dei pannelli', () => {
  beforeEach(() => {
    localStorage.clear()
  })

  it('le sezioni si chiudono dal titolo e la scelta resta salvata', async () => {
    const wrapper = await mountDesigner()
    const layout = wrapper.findAll('details.section').find((node) => node.find('summary').text() === 'Layout')

    expect(layout.element.open).toBe(true)

    layout.element.open = false
    await layout.trigger('toggle')

    expect(wrapper.vm.sections.layout).toBe(false)
    expect(JSON.parse(localStorage.getItem('mojito:panel-sections')).layout).toBe(false)
  })

  it('riapre il designer con le sezioni come erano state lasciate', async () => {
    localStorage.setItem('mojito:panel-sections', JSON.stringify({ properties: false }))

    const wrapper = await mountDesigner()
    const properties = wrapper.findAll('details.section').find((node) => node.find('summary').text() === 'Proprietà')

    expect(properties.element.open).toBe(false)
  })
})
