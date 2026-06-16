import { placeNewElement } from './elementPlacement.js'

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
    (element) =>
      (element.type === 'text' || element.type === 'barcode') &&
      element.dataSource === dataSourceName
  )
}

export function findSharedDataSources(template) {
  const groups = new Map()

  for (const element of template.elements ?? []) {
    if (element.type !== 'text' && element.type !== 'barcode') {
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
  const typeLabel =
    element.type === 'text' ? 'Testo' : element.type === 'barcode' ? 'Barcode' : element.type

  return `${typeLabel} (${element.x ?? 0}, ${element.y ?? 0})`
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

  const currentValue =
    overrideValue !== undefined
      ? String(overrideValue)
      : resolveElementValue(element, dataValues, template.dataSources ?? [])
  const base = element.type === 'text' ? 'text' : 'barcode'
  const labelPrefix = element.type === 'text' ? 'Testo' : 'Barcode'
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

  return true
}

export function ensureElementDataSource(template, element) {
  if (element.type !== 'text' && element.type !== 'barcode') {
    return element.dataSource ?? ''
  }

  const base = element.type === 'text' ? 'text' : 'barcode'
  const labelPrefix = element.type === 'text' ? 'Testo' : 'Barcode'
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

export function registerNewElement(template, element, displayValues = {}) {
  ensureElementDataSource(template, element)
  placeNewElement(template, element, displayValues)
  template.elements.push(element)

  return element
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
