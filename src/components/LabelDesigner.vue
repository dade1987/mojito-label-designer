<script setup>
import { ref, computed, onMounted, onUnmounted, watch } from 'vue'
import {
  fetchDefaultTemplate,
  fetchPrinters,
  fetchTemplate,
  fetchTemplates,
  previewLabelImage,
  previewZpl,
  printLabel,
  saveTemplate,
  deleteTemplate,
} from '../utils/api.js'
import {
  buildValuesFromSources,
  countElementsUsingDataSource,
  createElement,
  describeElementForUi,
  duplicateElementsInTemplate,
  disconnectElementFromSharedDataSource,
  findSharedDataSources,
  getElementsUsingDataSource,
  pruneUnusedDataSources,
  reassignElementToDedicatedDataSource,
  registerNewElement,
  renameDataSource,
  repairBrokenDataSourceReferences,
  updateElementTextValue,
} from '../utils/templateStore.js'
import { cloneTemplateState } from '../utils/cloneSerializable.js'
import { hasWork, startNewLayout } from '../utils/newLayout.js'
import {
  asUnsavedStartingPoint,
  findLayoutByName,
  findOverwriteTarget,
  overwriteQuestion,
  prepareSaveAs,
  proposeSaveAsName,
} from '../utils/layoutSaveGuard.js'
import { resolutionForPrinter, shouldWarnResolution } from '../utils/printerResolution.js'
import { describePrintMode, printModeForPrinter, shouldWarnPrintMode } from '../utils/printerPrintMode.js'
import { loadPanelSections, savePanelSections } from '../utils/panelSections.js'
import { printableMagnification, resizeKeepingRatio } from '../utils/aspectRatio.js'
import {
  MAX_LABELS_PER_RUN,
  buildPrintJobs,
  buildSerialRange,
  describeRun,
  serialRangeCount,
} from '../utils/serialRange.js'
import {
  deleteLocalLayout,
  importLayoutFromFile,
  openLayoutFromFile,
  saveLayoutToFile,
  listLocalLayouts,
  loadLocalLayout,
  loadRememberedLayoutId,
  rememberActiveLayout,
  removeDataSource,
  saveLocalLayout,
  templateToEditableJson,
  parseLayoutJsonText,
} from '../utils/layoutStorage.js'
import {
  CUSTOM_FORMAT_ID,
  LABEL_FORMATS,
  PRINTER_RESOLUTIONS,
  applyFormat,
  detectFormat,
  dotsToMm,
  findFormat,
  fitElementsToLabel,
  fitTemplateToSize,
  mmToDots,
  rescaleTemplateForDpi,
} from '../utils/labelFormats.js'
import { generateId } from '../utils/id.js'
import LabelCanvas from './LabelCanvas.vue'

const template = ref(null)
const selectedIds = ref([])
const printers = ref([])
const selectedPrinter = ref('')
// Risoluzione dichiarata dal server per ogni stampante conosciuta.
const printerResolutions = ref({})
// Strada di stampa (zpl / graphic) dichiarata dal server per ogni stampante
// conosciuta: il layout si allinea da solo quando si cambia stampante.
const printerModes = ref({})
// Quali sezioni dei pannelli laterali sono aperte: la scelta resta fra una
// sessione e l'altra.
const sections = ref(loadPanelSections())
// Le immagini si ridimensionano a proporzioni bloccate: un logo schiacciato
// non si nota sullo schermo e si vede benissimo stampato.
const keepImageRatio = ref(true)
const printerPlatform = ref('')
const zplPreview = ref('')
// L'etichetta disegnata: è quello che esce dalle stampanti non ZPL, e va
// visto prima di stampare perché lì il risultato lo decide questo disegno.
const labelImagePreview = ref('')
// La stampa manuale a serie: una tirata di etichette numerate, senza dover
// cambiare a mano il valore e ripremere Stampa per ogni pezzo.
const batch = ref({
  dataSource: '',
  start: 1,
  end: 10,
  step: 1,
  pad: 0,
  prefix: '',
  suffix: '',
  copies: 1,
})
const statusMessage = ref('')
const statusType = ref('info')
const isBusy = ref(false)
const localLayouts = ref([])
const serverLayouts = ref([])
const selectedLayoutId = ref('')
const jsonEditorOpen = ref(false)
const jsonEditorText = ref('')
const jsonEditorError = ref('')
const dataSourceRemovalPrompt = ref(null)
const clipboardElements = ref([])

const dataValues = computed(() => {
  if (!template.value) return {}
  return buildValuesFromSources(template.value.dataSources)
})

const selectedFormatId = computed({
  get: () => (template.value ? detectFormat(template.value) : CUSTOM_FORMAT_ID),
  set: (id) => {
    if (!template.value || id === CUSTOM_FORMAT_ID) return

    const format = findFormat(id)
    if (!format) return

    const dpi = template.value.dpi ?? 203
    const widthDots = mmToDots(format.widthMm, dpi)
    const heightDots = mmToDots(format.heightMm, dpi)
    const sizeChanged =
      widthDots !== template.value.labelWidth || heightDots !== template.value.labelHeight
    const hasElements = (template.value.elements ?? []).length > 0

    if (
      sizeChanged &&
      hasElements &&
      window.confirm(
        `Adatto il layout al formato ${format.name}? Gli elementi verranno riscalati in proporzione. (Annulla = cambia solo le dimensioni)`
      )
    ) {
      fitTemplateToSize(template.value, widthDots, heightDots)
      return
    }

    applyFormat(template.value, id)
  },
})

const selectedDpi = computed({
  get: () => template.value?.dpi ?? 203,
  set: (dpi) => {
    if (template.value) {
      rescaleTemplateForDpi(template.value, dpi)
    }
  },
})

// I layout salvati prima di questo campo non lo hanno: per loro vale "gap",
// lo stesso default che applica il server quando il campo manca.
const mediaTracking = computed({
  get: () => template.value?.mediaTracking ?? 'gap',
  set: (value) => {
    if (template.value) {
      template.value.mediaTracking = value
    }
  },
})

const printerDpi = computed(() => resolutionForPrinter(printerResolutions.value, selectedPrinter.value))

// Il disegno a una risoluzione diversa da quella di stampa esce di misura
// sbagliata, e sullo schermo sembra tutto a posto: e' il caso che merita un
// avviso, non un silenzio.
const resolutionMismatch = computed(() =>
  shouldWarnResolution(template.value?.dpi ?? 203, printerDpi.value)
)

function applyPrinterResolution() {
  if (!printerDpi.value || !template.value) return
  selectedDpi.value = printerDpi.value
  showStatus(`Disegno riportato a ${printerDpi.value} dpi, come la stampante`, 'success')
}

/**
 * L'elenco delle risoluzioni, con quella dichiarata dalla stampante scelta
 * aggiunta se non e' fra le standard: una stampante che stampa a una
 * risoluzione fuori elenco non deve restare non selezionabile.
 */
function withPrinterResolution() {
  const dpi = printerDpi.value

  if (!dpi || PRINTER_RESOLUTIONS.some((resolution) => resolution.dpi === dpi)) {
    return PRINTER_RESOLUTIONS
  }

  return [...PRINTER_RESOLUTIONS, { dpi, label: `${dpi} dpi (${selectedPrinter.value})` }]
}

const dpiOptions = computed(() => {
  const current = selectedDpi.value

  if (withPrinterResolution().some((resolution) => resolution.dpi === current)) {
    return withPrinterResolution()
  }

  return [{ dpi: current, label: `${current} dpi` }, ...withPrinterResolution()]
})

const labelWidthMm = computed({
  get: () => dotsToMm(template.value?.labelWidth ?? 0, template.value?.dpi ?? 203),
  set: (mm) => {
    if (template.value && Number(mm) > 0) {
      template.value.labelWidth = mmToDots(Number(mm), template.value.dpi ?? 203)
    }
  },
})

const labelHeightMm = computed({
  get: () => dotsToMm(template.value?.labelHeight ?? 0, template.value?.dpi ?? 203),
  set: (mm) => {
    if (template.value && Number(mm) > 0) {
      template.value.labelHeight = mmToDots(Number(mm), template.value.dpi ?? 203)
    }
  },
})

function fitLayoutToLabel() {
  if (!template.value) return

  const fitted = fitElementsToLabel(template.value, dataValues.value)
  showStatus(
    fitted
      ? 'Layout riscalato per rientrare nelle dimensioni etichetta.'
      : "Il layout è già dentro l'etichetta.",
    'info'
  )
}

const selectedElement = computed(() => {
  if (selectedIds.value.length !== 1 || !template.value) return null
  return template.value.elements.find((el) => el.id === selectedIds.value[0]) ?? null
})

const selectedCount = computed(() => selectedIds.value.length)

const sharedDataSources = computed(() => {
  if (!template.value) return []
  return findSharedDataSources(template.value)
})

const selectedElementSharesDataSource = computed(() => {
  if (!selectedElement.value?.dataSource || !template.value) return false

  return countElementsUsingDataSource(template.value, selectedElement.value.dataSource) > 1
})

const dataSourceRemovalElements = computed(() => {
  if (!template.value || !dataSourceRemovalPrompt.value) return []

  const ids = new Set(dataSourceRemovalPrompt.value.elementIds)

  return template.value.elements.filter((element) => ids.has(element.id))
})

const layoutOptions = computed(() => {
  const local = localLayouts.value.map((layout) => ({
    id: `local:${layout.id}`,
    label: `[Locale] ${layout.name}`,
    source: 'local',
    layoutId: layout.id,
  }))

  const server = serverLayouts.value.map((layout) => ({
    id: `server:${layout.id}`,
    label: `[Server] ${layout.name}`,
    source: 'server',
    layoutId: layout.id,
  }))

  return [...local, ...server]
})

onMounted(async () => {
  window.addEventListener('keydown', handleKeydown)

  try {
    const [{ printers: list, platform, diagnostics, printerResolutions: resolutions, printerModes: modes }, defaultTemplate, { templates }] = await Promise.all([
      fetchPrinters(),
      fetchDefaultTemplate(),
      fetchTemplates().catch(() => ({ templates: [] })),
    ])
    printers.value = Array.isArray(list) ? list : []
    printerResolutions.value = resolutions && typeof resolutions === 'object' ? resolutions : {}
    printerModes.value = modes && typeof modes === 'object' ? modes : {}
    printerPlatform.value = platform ?? ''
    selectedPrinter.value = pickDefaultPrinter(printers.value)
    if (printers.value.length === 0) {
      const hint = formatPrinterDiagnostics({ diagnostics })
      showStatus(
        `Nessuna stampante rilevata (${printerPlatform.value || 'sistema sconosciuto'}). Inserisci il nome manualmente.${hint}`,
        'warn'
      )
    }
    serverLayouts.value = templates
    refreshLocalLayouts()
    const initial = await resolveInitialTemplate(defaultTemplate)
    template.value = hydrateTemplate(initial.template)
    finalizeTemplateState()
    selectedLayoutId.value = initial.selectedLayout
    await refreshPreview()
  } catch (error) {
    showStatus(error.message, 'error')
  }
})

onUnmounted(() => {
  window.removeEventListener('keydown', handleKeydown)
})

watch(
  () => [template.value, dataValues.value, selectedPrinter.value],
  () => {
    refreshPreview()
  },
  { deep: true }
)

function pickDefaultPrinter(list) {
  if (!list.length) return ''
  const citizen = list.find((name) => /citizen/i.test(name))
  return citizen ?? list[0]
}

function formatPrinterDiagnostics(payload) {
  const entries = payload?.diagnostics
  if (!Array.isArray(entries) || entries.length === 0) return ''
  const first = entries[0]
  if (!first?.method) return ''
  return ` Dettaglio: ${first.method} → ${first.output}`
}

function ensurePrinterSelected() {
  if (!selectedPrinter.value.trim()) {
    throw new Error('Seleziona o inserisci il nome di una stampante.')
  }
}

function hydrateTemplate(raw) {
  return {
    ...raw,
    id: raw.id ?? generateId(),
    dataSources: (raw.dataSources ?? []).map((source) => ({
      name: source.name,
      label: source.label ?? source.name,
      defaultValue: source.defaultValue ?? '',
    })),
    elements: cloneTemplateState(raw.elements ?? []),
  }
}

function finalizeTemplateState() {
  if (!template.value) return
  repairBrokenDataSourceReferences(template.value)
  pruneUnusedDataSources(template.value)
}

async function resolveInitialTemplate(defaultTemplate) {
  const remembered = loadRememberedLayoutId()
  // Il default è un punto di partenza, non un layout salvato: senza id il
  // primo salvataggio crea un layout nuovo invece di scrivere sempre sullo
  // stesso file da tutte le postazioni.
  const startingPoint = asUnsavedStartingPoint(defaultTemplate)

  if (!remembered) {
    return { template: startingPoint, selectedLayout: '' }
  }

  if (remembered.source === 'local') {
    const loaded = loadLocalLayout(remembered.layoutId)

    if (loaded) {
      return {
        template: loaded,
        selectedLayout: `local:${remembered.layoutId}`,
      }
    }
  }

  if (remembered.source === 'server') {
    try {
      const loaded = await fetchTemplate(remembered.layoutId)

      return {
        template: loaded,
        selectedLayout: `server:${remembered.layoutId}`,
      }
    } catch {
      // fallback al template di default
    }
  }

  return { template: startingPoint, selectedLayout: '' }
}

function refreshLocalLayouts() {
  localLayouts.value = listLocalLayouts()
}

function showStatus(message, type = 'info') {
  statusMessage.value = message
  statusType.value = type
  dataSourceRemovalPrompt.value = null
}

function showDataSourceRemovalPrompt(name, users) {
  statusMessage.value = `"${name}" è ancora usato da ${users.length} elemento/i sul canvas.`
  statusType.value = 'warn'
  dataSourceRemovalPrompt.value = {
    name,
    elementIds: users.map((element) => element.id),
  }
  selectedIds.value = users.map((element) => element.id)
}

function dismissDataSourceRemovalPrompt() {
  dataSourceRemovalPrompt.value = null
  statusMessage.value = ''
}

function removeElementsByIds(ids) {
  if (!template.value || ids.length === 0) return

  const idSet = new Set(ids)
  template.value.elements = template.value.elements.filter((element) => !idSet.has(element.id))
  repairBrokenDataSourceReferences(template.value)
  pruneUnusedDataSources(template.value)
  selectedIds.value = selectedIds.value.filter((id) => !idSet.has(id))
}

function tryFinalizeDataSourceRemoval(name) {
  if (!template.value || !name) return false

  const users = getElementsUsingDataSource(template.value, name)

  if (users.length > 0) {
    showDataSourceRemovalPrompt(name, users)
    return false
  }

  template.value = removeDataSource(template.value, name)
  pruneUnusedDataSources(template.value)
  dataSourceRemovalPrompt.value = null
  showStatus(`Data source "${name}" eliminato`, 'success')

  return true
}

function notifySharedDataSourcesAfterLoad() {
  if (sharedDataSources.value.length === 0) {
    return
  }

  const count = sharedDataSources.value.length
  showStatus(
    `Attenzione: ${count} data source condivisi tra più elementi. Controlla il pannello a sinistra.`,
    'warn'
  )
}

function selectElementById(elementId) {
  selectedIds.value = [elementId]
}

function getSelectedElements() {
  if (!template.value || selectedIds.value.length === 0) return []

  const ids = new Set(selectedIds.value)

  return template.value.elements.filter((element) => ids.has(element.id))
}

function copySelected() {
  const sources = getSelectedElements()

  if (sources.length === 0) {
    showStatus('Seleziona almeno un elemento da copiare.', 'info')
    return
  }

  clipboardElements.value = cloneTemplateState(sources)
  showStatus(`${sources.length} elemento/i copiato/i · Ctrl+V per incollare`, 'info')
}

function duplicateSelected() {
  if (!template.value) return

  const sources = getSelectedElements()

  if (sources.length === 0) {
    showStatus('Seleziona almeno un elemento da duplicare.', 'info')
    return
  }

  const copies = duplicateElementsInTemplate(template.value, sources, dataValues.value)
  selectedIds.value = copies.map((element) => element.id)
  showStatus(`${copies.length} elemento/i duplicato/i`, 'success')
}

function pasteClipboard() {
  if (!template.value) return

  if (clipboardElements.value.length === 0) {
    showStatus('Nessun elemento negli appunti. Usa Ctrl+C per copiare.', 'info')
    return
  }

  const copies = duplicateElementsInTemplate(
    template.value,
    clipboardElements.value,
    dataValues.value
  )
  selectedIds.value = copies.map((element) => element.id)
  showStatus(`${copies.length} elemento/i incollato/i`, 'success')
}

function isShortcutTargetEditable(target) {
  const tag = target?.tagName?.toLowerCase() ?? ''

  return tag === 'input' || tag === 'textarea' || tag === 'select' || target?.isContentEditable
}

function handleDisconnectFromElement(element) {
  if (!template.value || !element) return

  const previousSource = element.dataSource ?? ''
  const disconnected = disconnectElementFromSharedDataSource(
    template.value,
    element,
    dataValues.value
  )

  if (!disconnected) {
    showStatus('Questo elemento non condivide il data source con altri.', 'info')
    return
  }

  selectedIds.value = [element.id]
  showStatus(
    `Elemento scollegato da "${previousSource}" → ora usa "${element.dataSource}".`,
    'success'
  )
}

function handleDataSourceChange(element) {
  if (!template.value || !element?.dataSource) return

  if (countElementsUsingDataSource(template.value, element.dataSource) > 1) {
    showStatus(
      `"${element.dataSource}" è usato da più elementi: mostrano lo stesso valore finché non scolleghi uno di essi.`,
      'warn'
    )
  }
}

function addElement(type) {
  if (!template.value) return
  const element = createElement(type)
  registerNewElement(template.value, element, dataValues.value)
  selectedIds.value = [element.id]
}

function removeSelected() {
  if (!template.value || selectedIds.value.length === 0) return
  removeElementsByIds(selectedIds.value)
  selectedIds.value = []

  if (dataSourceRemovalPrompt.value) {
    tryFinalizeDataSourceRemoval(dataSourceRemovalPrompt.value.name)
    return
  }

  showStatus('Elemento/i eliminato/i', 'info')
}

function handleDataSourceRename(source, requestedName, event) {
  if (!template.value) return

  // Il campo NON usa v-model: se il nome mutasse a ogni tasto, al momento
  // della conferma la sorgente col "vecchio" nome non esisterebbe piu' e la
  // rinomina fallirebbe in silenzio lasciando gli elementi legati al nome
  // vecchio (e al ricaricamento la rinomina spariva).
  const result = renameDataSource(template.value, source.name, requestedName)

  if (!result.ok) {
    if (event?.target) {
      event.target.value = source.name
    }

    if (result.reason === 'duplicate') {
      showStatus('Nome già usato da un altro data source.', 'error')
    } else if (String(requestedName ?? '').trim() !== source.name) {
      showStatus('Nome non valido.', 'error')
    }
    return
  }

  showStatus(`Data source rinominato in "${result.name}".`, 'success')
}

function handleKeydown(event) {
  if (isShortcutTargetEditable(event.target)) {
    return
  }

  const mod = event.ctrlKey || event.metaKey

  if (mod && event.key.toLowerCase() === 'c') {
    event.preventDefault()
    copySelected()
    return
  }

  if (mod && event.key.toLowerCase() === 'v') {
    event.preventDefault()
    pasteClipboard()
    return
  }

  if (mod && event.key.toLowerCase() === 'd') {
    event.preventDefault()
    duplicateSelected()
    return
  }

  if (event.key === 'Delete' && selectedIds.value.length > 0) {
    event.preventDefault()
    removeSelected()
  }
}

function handleUpdateTextValue({ element, value }) {
  if (!template.value) return
  updateElementTextValue(template.value, element, value)
}

function addDataSource() {
  if (!template.value) return
  const name = `field_${template.value.dataSources.length + 1}`
  template.value.dataSources.push({
    name,
    label: name,
    defaultValue: '',
  })
}

function handleRemoveDataSource(name) {
  if (!template.value) return

  const users = getElementsUsingDataSource(template.value, name)

  if (users.length > 0) {
    showDataSourceRemovalPrompt(name, users)
    return
  }

  tryFinalizeDataSourceRemoval(name)
}

function handlePromptReassignElement(element) {
  if (!template.value || !dataSourceRemovalPrompt.value) return

  const targetName = dataSourceRemovalPrompt.value.name
  reassignElementToDedicatedDataSource(template.value, element, dataValues.value)
  tryFinalizeDataSourceRemoval(targetName)
}

function handlePromptDeleteElement(element) {
  if (!dataSourceRemovalPrompt.value) return

  const targetName = dataSourceRemovalPrompt.value.name
  removeElementsByIds([element.id])
  tryFinalizeDataSourceRemoval(targetName)
}

function handlePromptReassignAll() {
  if (!template.value || !dataSourceRemovalPrompt.value) return

  const targetName = dataSourceRemovalPrompt.value.name
  const users = getElementsUsingDataSource(template.value, targetName)

  for (const element of users) {
    reassignElementToDedicatedDataSource(template.value, element, dataValues.value)
  }

  tryFinalizeDataSourceRemoval(targetName)
}

function handlePromptDeleteAll() {
  if (!template.value || !dataSourceRemovalPrompt.value) return

  const targetName = dataSourceRemovalPrompt.value.name
  const users = getElementsUsingDataSource(template.value, targetName)
  removeElementsByIds(users.map((element) => element.id))
  tryFinalizeDataSourceRemoval(targetName)
}

function onImageUpload(event) {
  const file = event.target.files?.[0]
  if (!file || !selectedElement.value || selectedElement.value.type !== 'image') return

  const reader = new FileReader()
  reader.onload = () => {
    selectedElement.value.imageData = reader.result
  }
  reader.readAsDataURL(file)
}

async function refreshPreview() {
  if (!template.value) return

  try {
    const result = await previewZpl({
      template: template.value,
      values: dataValues.value,
      printer: selectedPrinter.value,
    })
    zplPreview.value = result.zpl
  } catch (error) {
    zplPreview.value = ''
  }

  if (!isGraphicMode.value) {
    labelImagePreview.value = ''

    return
  }

  try {
    const { png } = await previewLabelImage({
      template: template.value,
      values: dataValues.value,
    })
    labelImagePreview.value = png ?? ''
  } catch (error) {
    labelImagePreview.value = ''
  }
}

async function handlePrint() {
  if (!template.value) return

  isBusy.value = true

  try {
    ensurePrinterSelected()
    await printLabel({
      template: template.value,
      values: dataValues.value,
      printMode: printMode.value,
      printer: selectedPrinter.value,
    })
    showStatus(`Etichetta inviata a ${selectedPrinter.value}`, 'success')
  } catch (error) {
    showStatus(error.message, 'error')
  } finally {
    isBusy.value = false
  }
}

// Come va stampato questo layout. Lo ZPL resta la strada di casa; "graphic"
// serve alle stampanti che lo ZPL non lo parlano (es. Munbyn ITPP941P): lì il
// server disegna l'etichetta e la manda alla coda di stampa come immagine.
const printMode = computed({
  get: () => (template.value?.printMode === 'graphic' ? 'graphic' : 'zpl'),
  set: (value) => {
    if (template.value) {
      template.value.printMode = value === 'graphic' ? 'graphic' : 'zpl'
    }
  },
})

const isGraphicMode = computed(() => printMode.value === 'graphic')

const printerMode = computed(() => printModeForPrinter(printerModes.value, selectedPrinter.value))

// Mandare ZPL a una stampante che non lo parla non da' errori: esce carta
// bianca. Quando il server conosce la stampante, il layout si mette da solo
// sulla sua strada; se poi qualcuno la cambia a mano, resta l'avviso.
const printModeMismatch = computed(() => shouldWarnPrintMode(printMode.value, printerMode.value))

function applyPrinterPrintMode() {
  if (!printerMode.value || !template.value || printMode.value === printerMode.value) return
  printMode.value = printerMode.value
  showStatus(`${selectedPrinter.value}: layout impostato su ${describePrintMode(printerMode.value)}`, 'info')
}

watch(
  () => [printerMode.value, template.value?.id],
  () => {
    applyPrinterPrintMode()
  }
)

function toggleSection(key, event) {
  sections.value[key] = Boolean(event?.target?.open)
  savePanelSections(sections.value)
}

const batchCopies = computed(() => Math.max(1, Number(batch.value.copies) || 1))

const batchCount = computed(() => serialRangeCount(batch.value))

const batchSummary = computed(() => describeRun(batchCount.value, batchCopies.value))

/** I primi e l'ultimo numero di serie, per far vedere cosa uscirà davvero. */
const batchSample = computed(() => {
  try {
    const serials = buildSerialRange(batch.value)

    if (serials.length <= 4) {
      return serials.join(', ')
    }

    return `${serials.slice(0, 3).join(', ')} … ${serials[serials.length - 1]}`
  } catch {
    return ''
  }
})

/**
 * La sorgente dati che riceve il numero di serie: si sceglie da sola quella
 * che ne ha l'aria, così la stampa a serie è pronta appena si apre il layout.
 */
function pickBatchDataSource(sources) {
  const names = (sources ?? []).map((source) => source.name)

  if (names.includes(batch.value.dataSource)) {
    return batch.value.dataSource
  }

  const likely = names.find((name) =>
    /seri|numero|progressiv|pack|pacco|barcode|codice/i.test(name),
  )

  return likely ?? names[0] ?? ''
}

watch(
  () => template.value?.dataSources,
  (sources) => {
    batch.value.dataSource = pickBatchDataSource(sources)
  },
  { deep: true, immediate: true },
)

const canPrintBatch = computed(
  () =>
    !isBusy.value &&
    Boolean(selectedPrinter.value.trim()) &&
    Boolean(batch.value.dataSource) &&
    batchCount.value > 0 &&
    batchCount.value <= MAX_LABELS_PER_RUN,
)

/**
 * Stampa una serie di etichette numerate in una sola richiesta: il server
 * ripete il layout cambiando ogni volta il numero di serie.
 */
async function handleBatchPrint() {
  if (!template.value) return

  isBusy.value = true

  try {
    ensurePrinterSelected()
    const serials = buildSerialRange(batch.value)
    const jobs = buildPrintJobs(serials, batch.value.dataSource)

    await printLabel({
      template: template.value,
      values: dataValues.value,
      jobs,
      copies: batchCopies.value,
      printMode: printMode.value,
      printer: selectedPrinter.value,
    })

    showStatus(
      `Serie inviata a ${selectedPrinter.value}: ${describeRun(serials.length, batchCopies.value)}`,
      'success',
    )
  } catch (error) {
    showStatus(error.message, 'error')
  } finally {
    isBusy.value = false
  }
}

async function handleQuickPrint() {
  isBusy.value = true

  try {
    ensurePrinterSelected()
    await printLabel({
      title: dataValues.value.title ?? 'CAVALLINI SERVICE',
      product: dataValues.value.product ?? 'Test',
      serial: dataValues.value.serial ?? 'ABC123',
      barcode: dataValues.value.barcode ?? 'ABC123456789',
      printer: selectedPrinter.value,
    })
    showStatus('Etichetta rapida inviata', 'success')
  } catch (error) {
    showStatus(error.message, 'error')
  } finally {
    isBusy.value = false
  }
}

/**
 * Se il salvataggio scriverebbe sopra un layout con un altro nome, si chiede
 * prima: quasi sempre l'utente voleva un layout nuovo, non perdere il vecchio.
 * Torna true se si può procedere.
 */
function confirmOverwrite(target, where) {
  if (!target?.renamed) return true

  if (window.confirm(overwriteQuestion(target, template.value.name, where))) return true

  showStatus(
    `Salvataggio annullato: «${target.name}» è rimasto com'era. Per crearne uno nuovo usa «Salva con nome…».`,
    'info'
  )

  return false
}

function handleSaveLocal() {
  if (!template.value) return

  const target = findOverwriteTarget(listLocalLayouts(), template.value)
  if (!confirmOverwrite(target, 'in locale')) return

  const saved = saveLocalLayout(template.value)
  template.value.id = saved.id
  refreshLocalLayouts()
  showStatus(`Layout salvato in locale: ${saved.name}`, 'success')
}

async function refreshServerLayouts() {
  const { templates } = await fetchTemplates()
  serverLayouts.value = templates

  return templates
}

async function handleSaveServer() {
  if (!template.value) return

  isBusy.value = true

  try {
    // L'elenco si rilegge adesso: un'altra postazione può aver salvato nel
    // frattempo. Il server rifiuta comunque (409) se crediamo di creare un
    // layout nuovo e invece l'identificativo esiste già.
    const target = findOverwriteTarget(await refreshServerLayouts(), template.value)
    if (!confirmOverwrite(target, 'sul server')) return

    const result = await saveTemplate(template.value, { overwrite: target !== null })
    template.value = hydrateTemplate(result.template)
    rememberActiveLayout('server', result.template.id)
    await refreshServerLayouts()
    showStatus(`Layout salvato sul server: ${result.template.name}`, 'success')
  } catch (error) {
    showStatus(error.message, 'error')
  } finally {
    isBusy.value = false
  }
}

/**
 * Salva sul server una copia con identificativo nuovo: il layout aperto
 * resta com'è e si continua a lavorare sulla copia.
 */
async function handleSaveAsServer() {
  if (!template.value) return

  isBusy.value = true

  try {
    const templates = await refreshServerLayouts()
    const requested = window.prompt(
      'Nome del nuovo layout sul server:',
      proposeSaveAsName(template.value.name, templates)
    )
    if (requested === null) return

    const name = requested.trim()
    if (name === '') {
      showStatus('Serve un nome per il nuovo layout.', 'error')
      return
    }

    const taken = findLayoutByName(templates, name)
    if (taken) {
      showStatus(`Sul server esiste già il layout «${taken.name}»: scegli un altro nome.`, 'error')
      return
    }

    const result = await saveTemplate(prepareSaveAs(template.value, name), { overwrite: false })
    template.value = hydrateTemplate(result.template)
    rememberActiveLayout('server', result.template.id)
    await refreshServerLayouts()
    selectedLayoutId.value = `server:${result.template.id}`
    showStatus(`Nuovo layout salvato sul server: ${result.template.name}`, 'success')
  } catch (error) {
    showStatus(error.message, 'error')
  } finally {
    isBusy.value = false
  }
}

async function handleLoadLayout() {
  if (!selectedLayoutId.value || !template.value) return

  const option = layoutOptions.value.find((item) => item.id === selectedLayoutId.value)
  if (!option) return

  try {
    let loaded

    if (option.source === 'local') {
      loaded = loadLocalLayout(option.layoutId)
    } else {
      loaded = await fetchTemplate(option.layoutId)
    }

    if (!loaded) {
      throw new Error('Layout non trovato.')
    }

    template.value = hydrateTemplate(loaded)
    finalizeTemplateState()
    rememberActiveLayout(option.source, option.layoutId)
    selectedLayoutId.value = selectedLayoutId.value || `${option.source}:${option.layoutId}`
    selectedIds.value = []
    showStatus(`Layout caricato: ${loaded.name}`, 'success')
    notifySharedDataSourcesAfterLoad()
  } catch (error) {
    showStatus(error.message, 'error')
  }
}

async function handleSaveToFile() {
  if (!template.value) return

  try {
    const result = await saveLayoutToFile(template.value)

    if (!result.saved) {
      return
    }

    if (result.filePath) {
      showStatus(`Layout salvato su file: ${result.filePath}`, 'success')
    } else {
      showStatus('Layout salvato su file (.mojito.json)', 'success')
    }
  } catch (error) {
    showStatus(error.message, 'error')
  }
}

async function handleOpenFromFile() {
  try {
    const opened = await openLayoutFromFile()

    if (!opened) {
      return
    }

    template.value = hydrateTemplate(opened)
    finalizeTemplateState()
    selectedIds.value = []
    showStatus(
      opened.filePath ? `Layout aperto: ${opened.filePath}` : `Layout aperto: ${opened.name}`,
      'success'
    )
    notifySharedDataSourcesAfterLoad()
  } catch (error) {
    showStatus(error.message, 'error')
  }
}

async function handleImportLayout(event) {
  const file = event.target.files?.[0]
  if (!file) return

  try {
    const imported = await importLayoutFromFile(file)
    template.value = hydrateTemplate(imported)
    finalizeTemplateState()
    selectedIds.value = []
    showStatus(`Layout importato: ${imported.name}`, 'success')
    notifySharedDataSourcesAfterLoad()
  } catch (error) {
    showStatus(error.message, 'error')
  } finally {
    event.target.value = ''
  }
}

/**
 * Ricomincia da un foglio pulito, conservando formato e risoluzione: chi
 * disegna etichette lavora quasi sempre sulla stessa misura.
 */
/**
 * Larghezza o altezza di un'immagine, con le proporzioni bloccate se
 * richiesto.
 */
function setImageSize(dimension, value) {
  const element = selectedElement.value
  if (!element) return

  const requested = Number(value)
  if (!Number.isFinite(requested)) return

  if (!keepImageRatio.value) {
    element[dimension] = Math.max(10, Math.round(requested))
    return
  }

  const resized = resizeKeepingRatio(element, { [dimension]: requested })
  element.width = resized.width
  element.height = resized.height
}

function handleNewLayout() {
  if (hasWork(template.value)) {
    const confirmed = window.confirm(
      'Iniziare un layout nuovo? Quello attuale verrà chiuso: salvalo prima se ti serve.'
    )
    if (!confirmed) return
  }

  template.value = startNewLayout(template.value)
  selectedLayoutId.value = ''
  selectedIds.value = []
  showStatus('Nuovo layout pronto', 'info')
}

/**
 * Elimina il layout dal server, non solo la copia nel browser: erano due
 * cose diverse e ce n'era una sola.
 */
async function handleDeleteServerLayout() {
  const option = layoutOptions.value.find((item) => item.id === selectedLayoutId.value)
  if (!option || option.source === 'local') return

  const confirmed = window.confirm(
    `Eliminare "${option.label}" dal server? Lo perderanno anche le altre postazioni.`
  )
  if (!confirmed) return

  try {
    isBusy.value = true
    await deleteTemplate(option.layoutId)
    selectedLayoutId.value = ''
    const { templates } = await fetchTemplates()
    serverLayouts.value = templates
    showStatus('Layout eliminato dal server', 'info')
  } catch (error) {
    showStatus(`Eliminazione fallita: ${error?.message ?? error}`, 'error')
  } finally {
    isBusy.value = false
  }
}

function handleDeleteLocalLayout() {
  if (!template.value?.id) return

  const confirmed = window.confirm('Eliminare la copia locale di questo layout?')
  if (!confirmed) return

  deleteLocalLayout(template.value.id)
  refreshLocalLayouts()
  showStatus('Layout locale eliminato', 'info')
}

function openJsonEditor() {
  if (!template.value) return

  jsonEditorError.value = ''
  jsonEditorText.value = templateToEditableJson(template.value)
  jsonEditorOpen.value = true
}

function closeJsonEditor() {
  jsonEditorOpen.value = false
  jsonEditorError.value = ''
}

function refreshJsonEditorFromCanvas() {
  if (!template.value) return

  jsonEditorText.value = templateToEditableJson(template.value)
  jsonEditorError.value = ''
}

function applyJsonEditor() {
  try {
    const parsed = parseLayoutJsonText(jsonEditorText.value)
    template.value = hydrateTemplate(parsed)
    finalizeTemplateState()
    selectedIds.value = []
    jsonEditorOpen.value = false
    jsonEditorError.value = ''
    showStatus('Layout JSON applicato', 'success')
    notifySharedDataSourcesAfterLoad()
  } catch (error) {
    jsonEditorError.value = error.message
  }
}

function buildApiExample() {
  if (!template.value) return ''

  return JSON.stringify(
    {
      templateId: template.value.id,
      printer: selectedPrinter.value,
      values: Object.fromEntries(
        template.value.dataSources.map((source) => [source.name, source.defaultValue ?? ''])
      ),
    },
    null,
    2
  )
}
</script>

<template>
  <div class="designer">
    <header class="toolbar">
      <div class="brand">
        <span class="logo">🍹</span>
        <div>
          <h1>Mojito Label Designer</h1>
          <p>Designer etichette ZPL</p>
        </div>
      </div>

      <div class="toolbar-actions">
        <label class="printer-field">
          Stampante
          <span v-if="printerPlatform" class="platform-tag">
            {{ printerPlatform }}<template v-if="printerMode"> · {{ printerMode === 'graphic' ? 'immagine' : 'ZPL' }}</template>
          </span>
          <select v-if="printers.length" v-model="selectedPrinter">
            <option v-for="printer in printers" :key="printer" :value="printer">
              {{ printer }}
            </option>
          </select>
          <input
            v-else
            v-model="selectedPrinter"
            type="text"
            class="printer-input"
            placeholder="Nome stampante Windows/Linux"
          />
        </label>

        <button type="button" class="btn secondary" :disabled="isBusy || !selectedPrinter.trim()" @click="handleQuickPrint">
          Test stampante
        </button>
        <button type="button" class="btn primary" :disabled="isBusy || !selectedPrinter.trim()" @click="handlePrint">
          Stampa etichetta
        </button>
      </div>
    </header>

    <div v-if="statusMessage" class="status" :class="statusType">
      <p class="status-text">{{ statusMessage }}</p>

      <div v-if="dataSourceRemovalPrompt" class="status-removal-panel">
        <ul class="status-removal-list">
          <li v-for="element in dataSourceRemovalElements" :key="element.id">
            <span>{{ describeElementForUi(element) }}</span>
            <span class="status-removal-actions">
              <button type="button" class="btn-link" @click="selectElementById(element.id)">
                Seleziona
              </button>
              <button type="button" class="btn-link" @click="handlePromptReassignElement(element)">
                Scollega
              </button>
              <button type="button" class="btn-link warn" @click="handlePromptDeleteElement(element)">
                Elimina
              </button>
            </span>
          </li>
        </ul>

        <div class="status-removal-bulk">
          <button type="button" class="btn ghost compact" @click="handlePromptReassignAll">
            Scollega tutti ed elimina campo
          </button>
          <button type="button" class="btn ghost compact danger-text" @click="handlePromptDeleteAll">
            Elimina elementi ed elimina campo
          </button>
          <button type="button" class="btn ghost compact" @click="dismissDataSourceRemovalPrompt">
            Annulla
          </button>
        </div>
      </div>
    </div>

    <main v-if="template" class="workspace">
      <aside class="panel left">
        <div class="panel-scroll">
        <details class="section" :open="sections.batch" @toggle="toggleSection('batch', $event)">
        <summary>Stampa manuale</summary>
        <div class="batch-panel">
          <label>
            Numero di serie nel campo
            <select v-model="batch.dataSource">
              <option v-for="source in template.dataSources" :key="source.name" :value="source.name">
                {{ source.label ?? source.name }}
              </option>
            </select>
          </label>
          <div class="inline-fields">
            <label>
              Da
              <input v-model.number="batch.start" type="number" min="0" step="1" />
            </label>
            <label>
              A
              <input v-model.number="batch.end" type="number" min="0" step="1" />
            </label>
          </div>
          <div class="inline-fields">
            <label>
              Passo
              <input v-model.number="batch.step" type="number" min="1" step="1" />
            </label>
            <label>
              Cifre (zeri davanti)
              <input v-model.number="batch.pad" type="number" min="0" max="12" step="1" />
            </label>
          </div>
          <div class="inline-fields">
            <label>
              Prefisso
              <input v-model="batch.prefix" type="text" placeholder="es. CHL1225" />
            </label>
            <label>
              Suffisso
              <input v-model="batch.suffix" type="text" />
            </label>
          </div>
          <label>
            Copie per etichetta
            <input v-model.number="batch.copies" type="number" min="1" max="100" step="1" />
          </label>

          <p class="hint">
            <strong>{{ batchSummary }}</strong>
            <template v-if="batchSample"><br />{{ batchSample }}</template>
          </p>
          <p v-if="batchCount > MAX_LABELS_PER_RUN" class="hint warn-box">
            Troppe etichette in una volta: il massimo è {{ MAX_LABELS_PER_RUN }}.
          </p>

          <button type="button" class="btn primary" :disabled="!canPrintBatch" @click="handleBatchPrint">
            Stampa serie
          </button>
        </div>
        </details>

        <details class="section" :open="sections.layout" @toggle="toggleSection('layout', $event)">
        <summary>Layout</summary>
        <label>
          Nome layout
          <input v-model="template.name" type="text" />
        </label>

        <div class="layout-actions">
          <button type="button" class="btn primary" @click="handleNewLayout">+ Nuovo layout</button>
          <button type="button" class="btn ghost" @click="handleSaveLocal">Salva locale</button>
          <button type="button" class="btn ghost" :disabled="isBusy" @click="handleSaveServer">
            Salva server
          </button>
          <button
            type="button"
            class="btn ghost"
            :disabled="isBusy"
            title="Salva sul server una copia con un altro nome: il layout aperto resta com'è"
            @click="handleSaveAsServer"
          >
            Salva con nome…
          </button>
          <button type="button" class="btn ghost" @click="handleSaveToFile">Salva su file</button>
          <button type="button" class="btn ghost" @click="handleOpenFromFile">Apri da file</button>
          <label class="file-btn">
            Apri JSON (browser)
            <input type="file" accept="application/json,.json" hidden @change="handleImportLayout" />
          </label>
          <button type="button" class="btn ghost" @click="openJsonEditor">Editor JSON</button>
        </div>

        <label>
          Apri layout
          <select v-model="selectedLayoutId">
            <option value="">Seleziona...</option>
            <option v-for="option in layoutOptions" :key="option.id" :value="option.id">
              {{ option.label }}
            </option>
          </select>
        </label>
        <button type="button" class="btn ghost" :disabled="!selectedLayoutId" @click="handleLoadLayout">
          Carica layout
        </button>
        <button type="button" class="btn ghost danger-text" @click="handleDeleteLocalLayout">
          Elimina copia locale
        </button>
        <button
          type="button"
          class="btn ghost danger-text"
          :disabled="isBusy || !selectedLayoutId"
          @click="handleDeleteServerLayout"
        >
          Elimina dal server
        </button>
        </details>

        <details class="section" :open="sections.properties" @toggle="toggleSection('properties', $event)">
        <summary>Proprietà</summary>
        <div v-if="selectedCount > 1" class="properties">
          <p class="hint">{{ selectedCount }} elementi selezionati</p>
          <p class="hint">Trascina per spostarli · Shift+click · Ctrl+C/V/D · Canc per eliminare</p>
          <div class="property-actions">
            <button type="button" class="btn ghost" @click="duplicateSelected">
              Duplica selezione
            </button>
            <button type="button" class="btn ghost" @click="copySelected">Copia</button>
            <button type="button" class="btn ghost" :disabled="clipboardElements.length === 0" @click="pasteClipboard">
              Incolla
            </button>
            <button type="button" class="btn danger" @click="removeSelected">
              Elimina selezione ({{ selectedCount }})
            </button>
          </div>
        </div>
        <div v-else-if="selectedElement" class="properties">
          <label>
            Tipo
            <input :value="selectedElement.type" disabled />
          </label>
          <label>
            X (dots)
            <input v-model.number="selectedElement.x" type="number" min="0" />
          </label>
          <label>
            Y (dots)
            <input v-model.number="selectedElement.y" type="number" min="0" />
          </label>

          <label v-if="['text', 'barcode', 'qr', 'image'].includes(selectedElement.type)">
            Rotazione
            <select v-model.number="selectedElement.rotation">
              <option :value="0">Nessuna</option>
              <option :value="90">90° in senso orario</option>
              <option :value="180">Capovolto</option>
              <option :value="270">270°</option>
            </select>
            <small class="hint">
              La stampante conosce solo questi quattro orientamenti. L'angolo in alto a
              sinistra resta fermo, come sulla carta.
            </small>
          </label>

          <template v-if="selectedElement.type === 'text'">
            <div v-if="selectedElementSharesDataSource" class="shared-element-warning">
              <p>
                Condivide <code>{{ selectedElement.dataSource }}</code> con altri elementi: il valore
                resta sincronizzato.
              </p>
              <button type="button" class="btn ghost" @click="handleDisconnectFromElement(selectedElement)">
                Scollega da questo elemento
              </button>
            </div>
            <label>
              Data source
              <select
                v-model="selectedElement.dataSource"
                @change="handleDataSourceChange(selectedElement)"
              >
                <option v-for="source in template.dataSources" :key="source.name" :value="source.name">
                  {{ source.name }}
                </option>
              </select>
            </label>
            <label>
              Prefisso
              <input v-model="selectedElement.prefix" type="text" />
            </label>
            <label>
              Font height
              <input v-model.number="selectedElement.fontHeight" type="number" min="10" />
            </label>
            <label>
              Font width
              <input v-model.number="selectedElement.fontWidth" type="number" min="10" />
            </label>
            <label class="checkbox-row">
              <input v-model="selectedElement.bold" type="checkbox" />
              Grassetto
            </label>
            <label class="checkbox-row">
              <input v-model="selectedElement.underline" type="checkbox" />
              Sottolineato
            </label>
          </template>

          <template v-if="selectedElement.type === 'barcode'">
            <div v-if="selectedElementSharesDataSource" class="shared-element-warning">
              <p>
                Condivide <code>{{ selectedElement.dataSource }}</code> con altri elementi: il valore
                resta sincronizzato.
              </p>
              <button type="button" class="btn ghost" @click="handleDisconnectFromElement(selectedElement)">
                Scollega da questo elemento
              </button>
            </div>
            <label>
              Data source
              <select
                v-model="selectedElement.dataSource"
                @change="handleDataSourceChange(selectedElement)"
              >
                <option v-for="source in template.dataSources" :key="source.name" :value="source.name">
                  {{ source.name }}
                </option>
              </select>
            </label>
            <label>
              Tipo
              <select v-model="selectedElement.barcodeType">
                <option value="code128">Code 128</option>
                <option value="code39">Code 39</option>
              </select>
            </label>
            <label>
              Dimensione testo sotto il codice
              <input
                :value="selectedElement.textHeight ?? 0"
                type="number"
                min="0"
                max="200"
                step="1"
                @input="selectedElement.textHeight = Math.max(0, Math.round(Number($event.target.value) || 0))"
              />
              <small class="hint">0 lascia il carattere predefinito della stampante, che è molto piccolo.</small>
            </label>
            <label>
              Altezza
              <input v-model.number="selectedElement.height" type="number" min="20" />
            </label>
            <label>
              Module width
              <input
                :value="selectedElement.moduleWidth"
                type="number"
                min="1"
                max="10"
                step="1"
                @input="selectedElement.moduleWidth = printableMagnification($event.target.value)"
              />
            </label>
          </template>

          <template v-if="selectedElement.type === 'qr'">
            <div v-if="selectedElementSharesDataSource" class="shared-element-warning">
              <p>
                Condivide <code>{{ selectedElement.dataSource }}</code> con altri elementi: il valore
                resta sincronizzato.
              </p>
              <button type="button" class="btn ghost" @click="handleDisconnectFromElement(selectedElement)">
                Scollega da questo elemento
              </button>
            </div>
            <label>
              Data source
              <select
                v-model="selectedElement.dataSource"
                @change="handleDataSourceChange(selectedElement)"
              >
                <option v-for="source in template.dataSources" :key="source.name" :value="source.name">
                  {{ source.name }}
                </option>
              </select>
            </label>
            <label>
              Correzione errore
              <select v-model="selectedElement.errorCorrection">
                <option value="L">L (bassa)</option>
                <option value="M">M (media)</option>
                <option value="Q">Q (alta)</option>
                <option value="H">H (massima)</option>
              </select>
            </label>
            <label>
              Magnification
              <input
                :value="selectedElement.magnification"
                type="number"
                min="1"
                max="10"
                step="1"
                @input="selectedElement.magnification = printableMagnification($event.target.value)"
              />
            </label>
          </template>

          <template v-if="selectedElement.type === 'image'">
            <label class="inline-check">
              <input v-model="keepImageRatio" type="checkbox" />
              Mantieni le proporzioni
            </label>
            <label>
              Larghezza
              <input
                :value="selectedElement.width"
                type="number"
                min="10"
                @input="setImageSize('width', $event.target.value)"
              />
            </label>
            <label>
              Altezza
              <input
                :value="selectedElement.height"
                type="number"
                min="10"
                @input="setImageSize('height', $event.target.value)"
              />
            </label>
            <label>
              Soglia bianco/nero
              <input
                :value="selectedElement.threshold ?? 128"
                type="range"
                min="16"
                max="240"
                step="1"
                @input="selectedElement.threshold = Number($event.target.value)"
              />
              <small class="hint">
                {{ selectedElement.threshold ?? 128 }} — la stampante fa punti neri o niente.
                Più alta, più nero: regolala guardando l'anteprima.
              </small>
            </label>
            <label>
              Immagine
              <input type="file" accept="image/*" @change="onImageUpload" />
            </label>
          </template>

          <div class="property-actions">
            <button type="button" class="btn ghost" @click="duplicateSelected">Duplica</button>
            <button type="button" class="btn ghost" @click="copySelected">Copia</button>
            <button type="button" class="btn ghost" :disabled="clipboardElements.length === 0" @click="pasteClipboard">
              Incolla
            </button>
            <button type="button" class="btn danger" @click="removeSelected">Elimina elemento</button>
          </div>
        </div>
        <p v-else class="hint">Seleziona uno o più elementi sul canvas · Ctrl+C/V/D</p>
        </details>

        <details class="section" :open="sections.elements" @toggle="toggleSection('elements', $event)">
        <summary>Elementi</summary>
        <div class="palette">
          <button type="button" @click="addElement('text')">+ Testo</button>
          <button type="button" @click="addElement('barcode')">+ Barcode</button>
          <button type="button" @click="addElement('qr')">+ QR</button>
          <button type="button" @click="addElement('image')">+ Immagine</button>
        </div>
        </details>

        <details class="section" :open="sections.dataSources" @toggle="toggleSection('dataSources', $event)">
        <summary>Named Data Sources</summary>
        <div v-if="sharedDataSources.length" class="shared-datasource-alert">
          <strong>Data source condivisi</strong>
          <p class="hint">
            Lo stesso campo è collegato a più elementi: mostrano sempre lo stesso valore in stampa.
          </p>
          <div v-for="group in sharedDataSources" :key="group.name" class="shared-group">
            <p class="shared-group-title">
              <code>{{ group.name }}</code>
              <span>· {{ group.elements.length }} elementi</span>
            </p>
            <ul class="shared-element-list">
              <li v-for="element in group.elements" :key="element.id">
                <span>{{ describeElementForUi(element) }}</span>
                <span class="shared-element-actions">
                  <button type="button" class="btn-link" @click="selectElementById(element.id)">
                    Seleziona
                  </button>
                  <button type="button" class="btn-link warn" @click="handleDisconnectFromElement(element)">
                    Scollega
                  </button>
                </span>
              </li>
            </ul>
          </div>
        </div>
        <div class="data-sources">
          <!-- Key per indice, NON per nome: se la key cambiasse col nome,
               Vue ricreerebbe la riga durante la rinomina e il campo
               perderebbe il focus a ogni tasto. -->
          <div
            v-for="(source, sourceIndex) in template.dataSources"
            :key="sourceIndex"
            class="data-row"
            :class="{ shared: countElementsUsingDataSource(template, source.name) > 1 }"
          >
            <div class="data-row-header">
              <input v-model="source.label" type="text" placeholder="Etichetta" />
              <span
                v-if="countElementsUsingDataSource(template, source.name) > 1"
                class="usage-badge"
                :title="`Usato da ${countElementsUsingDataSource(template, source.name)} elementi`"
              >
                ×{{ countElementsUsingDataSource(template, source.name) }}
              </span>
              <button
                type="button"
                class="icon-btn"
                :title="countElementsUsingDataSource(template, source.name) > 0 ? 'In uso: elimina prima gli elementi sul canvas' : 'Elimina data source'"
                @click="handleRemoveDataSource(source.name)"
              >
                ✕
              </button>
            </div>
            <input
              :value="source.name"
              type="text"
              placeholder="nome_variabile"
              @change="handleDataSourceRename(source, $event.target.value, $event)"
            />
            <input v-model="source.defaultValue" type="text" placeholder="Valore di test" />
          </div>
          <button type="button" class="btn ghost" @click="addDataSource">+ Data source</button>
        </div>
        </details>

        <details class="section" :open="sections.label" @toggle="toggleSection('label', $event)">
        <summary>Etichetta</summary>
        <label>
          Formato
          <select v-model="selectedFormatId">
            <option v-for="format in LABEL_FORMATS" :key="format.id" :value="format.id">
              {{ format.name }}
            </option>
            <option :value="CUSTOM_FORMAT_ID">Personalizzato</option>
          </select>
        </label>
        <label>
          Come stampare
          <select v-model="printMode">
            <option value="zpl">Comandi ZPL (Zebra, Citizen, compatibili)</option>
            <option value="graphic">Stampa normale di sistema (immagine)</option>
          </select>
          <small v-if="printerMode && !printModeMismatch" class="hint ok-text">
            {{ selectedPrinter }} vuole {{ describePrintMode(printerMode) }}: il layout è
            allineato, impostato in automatico.
          </small>
          <small v-else-if="printModeMismatch" class="hint warn-box">
            <strong>{{ selectedPrinter }} vuole {{ describePrintMode(printerMode) }}</strong>, il
            layout è su {{ describePrintMode(printMode) }}. Stampata così, l'etichetta può uscire
            bianca o illeggibile.
            <button type="button" class="btn ghost compact" @click="applyPrinterPrintMode">
              Usa {{ describePrintMode(printerMode) }}
            </button>
          </small>
          <small v-else class="hint">
            Non tutte le stampanti parlano ZPL. Con "stampa normale" il server
            disegna l'etichetta e la manda alla coda di stampa come immagine,
            usando il driver installato: è la strada per stampanti come la
            Munbyn ITPP941P.
          </small>
        </label>
        <label>
          Avanzamento carta
          <select v-model="mediaTracking">
            <option value="gap">Etichette con spazio (gap)</option>
            <option value="mark">Tacca nera sul retro</option>
            <option value="continuous">Carta continua</option>
            <option value="none">Non impostare (usa la stampante)</option>
          </select>
          <small class="hint">
            Con "gap" la stampante si riallinea all'inizio di ogni etichetta: è il
            rimedio quando la stampa scivola un po' più in là ad ogni copia.
          </small>
        </label>
        <label>
          Intensità di stampa
          <input v-model.number="template.darkness" type="number" min="0" max="30" step="1" />
          <small class="hint">0 lascia la taratura della stampante. Se le etichette escono sbiadite, alza fino a 20-25.</small>
        </label>
        <label>
          Velocità di stampa
          <input v-model.number="template.printSpeed" type="number" min="0" max="14" step="1" />
          <small class="hint">0 lascia quella della stampante. Più lenta stampa più nero.</small>
        </label>
        <label>
          Risoluzione stampante
          <select v-model.number="selectedDpi">
            <option v-for="resolution in dpiOptions" :key="resolution.dpi" :value="resolution.dpi">
              {{ resolution.label }}
            </option>
          </select>
        </label>

        <p v-if="printerDpi && !resolutionMismatch" class="hint ok-text">
          {{ selectedPrinter }} stampa a {{ printerDpi }} dpi: il disegno è allineato.
        </p>

        <div v-else-if="resolutionMismatch" class="hint warn-box">
          <strong>{{ selectedPrinter }} stampa a {{ printerDpi }} dpi</strong>, il disegno è a
          {{ selectedDpi }}. Stampata così, l'etichetta uscirà di misura diversa da quella che vedi.
          <button type="button" class="btn ghost compact" @click="applyPrinterResolution">
            Porta il disegno a {{ printerDpi }} dpi
          </button>
        </div>
        <p class="hint">
          Cambiando risoluzione, etichetta ed elementi vengono riscalati per
          mantenere le stesse misure in mm.
        </p>
        <div class="size-row">
          <label>
            Larghezza (mm)
            <input v-model.number="labelWidthMm" type="number" min="10" max="104" step="0.5" />
          </label>
          <label>
            Altezza (mm)
            <input v-model.number="labelHeightMm" type="number" min="6" step="0.5" />
          </label>
        </div>
        <div class="size-row">
          <label>
            Larghezza (dots)
            <input v-model.number="template.labelWidth" type="number" min="80" />
          </label>
          <label>
            Altezza (dots)
            <input v-model.number="template.labelHeight" type="number" min="48" />
          </label>
        </div>
        <button type="button" class="btn ghost" @click="fitLayoutToLabel">
          Adatta layout all'etichetta
        </button>
        <div class="size-row">
          <label>
            Offset origine X (dots)
            <input v-model.number="template.originX" type="number" min="0" />
          </label>
          <label>
            Offset origine Y (dots)
            <input v-model.number="template.originY" type="number" min="0" />
          </label>
        </div>
        <p class="hint">
          Se la stampa esce tagliata sul bordo sinistro/alto, aumenta l'offset
          per spostare tutto il contenuto (es. 24 dots ≈ 2 mm a 300 dpi).
        </p>
        </details>

        <details v-if="isGraphicMode" class="section" :open="sections.preview" @toggle="toggleSection('preview', $event)">
          <summary>Anteprima di stampa</summary>
          <div class="batch-panel">
            <img v-if="labelImagePreview" class="label-image-preview" :src="labelImagePreview" alt="Anteprima etichetta" />
            <p v-else class="hint">Anteprima non disponibile: controlla il layout e la stampante.</p>
          </div>
        </details>

        <details class="section devtools-panel" :open="sections.devtools" @toggle="toggleSection('devtools', $event)">
          <summary>API &amp; ZPL</summary>
          <div class="devtools-body">
            <p class="devtools-label">API esempio</p>
            <pre class="zpl-preview api-example">{{ buildApiExample() }}</pre>
            <p class="devtools-label">ZPL Preview</p>
            <pre class="zpl-preview">{{ zplPreview }}</pre>
          </div>
        </details>
        </div>
      </aside>

      <section class="canvas-area">
        <LabelCanvas
          v-model:template="template"
          v-model:selected-ids="selectedIds"
          :data-values="dataValues"
          @update-text-value="handleUpdateTextValue"
        />
      </section>
    </main>

    <div v-else class="loading">Caricamento template...</div>

    <div
      v-if="jsonEditorOpen"
      class="json-editor-overlay"
      @click.self="closeJsonEditor"
    >
      <div class="json-editor-dialog" role="dialog" aria-labelledby="json-editor-title">
        <header class="json-editor-header">
          <div>
            <h2 id="json-editor-title">Editor JSON layout</h2>
            <p class="hint">Modifica a mano il layout. Servono almeno <code>elements</code> e <code>dataSources</code>.</p>
          </div>
          <button type="button" class="icon-btn json-editor-close" title="Chiudi" @click="closeJsonEditor">
            ✕
          </button>
        </header>

        <p v-if="jsonEditorError" class="json-editor-error">{{ jsonEditorError }}</p>

        <textarea
          v-model="jsonEditorText"
          class="json-editor-textarea"
          spellcheck="false"
          autocapitalize="off"
          autocomplete="off"
          autocorrect="off"
        />

        <div class="json-editor-actions">
          <button type="button" class="btn ghost" @click="refreshJsonEditorFromCanvas">
            Ricarica da canvas
          </button>
          <button type="button" class="btn ghost" @click="closeJsonEditor">Annulla</button>
          <button type="button" class="btn primary" @click="applyJsonEditor">Applica</button>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.designer {
  height: 100dvh;
  max-height: 100dvh;
  overflow: hidden;
  display: flex;
  flex-direction: column;
}

/* Barra alta abbastanza da respirare (min 64px) con controlli da almeno
   40px: sono i bersagli che si prendono al volo anche col touch. */
.toolbar {
  flex-shrink: 0;
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 1rem;
  min-height: 64px;
  padding: 0.75rem 1.25rem;
  background: #16213e;
  color: #fff;
}

.brand {
  display: flex;
  align-items: center;
  gap: 0.75rem;
}

.logo {
  font-size: 2rem;
}

.brand h1 {
  margin: 0;
  font-size: 1.25rem;
}

.brand p {
  margin: 0;
  opacity: 0.75;
  font-size: 0.85rem;
}

.toolbar-actions {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 0.75rem 1rem;
}

.toolbar-actions label {
  display: flex;
  flex-direction: column;
  gap: 0.3rem;
  font-size: 0.8rem;
}

.toolbar-actions select {
  min-width: 220px;
  min-height: 44px;
  padding: 0.5rem 0.6rem;
  border-radius: 6px;
  border: none;
  font: inherit;
}

.toolbar-actions .btn {
  min-height: 44px;
  padding: 0.6rem 1.1rem;
  align-self: flex-end;
}

.btn {
  border: none;
  border-radius: 8px;
  padding: 0.55rem 1rem;
  font-weight: 600;
}

.btn.primary {
  background: #0f9d58;
  color: #fff;
}

.btn.secondary {
  background: #e8f5e9;
  color: #1b5e20;
}

.btn.ghost {
  background: transparent;
  border: 1px dashed #bbb;
}

.btn.danger {
  background: #ffebee;
  color: #b71c1c;
}

.danger-text {
  color: #b71c1c;
}

.status {
  flex-shrink: 0;
  padding: 0.4rem 1.25rem;
  font-size: 0.88rem;
}

.status-text {
  margin: 0;
}

.status-removal-panel {
  margin-top: 0.5rem;
  padding-top: 0.5rem;
  border-top: 1px solid rgba(0, 0, 0, 0.08);
}

.status-removal-list {
  margin: 0 0 0.5rem;
  padding: 0;
  list-style: none;
  display: grid;
  gap: 0.35rem;
}

.status-removal-list li {
  display: flex;
  justify-content: space-between;
  gap: 0.75rem;
  align-items: center;
  flex-wrap: wrap;
}

.status-removal-actions {
  display: flex;
  gap: 0.5rem;
  flex-wrap: wrap;
}

.status-removal-bulk {
  display: flex;
  gap: 0.5rem;
  flex-wrap: wrap;
}

.btn.compact {
  padding: 0.35rem 0.65rem;
  font-size: 0.78rem;
}

.status.success {
  background: #e8f5e9;
  color: #1b5e20;
}

.status.error {
  background: #ffebee;
  color: #b71c1c;
}

.status.info {
  background: #e3f2fd;
  color: #0d47a1;
}

.status.warn {
  background: #fff8e1;
  color: #f57f17;
}

.printer-field {
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
  min-width: 220px;
}

.platform-tag {
  font-size: 0.75rem;
  opacity: 0.7;
}

.printer-input {
  min-height: 44px;
  padding: 0.5rem 0.6rem;
  border: 1px solid #ccc;
  border-radius: 6px;
  font: inherit;
}

.workspace {
  flex: 1;
  min-height: 0;
  display: grid;
  grid-template-columns: 320px minmax(0, 1fr);
  gap: 0.75rem;
  padding: 0.75rem;
  overflow: hidden;
}

.panel {
  background: #fff;
  border-radius: 12px;
  padding: 0.75rem;
  box-shadow: 0 1px 4px rgba(0, 0, 0, 0.08);
  min-height: 0;
  display: flex;
  flex-direction: column;
  overflow: hidden;
}

.panel-scroll {
  flex: 1;
  min-height: 0;
  overflow-y: auto;
  overscroll-behavior: contain;
  padding-right: 0.15rem;
}

.panel h2 {
  margin: 1rem 0 0.75rem;
  font-size: 0.95rem;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  color: #555;
}

.panel h2:first-child {
  margin-top: 0;
}

/* Ogni sezione del pannello si apre e si chiude dal suo titolo: i pannelli
   sono lunghi e chi lavora sempre sulle stesse cose vuole nascondere il resto. */
.section {
  border-top: 1px solid #eee;
  padding: 0.35rem 0 0.5rem;
}

.section:first-child {
  border-top: none;
  padding-top: 0;
}

.section > summary {
  cursor: pointer;
  user-select: none;
  min-height: 44px;
  display: flex;
  align-items: center;
  gap: 0.4rem;
  font-size: 0.95rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  color: #555;
  list-style: none;
}

.section > summary::-webkit-details-marker {
  display: none;
}

.section > summary::before {
  content: '▸';
  font-size: 0.85rem;
  transition: transform 0.15s ease;
}

.section[open] > summary::before {
  transform: rotate(90deg);
}

.section[open] > summary {
  margin-bottom: 0.5rem;
}

.layout-actions {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 0.35rem;
  margin-bottom: 0.75rem;
}

.file-btn {
  display: inline-block;
  text-align: center;
  padding: 0.5rem;
  border-radius: 8px;
  border: 1px dashed #bbb;
  cursor: pointer;
}

.palette {
  display: grid;
  gap: 0.5rem;
  margin-bottom: 1rem;
}

.palette button,
.data-sources button,
.layout-actions button {
  padding: 0.5rem;
  border-radius: 8px;
  border: 1px solid #ddd;
  background: #fafafa;
}

.data-sources {
  display: grid;
  gap: 0.75rem;
}

.data-row {
  display: grid;
  gap: 0.25rem;
  padding: 0.5rem;
  border: 1px solid #eee;
  border-radius: 8px;
}

.data-row.shared {
  border-color: #ffcc80;
  background: #fff8e1;
}

.usage-badge {
  flex-shrink: 0;
  padding: 0.15rem 0.45rem;
  border-radius: 999px;
  background: #ffe0b2;
  color: #e65100;
  font-size: 0.75rem;
  font-weight: 700;
}

.shared-datasource-alert {
  margin-bottom: 0.75rem;
  padding: 0.75rem;
  border: 1px solid #ffcc80;
  border-radius: 8px;
  background: #fff8e1;
  max-height: 160px;
  overflow-y: auto;
  overscroll-behavior: contain;
}

.shared-datasource-alert strong {
  display: block;
  margin-bottom: 0.25rem;
  color: #e65100;
}

.shared-group + .shared-group {
  margin-top: 0.75rem;
  padding-top: 0.75rem;
  border-top: 1px solid #ffe0b2;
}

.shared-group-title {
  margin: 0 0 0.35rem;
  font-size: 0.85rem;
}

.shared-group-title code {
  font-size: 0.82rem;
}

.shared-element-list {
  margin: 0;
  padding-left: 1rem;
  display: grid;
  gap: 0.35rem;
  font-size: 0.82rem;
}

.shared-element-list li {
  display: flex;
  justify-content: space-between;
  gap: 0.5rem;
  align-items: center;
}

.shared-element-actions {
  display: flex;
  gap: 0.35rem;
  flex-shrink: 0;
}

.btn-link {
  border: none;
  background: none;
  padding: 0;
  color: #1565c0;
  font-size: 0.78rem;
  font-weight: 600;
  cursor: pointer;
  text-decoration: underline;
}

.btn-link.warn {
  color: #e65100;
}

.shared-element-warning {
  padding: 0.65rem 0.75rem;
  border: 1px solid #ffcc80;
  border-radius: 8px;
  background: #fff8e1;
  font-size: 0.85rem;
}

.shared-element-warning p {
  margin: 0 0 0.5rem;
}

.data-row-header {
  display: flex;
  gap: 0.5rem;
  align-items: center;
}

.icon-btn {
  width: 2rem;
  height: 2rem;
  border: 1px solid #ddd;
  border-radius: 6px;
  background: #fff;
  color: #b71c1c;
}

.data-row input,
.properties input,
.properties select,
.panel label input,
.panel label select {
  width: 100%;
  padding: 0.4rem 0.5rem;
  border: 1px solid #ccc;
  border-radius: 6px;
}

.panel label {
  display: grid;
  gap: 0.25rem;
  font-size: 0.85rem;
  margin-bottom: 0.75rem;
}

.canvas-area {
  min-height: 0;
  overflow: auto;
  overscroll-behavior: contain;
  display: flex;
  align-items: flex-start;
  justify-content: center;
  background: #fff;
  border-radius: 12px;
  box-shadow: 0 1px 4px rgba(0, 0, 0, 0.08);
  padding: 0.75rem;
}

.properties {
  display: grid;
  gap: 0.75rem;
  margin-bottom: 1.5rem;
}

.property-actions {
  display: grid;
  gap: 0.5rem;
}

.properties label {
  display: grid;
  gap: 0.25rem;
  font-size: 0.85rem;
}

.size-row {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 0.5rem;
}

.checkbox-row {
  display: flex;
  align-items: center;
  gap: 0.5rem;
}

.checkbox-row input[type='checkbox'] {
  width: auto;
}

.hint {
  color: #888;
  font-size: 0.9rem;
}

.inline-fields {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 0.5rem;
}

.batch-panel {
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
  padding: 0.6rem;
  border: 1px solid #eee;
  border-radius: 6px;
  background: #fafafa;
}

.label-image-preview {
  width: 100%;
  height: auto;
  border: 1px solid #ddd;
  border-radius: 4px;
  background: #fff;
  image-rendering: pixelated;
}

.devtools-panel > summary {
  font-size: 0.82rem;
}

.devtools-body {
  display: grid;
  gap: 0.5rem;
  margin-top: 0.5rem;
  max-height: min(34vh, 280px);
  overflow-y: auto;
  overscroll-behavior: contain;
}

.devtools-label {
  margin: 0;
  font-size: 0.78rem;
  font-weight: 600;
  color: #666;
}

.zpl-preview {
  background: #1e1e1e;
  color: #d4d4d4;
  padding: 0.75rem;
  border-radius: 8px;
  font-size: 0.72rem;
  max-height: 120px;
  overflow: auto;
  white-space: pre-wrap;
  word-break: break-all;
  margin: 0;
}

.api-example {
  max-height: 100px;
}

.json-editor-overlay {
  position: fixed;
  inset: 0;
  z-index: 1000;
  display: grid;
  place-items: center;
  padding: 1rem;
  background: rgba(0, 0, 0, 0.55);
}

.json-editor-dialog {
  width: min(960px, 100%);
  max-height: min(90vh, 900px);
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
  padding: 1rem 1.25rem 1.25rem;
  background: #fff;
  border-radius: 12px;
  box-shadow: 0 20px 60px rgba(0, 0, 0, 0.25);
}

.json-editor-header {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  gap: 1rem;
}

.json-editor-header h2 {
  margin: 0 0 0.25rem;
  font-size: 1.1rem;
}

.json-editor-close {
  font-size: 1.1rem;
}

.json-editor-error {
  margin: 0;
  padding: 0.65rem 0.75rem;
  border-radius: 8px;
  background: #fdecea;
  color: #b3261e;
  font-size: 0.9rem;
}

.json-editor-textarea {
  flex: 1;
  min-height: 420px;
  resize: vertical;
  border: 1px solid #ccc;
  border-radius: 8px;
  padding: 0.75rem;
  font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
  font-size: 0.82rem;
  line-height: 1.45;
  tab-size: 2;
}

.json-editor-actions {
  display: flex;
  justify-content: flex-end;
  gap: 0.5rem;
  flex-wrap: wrap;
}

.loading {
  flex: 1;
  display: grid;
  place-items: center;
  text-align: center;
}

@media (max-height: 760px) {
  .brand p {
    display: none;
  }

  .toolbar {
    padding: 0.45rem 1rem;
  }

  .panel h2 {
    margin: 0.65rem 0 0.5rem;
    font-size: 0.85rem;
  }
}
</style>
