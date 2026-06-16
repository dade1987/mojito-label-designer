import { cloneTemplateState } from './cloneSerializable.js'

const STORAGE_KEY = 'mojito-layouts'

function cloneElements(elements) {
  return cloneTemplateState(elements ?? [])
}

export function sanitizeTemplateForSave(template) {
  const plain = cloneTemplateState(template)

  return {
    id: plain.id ?? crypto.randomUUID(),
    name: plain.name ?? 'Etichetta senza nome',
    labelWidth: plain.labelWidth ?? 600,
    labelHeight: plain.labelHeight ?? 400,
    dpi: plain.dpi ?? 203,
    dataSources: (plain.dataSources ?? []).map((source) => ({
      name: source.name,
      label: source.label ?? source.name,
    })),
    elements: cloneElements(plain.elements),
  }
}

export function listLocalLayouts() {
  try {
    const raw = localStorage.getItem(STORAGE_KEY)
    if (!raw) return []

    const parsed = JSON.parse(raw)
    return Array.isArray(parsed) ? parsed : []
  } catch {
    return []
  }
}

export function saveLocalLayout(template) {
  const layout = sanitizeTemplateForSave(template)
  const layouts = listLocalLayouts()
  const index = layouts.findIndex((item) => item.id === layout.id)

  if (index >= 0) {
    layouts[index] = layout
  } else {
    layouts.push(layout)
  }

  localStorage.setItem(STORAGE_KEY, JSON.stringify(layouts))
  return layout
}

export function loadLocalLayout(id) {
  return listLocalLayouts().find((item) => item.id === id) ?? null
}

export function deleteLocalLayout(id) {
  const layouts = listLocalLayouts().filter((item) => item.id !== id)
  localStorage.setItem(STORAGE_KEY, JSON.stringify(layouts))
}

function serializeLayout(template) {
  return JSON.stringify(sanitizeTemplateForSave(template), null, 2)
}

export function exportLayoutToFile(template) {
  const layout = sanitizeTemplateForSave(template)
  const blob = new Blob([serializeLayout(template)], { type: 'application/json' })
  const url = URL.createObjectURL(blob)
  const anchor = document.createElement('a')
  anchor.href = url
  anchor.download = `${layout.name.replace(/\s+/g, '_').toLowerCase() || 'layout'}.mojito.json`
  anchor.click()
  URL.revokeObjectURL(url)
}

export async function saveLayoutToFile(template) {
  const layout = sanitizeTemplateForSave(template)

  if (typeof window !== 'undefined' && window.electronAPI?.layout?.saveFile) {
    const result = await window.electronAPI.layout.saveFile(layout)

    if (result.canceled) {
      return { saved: false }
    }

    return { saved: true, filePath: result.filePath, layout }
  }

  exportLayoutToFile(template)
  return { saved: true, filePath: null, layout }
}

export async function openLayoutFromFile() {
  if (typeof window !== 'undefined' && window.electronAPI?.layout?.openFile) {
    const result = await window.electronAPI.layout.openFile()

    if (result.canceled) {
      return null
    }

    return parseLayoutFile(result.content, result.filePath)
  }

  return null
}

function parseLayoutFile(parsed, filePath = null) {
  if (!parsed.elements || !Array.isArray(parsed.elements)) {
    throw new Error('File layout non valido: manca elements.')
  }

  return {
    ...parsed,
    id: parsed.id ?? crypto.randomUUID(),
    filePath,
    dataSources: (parsed.dataSources ?? []).map((source) => ({
      name: source.name,
      label: source.label ?? source.name,
      defaultValue: source.defaultValue ?? '',
    })),
  }
}

export async function importLayoutFromFile(file) {
  const text = await file.text()
  return parseLayoutFile(JSON.parse(text), file.name)
}

export function removeDataSource(template, name) {
  const next = cloneTemplateState(template)
  next.dataSources = next.dataSources.filter((source) => source.name !== name)

  next.elements = next.elements.map((element) => {
    if (element.dataSource !== name) {
      return element
    }

    const fallback = next.dataSources[0]?.name ?? ''
    return { ...element, dataSource: fallback }
  })

  return next
}

export function isDataSourceInUse(template, name) {
  return template.elements.some((element) => element.dataSource === name)
}
