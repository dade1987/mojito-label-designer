/**
 * Quali sezioni dei pannelli laterali sono aperte.
 *
 * I pannelli sono lunghi e chi lavora sempre sulle stesse cose vuole
 * chiudere il resto: la scelta va ricordata, altrimenti al prossimo apri del
 * designer torna tutto aperto e si ricomincia.
 */
export const PANEL_SECTIONS_KEY = 'mojito:panel-sections'

export const PANEL_SECTION_KEYS = [
  'layout',
  'elements',
  'dataSources',
  'properties',
  'label',
  'batch',
  'preview',
  'devtools',
]

export function defaultPanelSections() {
  const sections = {}
  for (const key of PANEL_SECTION_KEYS) {
    sections[key] = key !== 'devtools'
  }

  return sections
}

export function loadPanelSections(storage = globalThis.localStorage) {
  const sections = defaultPanelSections()

  try {
    const raw = storage?.getItem(PANEL_SECTIONS_KEY)
    if (!raw) return sections

    const parsed = JSON.parse(raw)
    if (!parsed || typeof parsed !== 'object') return sections

    for (const key of PANEL_SECTION_KEYS) {
      if (typeof parsed[key] === 'boolean') {
        sections[key] = parsed[key]
      }
    }
  } catch {
    // Storage assente o corrotto: si riparte dai default, senza rumore.
  }

  return sections
}

export function savePanelSections(sections, storage = globalThis.localStorage) {
  const clean = {}
  for (const key of PANEL_SECTION_KEYS) {
    clean[key] = Boolean(sections?.[key])
  }

  try {
    storage?.setItem(PANEL_SECTIONS_KEY, JSON.stringify(clean))
  } catch {
    // Quota piena o storage bloccato: la sezione resta aperta per questa sessione.
  }

  return clean
}
