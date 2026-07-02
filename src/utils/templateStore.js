import { cloneTemplateState } from './cloneSerializable.js'
import { findEmptyPlacement, placeNewElement } from './elementPlacement.js'

const DUPLICATE_OFFSET = 20
const DATA_BOUND_TYPES = ['text', 'barcode', 'qr']

function isDataBoundElement(element) {
  return DATA_BOUND_TYPES.includes(element.type)
}

function dataSourceNaming(type) {
  if (type === 'text') {
    return { base: 'text', labelPrefix: 'Testo' }
  }

  if (type === 'qr') {
    return { base: 'qr', labelPrefix: 'QR' }
  }

  return { base: 'barcode', labelPrefix: 'Barcode' }
}

export function createEmptyTemplate() {
  return {
    name: 'Nuova etichetta',
    labelWidth: 600,
    labelHeight: 400,
    dpi: 203,
    dataSources: [
      { name: 'title', label: 'Titolo', defaultValue: '' },
      { name: 'product', label: 'Prodotto', defaultValue: '' },
      { name: 'serial', label: 'Seriale', defaultValue: '' },
      { name: 'barcode', label: 'Barcode', defaultValue: '' },
    ],
    elements: [],
  }
}

export function createElement(type, x = 0, y = 0) {
  const id = crypto.randomUUID()

  const base = { id, type, x, y }

  if (type === 'text') {
    return {
      ...base,
      font: '0',
      fontHeight: 30,
      fontWidth: 30,
      dataSource: '',
      prefix: '',
      suffix: '',
      bold: false,
      underline: false,
    }
  }

  if (type === 'barcode') {
    return {
      ...base,
      barcodeType: 'code128',
      moduleWidth: 2,
      height: 100,
      showText: true,
      dataSource: '',
    }
  }

  if (type === 'qr') {
    return {
      ...base,
      magnification: 4,
      errorCorrection: 'M',
      dataSource: '',
    }
  }

  if (type === 'image') {
    return {
      ...base,
      width: 80,
      height: 80,
      imageData: '',
    }
  }

  return base
}

export function resolveElementValue(element, dataValues, dataSources) {
  if (element.type === 'image') {
    return ''
  }

  const source = element.dataSource ?? ''

  if (source && Object.prototype.hasOwnProperty.call(dataValues, source)) {
    return String(dataValues[source])
  }

  const ds = dataSources.find((item) => item.name === source)
  if (ds?.defaultValue !== undefined) {
    return String(ds.defaultValue)
  }

  return element.staticValue ? String(element.staticValue) : ''
}

export function buildValuesFromSources(dataSources) {
  return Object.fromEntries(
    dataSources.map((source) => [source.name, source.defaultValue ?? ''])
  )
}

export function uniqueDataSourceName(template, base = 'text') {
  const existing = new Set((template.dataSources ?? []).map((source) => source.name))
  let index = 1
  let name = `${base}_${index}`

  while (existing.has(name)) {
    index += 1
    name = `${base}_${index}`
  }

  return name
}

export function countElementsUsingDataSource(template, dataSourceName) {
  if (!dataSourceName) {
    return 0
  }

  return getElementsUsingDataSource(template, dataSourceName).length
}

export function getElementsUsingDataSource(template, dataSourceName) {
  if (!dataSourceName) {
    return []
  }

  return (template.elements ?? []).filter(
    (element) => isDataBoundElement(element) && element.dataSource === dataSourceName
  )
}

export function findSharedDataSources(template) {
  const groups = new Map()

  for (const element of template.elements ?? []) {
    if (!isDataBoundElement(element)) {
      continue
    }

    const name = element.dataSource ?? ''
    if (!name) {
      continue
    }

    if (!groups.has(name)) {
      groups.set(name, [])
    }

    groups.get(name).push(element)
  }

  return [...groups.entries()]
    .filter(([, elements]) => elements.length > 1)
    .map(([name, elements]) => ({ name, elements }))
}

export function describeElementForUi(element) {
  const typeLabel = isDataBoundElement(element)
    ? dataSourceNaming(element.type).labelPrefix
    : element.type

  return `${typeLabel} (${element.x ?? 0}, ${element.y ?? 0})`
}

export function reassignElementToDedicatedDataSource(
  template,
  element,
  dataValues = {},
  overrideValue = undefined
) {
  const currentValue =
    overrideValue !== undefined
      ? String(overrideValue)
      : resolveElementValue(element, dataValues, template.dataSources ?? [])
  const { base, labelPrefix } = dataSourceNaming(element.type)
  const name = uniqueDataSourceName(template, base)
  const labelNumber =
    (template.dataSources ?? []).filter((source) => source.name.startsWith(`${base}_`)).length + 1

  if (!template.dataSources) {
    template.dataSources = []
  }

  template.dataSources.push({
    name,
    label: `${labelPrefix} ${labelNumber}`,
    defaultValue: currentValue,
  })
  element.dataSource = name

  if ('staticValue' in element) {
    delete element.staticValue
  }

  return name
}

export function disconnectElementFromSharedDataSource(
  template,
  element,
  dataValues = {},
  overrideValue = undefined
) {
  const sourceName = element.dataSource ?? ''

  if (!sourceName || countElementsUsingDataSource(template, sourceName) <= 1) {
    return false
  }

  reassignElementToDedicatedDataSource(template, element, dataValues, overrideValue)

  return true
}

export function ensureElementDataSource(template, element) {
  if (!isDataBoundElement(element)) {
    return element.dataSource ?? ''
  }

  const { base, labelPrefix } = dataSourceNaming(element.type)
  const current = element.dataSource ?? ''
  const needsDedicatedSource =
    current === '' ||
    countElementsUsingDataSource(template, current) > 0 ||
    !(template.dataSources ?? []).some((source) => source.name === current)

  if (!needsDedicatedSource) {
    return current
  }

  const name = uniqueDataSourceName(template, base)
  const labelNumber =
    (template.dataSources ?? []).filter((source) => source.name.startsWith(`${base}_`)).length + 1

  if (!template.dataSources) {
    template.dataSources = []
  }

  template.dataSources.push({
    name,
    label: `${labelPrefix} ${labelNumber}`,
    defaultValue: '',
  })
  element.dataSource = name

  return name
}

export function pruneUnusedDataSources(template) {
  if (!template.dataSources) {
    return
  }

  template.dataSources = template.dataSources.filter(
    (source) => countElementsUsingDataSource(template, source.name) > 0
  )
}

/** @deprecated usa pruneUnusedDataSources */
export function pruneOrphanedAutoDataSources(template) {
  pruneUnusedDataSources(template)
}

export function repairBrokenDataSourceReferences(template) {
  const known = new Set((template.dataSources ?? []).map((source) => source.name))

  for (const element of template.elements ?? []) {
    if (!isDataBoundElement(element)) {
      continue
    }

    const current = element.dataSource ?? ''
    if (current && known.has(current)) {
      continue
    }

    element.dataSource = ''
    ensureElementDataSource(template, element)
    known.add(element.dataSource)
  }
}

export function renameDataSource(template, oldName, newName) {
  const trimmed = String(newName ?? '').trim()

  if (!oldName || !trimmed || oldName === trimmed) {
    return { ok: false, reason: 'invalid' }
  }

  const sources = template.dataSources ?? []
  const source = sources.find((item) => item.name === oldName)

  if (!source) {
    return { ok: false, reason: 'missing' }
  }

  if (sources.some((item) => item.name === trimmed && item.name !== oldName)) {
    return { ok: false, reason: 'duplicate' }
  }

  source.name = trimmed

  for (const element of template.elements ?? []) {
    if (element.dataSource === oldName) {
      element.dataSource = trimmed
    }
  }

  return { ok: true, name: trimmed }
}

export function registerNewElement(template, element, displayValues = {}) {
  ensureElementDataSource(template, element)
  placeNewElement(template, element, displayValues)
  template.elements.push(element)

  return element
}

export function duplicateElementInTemplate(
  template,
  source,
  displayValues = {},
  offset = DUPLICATE_OFFSET
) {
  const clone = cloneTemplateState(source)
  clone.id = crypto.randomUUID()

  const preferred = {
    x: (source.x ?? 0) + offset,
    y: (source.y ?? 0) + offset,
  }
  const position = findEmptyPlacement(template, clone.type, displayValues, preferred)
  clone.x = position.x
  clone.y = position.y

  template.elements.push(clone)

  if (isDataBoundElement(clone)) {
    const copiedValue = resolveElementValue(source, displayValues, template.dataSources ?? [])
    clone.dataSource = ''
    reassignElementToDedicatedDataSource(template, clone, displayValues, copiedValue)
  }

  return clone
}

export function duplicateElementsInTemplate(template, sources, displayValues = {}) {
  return (sources ?? []).map((source) =>
    duplicateElementInTemplate(template, source, displayValues, DUPLICATE_OFFSET)
  )
}

export function updateElementTextValue(template, element, value) {
  const nextValue = String(value ?? '')
  const sourceName = element.dataSource ?? ''

  if (sourceName && countElementsUsingDataSource(template, sourceName) > 1) {
    disconnectElementFromSharedDataSource(template, element, {}, nextValue)

    return
  }

  if (sourceName) {
    const source = template.dataSources.find((item) => item.name === sourceName)

    if (source) {
      source.defaultValue = nextValue
      return
    }
  }

  element.staticValue = nextValue
}
