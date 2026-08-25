import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import LabelDesigner from '../LabelDesigner.vue'

/**
 * La stampa dal designer: la scelta fra comandi ZPL e stampa normale di
 * sistema, e la tirata di etichette numerate con quantità e intervallo di
 * numeri di serie.
 */

const printCalls = []
const imagePreviewCalls = []

vi.mock('../../utils/api.js', () => ({
  fetchPrinters: async () => ({
    printers: ['Munbyn ITPP941P'],
    printerResolutions: {},
    platform: 'Linux',
  }),
  fetchDefaultTemplate: async () => ({
    id: 'default',
    name: 'Default',
    labelWidth: 400,
    labelHeight: 200,
    dpi: 203,
    dataSources: [
      { name: 'lotto', label: 'Lotto', defaultValue: 'CHL1225' },
      { name: 'seriale', label: 'Seriale', defaultValue: '1' },
    ],
    elements: [{ id: 'e1', type: 'text', x: 0, y: 0, dataSource: 'seriale' }],
  }),
  fetchTemplates: async () => ({ templates: [] }),
  fetchTemplate: async () => null,
  saveTemplate: async () => ({}),
  deleteTemplate: async () => ({}),
  previewZpl: async () => ({ zpl: '^XA^XZ' }),
  previewLabelImage: async (body) => {
    imagePreviewCalls.push(body)

    return { png: 'data:image/png;base64,AAAA', width: 400, height: 200 }
  },
  printLabel: async (body) => {
    printCalls.push(body)

    return {}
  },
  fetchAuthStatus: async () => ({ passwordRequired: false, authenticated: true }),
  checkPassword: async () => ({}),
}))

async function mountDesigner() {
  const wrapper = mount(LabelDesigner, { attachTo: document.body })
  await flushPromises()

  return wrapper
}

function selectByOption(wrapper, optionValue) {
  return wrapper.findAll('select').find((select) =>
    select.findAll('option').some((option) => option.attributes('value') === optionValue),
  )
}

function batchInput(wrapper, label) {
  return wrapper
    .findAll('.batch-panel label')
    .find((node) => node.text().startsWith(label))
    ?.find('input')
}

describe('LabelDesigner — stampa', () => {
  beforeEach(() => {
    localStorage.clear()
    sessionStorage.clear()
    printCalls.length = 0
    imagePreviewCalls.length = 0
  })

  it('di default stampa in ZPL, come è sempre stato', async () => {
    const wrapper = await mountDesigner()

    await wrapper.findAll('button').find((button) => button.text() === 'Stampa etichetta').trigger('click')
    await flushPromises()

    expect(printCalls).toHaveLength(1)
    expect(printCalls[0].printMode).toBe('zpl')
  })

  it('scegliendo la stampa normale il layout la ricorda e la usa', async () => {
    const wrapper = await mountDesigner()

    await selectByOption(wrapper, 'graphic').setValue('graphic')
    await flushPromises()

    expect(wrapper.vm.template.printMode).toBe('graphic')

    await wrapper.findAll('button').find((button) => button.text() === 'Stampa etichetta').trigger('click')
    await flushPromises()

    expect(printCalls[0].printMode).toBe('graphic')
  })

  it('in stampa normale mostra l\'anteprima disegnata dell\'etichetta', async () => {
    const wrapper = await mountDesigner()

    await selectByOption(wrapper, 'graphic').setValue('graphic')
    await flushPromises()
    await flushPromises()

    expect(imagePreviewCalls.length).toBeGreaterThan(0)
    expect(wrapper.find('.label-image-preview').attributes('src')).toBe('data:image/png;base64,AAAA')
  })

  it('sceglie da sola una sorgente dati sensata per il numero di serie', async () => {
    const wrapper = await mountDesigner()

    expect(wrapper.vm.batch.dataSource).toBe('seriale')
  })

  it('riassume quante etichette partiranno prima di stamparle', async () => {
    const wrapper = await mountDesigner()

    await batchInput(wrapper, 'Da').setValue(1)
    await batchInput(wrapper, 'A').setValue(96)
    await batchInput(wrapper, 'Copie').setValue(2)

    expect(wrapper.find('.batch-panel .hint').text()).toContain('96 etichette × 2 copie = 192 stampe')
  })

  it('stampa la serie in una sola richiesta, un lavoro per numero', async () => {
    const wrapper = await mountDesigner()

    await batchInput(wrapper, 'Da').setValue(1)
    await batchInput(wrapper, 'A').setValue(3)
    await batchInput(wrapper, 'Prefisso').setValue('CHL1225')
    await batchInput(wrapper, 'Copie').setValue(2)

    await wrapper.findAll('button').find((button) => button.text() === 'Stampa serie').trigger('click')
    await flushPromises()

    expect(printCalls).toHaveLength(1)
    expect(printCalls[0].copies).toBe(2)
    expect(printCalls[0].jobs).toEqual([
      { seriale: 'CHL12251' },
      { seriale: 'CHL12252' },
      { seriale: 'CHL12253' },
    ])
  })

  it('non lascia stampare un intervallo al contrario', async () => {
    const wrapper = await mountDesigner()

    await batchInput(wrapper, 'Da').setValue(10)
    await batchInput(wrapper, 'A').setValue(1)

    const button = wrapper.findAll('button').find((node) => node.text() === 'Stampa serie')
    expect(button.attributes('disabled')).toBeDefined()
    expect(wrapper.find('.batch-panel .hint').text()).toContain('nessuna etichetta')
  })
})
