import { cloneTemplateState } from './cloneSerializable.js'
import { generateId } from './id.js'
import {
  buildValuesFromSources,
  ensureElementDataSource,
  resolveElementValue,
} from './templateStore.js'

const STORAGE_KEY = 'mojito-layouts'
export const LAST_LAYOUT_KEY = 'mojito-last-layout-id'

function cloneElements(elements) {
  return cloneTemplateState(elements ?? [])
}

export function sanitizeTemplateForSave(template) {
  const plain = cloneTemplateState(template)

  return {
    id: plain.id ?? generateId(),
    name: plain.name ?? 'Etichetta senza nome',
    labelWidth: plain.labelWidth ?? 600,
    labelHeight: plain.labelHeight ?? 400,
    dpi: plain.dpi ?? 203,
    originX: plain.originX ?? 0,
    originY: plain.originY ?? 0,
    dataSources: (plain.dataSources ?? []).map((source) => ({
      name: source.name,
      label: source.label ?? source.name,
      defaultValue: source.defaultValue ?? '',
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
  rememberActiveLayout('local', layout.id)
  return layout
}

export function loadLocalLayout(id) {
  return listLocalLayouts().find((item) => item.id === id) ?? null
}

export function rememberActiveLayout(source, layoutId) {
  if (!layoutId) return
  localStorage.setItem(LAST_LAYOUT_KEY, `${source}:${layoutId}`)
}

export function loadRememberedLayoutId() {
  const raw = localStorage.getItem(LAST_LAYOUT_KEY)
  if (!raw || !raw.includes(':')) return null

  const [source, ...rest] = raw.split(':')
  const layoutId = rest.join(':')

  if (!source || !layoutId) return null

  return { source, layoutId }
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
    id: parsed.id ?? generateId(),
    filePath,
    dataSources: (parsed.dataSources ?? []).map((source) => ({
      name: source.name,
      label: source.label ?? source.name,
      defaultValue: source.defaultValue ?? '',
    })),
  }
}

export function templateToEditableJson(template) {
  return JSON.stringify(sanitizeTemplateForSave(template), null, 2)
}

export function parseLayoutJsonText(text) {
  const trimmed = text.trim()

  if (trimmed === '') {
    throw new Error('JSON vuoto.')
  }

  let parsed

  try {
    parsed = JSON.parse(trimmed)
  } catch (error) {
    const message = error instanceof Error ? error.message : 'errore di parsing'
    throw new Error(`JSON non valido: ${message}`)
  }

  if (!parsed || typeof parsed !== 'object' || Array.isArray(parsed)) {
    throw new Error('Il layout deve essere un oggetto JSON.')
  }

  return parseLayoutFile(parsed)
}

export async function importLayoutFromFile(file) {
  const text = await file.text()
  return parseLayoutFile(JSON.parse(text), file.name)
}

export function removeDataSource(template, name) {
  const next = cloneTemplateState(template)
  const values = buildValuesFromSources(template.dataSources ?? [])
  next.dataSources = next.dataSources.filter((source) => source.name !== name)

  for (const element of next.elements) {
    if (element.dataSource !== name) {
      continue
    }

    const preservedValue = resolveElementValue(element, values, template.dataSources ?? [])
    element.dataSource = ''

    if (element.type === 'text' || element.type === 'barcode') {
      ensureElementDataSource(next, element)
      const assigned = next.dataSources.find((source) => source.name === element.dataSource)

      if (assigned) {
        assigned.defaultValue = preservedValue
      }
    }

    if ('staticValue' in element) {
      delete element.staticValue
    }
  }

  return next
}

export function isDataSourceInUse(template, name) {
  return template.elements.some((element) => element.dataSource === name)
}
