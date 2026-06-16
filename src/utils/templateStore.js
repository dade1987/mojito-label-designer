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

export function createElement(type, x = 40, y = 40) {
  const id = crypto.randomUUID()

  const base = { id, type, x, y }

  if (type === 'text') {
    return {
      ...base,
      font: '0',
      fontHeight: 30,
      fontWidth: 30,
      dataSource: 'title',
      prefix: '',
      suffix: '',
    }
  }

  if (type === 'barcode') {
    return {
      ...base,
      barcodeType: 'code128',
      moduleWidth: 2,
      height: 100,
      showText: true,
      dataSource: 'barcode',
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
