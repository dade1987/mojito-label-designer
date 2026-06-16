<script setup>
import { ref, computed, onMounted, onUnmounted, watch } from 'vue'
import {
  fetchDefaultTemplate,
  fetchPrinters,
  fetchTemplate,
  fetchTemplates,
  previewZpl,
  printLabel,
  saveTemplate,
} from '../utils/api.js'
import {
  buildValuesFromSources,
  countElementsUsingDataSource,
  createElement,
  describeElementForUi,
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
import LabelCanvas from './LabelCanvas.vue'

const template = ref(null)
const selectedIds = ref([])
const printers = ref([])
const selectedPrinter = ref('')
const printerPlatform = ref('')
const zplPreview = ref('')
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

const dataValues = computed(() => {
  if (!template.value) return {}
  return buildValuesFromSources(template.value.dataSources)
})

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
    const [{ printers: list, platform, diagnostics }, defaultTemplate, { templates }] = await Promise.all([
      fetchPrinters(),
      fetchDefaultTemplate(),
      fetchTemplates().catch(() => ({ templates: [] })),
    ])
    printers.value = Array.isArray(list) ? list : []
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
    id: raw.id ?? crypto.randomUUID(),
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

  if (!remembered) {
    return { template: defaultTemplate, selectedLayout: '' }
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

  return { template: defaultTemplate, selectedLayout: '' }
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

function rememberDataSourceName(source) {
  source._renamePrevious = source.name
}

function handleDataSourceNameBlur(source) {
  if (!template.value) return

  const previousName = source._renamePrevious ?? source.name
  const result = renameDataSource(template.value, previousName, source.name)

  if (!result.ok) {
    if (result.reason === 'duplicate') {
      source.name = previousName
      showStatus('Nome già usato da un altro data source.', 'error')
    }
    return
  }

  delete source._renamePrevious
}

function handleKeydown(event) {
  const target = event.target
  const tag = target?.tagName?.toLowerCase() ?? ''

  if (tag === 'input' || tag === 'textarea' || tag === 'select' || target?.isContentEditable) {
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
}

async function handlePrint() {
  if (!template.value) return

  isBusy.value = true

  try {
    ensurePrinterSelected()
    await printLabel({
      template: template.value,
      values: dataValues.value,
      printer: selectedPrinter.value,
    })
    showStatus(`Etichetta inviata a ${selectedPrinter.value}`, 'success')
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

function handleSaveLocal() {
  if (!template.value) return
  const saved = saveLocalLayout(template.value)
  template.value.id = saved.id
  refreshLocalLayouts()
  showStatus(`Layout salvato in locale: ${saved.name}`, 'success')
}

async function handleSaveServer() {
  if (!template.value) return

  isBusy.value = true

  try {
    const result = await saveTemplate(template.value)
    template.value = hydrateTemplate(result.template)
    rememberActiveLayout('server', result.template.id)
    const { templates } = await fetchTemplates()
    serverLayouts.value = templates
    showStatus(`Layout salvato sul server: ${result.template.name}`, 'success')
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
          <p>POC ZPL · Citizen CL-S700 / CL-S703Z</p>
        </div>
      </div>

      <div class="toolbar-actions">
        <label class="printer-field">
          Stampante
          <span v-if="printerPlatform" class="platform-tag">{{ printerPlatform }}</span>
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
          Stampa rapida
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
        <h2>Layout</h2>
        <label>
          Nome layout
          <input v-model="template.name" type="text" />
        </label>

        <div class="layout-actions">
          <button type="button" class="btn ghost" @click="handleSaveLocal">Salva locale</button>
          <button type="button" class="btn ghost" :disabled="isBusy" @click="handleSaveServer">
            Salva server
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

        <h2>Elementi</h2>
        <div class="palette">
          <button type="button" @click="addElement('text')">+ Testo</button>
          <button type="button" @click="addElement('barcode')">+ Barcode</button>
          <button type="button" @click="addElement('image')">+ Immagine</button>
        </div>

        <h2>Named Data Sources</h2>
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
          <div
            v-for="source in template.dataSources"
            :key="source.name"
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
            <input v-model="source.name" type="text" placeholder="nome_variabile" @focus="rememberDataSourceName(source)" @blur="handleDataSourceNameBlur(source)" />
            <input v-model="source.defaultValue" type="text" placeholder="Valore di test" />
          </div>
          <button type="button" class="btn ghost" @click="addDataSource">+ Data source</button>
        </div>
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

      <aside class="panel right">
        <div class="panel-scroll">
        <h2>Proprietà</h2>
        <div v-if="selectedCount > 1" class="properties">
          <p class="hint">{{ selectedCount }} elementi selezionati</p>
          <p class="hint">Trascina per spostarli insieme · Shift+click per aggiungere/togliere · Canc per eliminare</p>
          <button type="button" class="btn danger" @click="removeSelected">
            Elimina selezione ({{ selectedCount }})
          </button>
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
              Altezza
              <input v-model.number="selectedElement.height" type="number" min="20" />
            </label>
            <label>
              Module width
              <input v-model.number="selectedElement.moduleWidth" type="number" min="1" max="10" />
            </label>
          </template>

          <template v-if="selectedElement.type === 'image'">
            <label>
              Larghezza
              <input v-model.number="selectedElement.width" type="number" min="10" />
            </label>
            <label>
              Altezza
              <input v-model.number="selectedElement.height" type="number" min="10" />
            </label>
            <label>
              Immagine
              <input type="file" accept="image/*" @change="onImageUpload" />
            </label>
          </template>

          <button type="button" class="btn danger" @click="removeSelected">Elimina elemento</button>
        </div>
        <p v-else class="hint">Seleziona uno o più elementi sul canvas (trascina un’area vuota)</p>

        <h2>Etichetta</h2>
        <label>
          Larghezza (dots)
          <input v-model.number="template.labelWidth" type="number" min="100" />
        </label>
        <label>
          Altezza (dots)
          <input v-model.number="template.labelHeight" type="number" min="100" />
        </label>
        <label>
          DPI
          <input v-model.number="template.dpi" type="number" min="150" />
        </label>
        </div>

        <details class="devtools-panel">
          <summary>API &amp; ZPL</summary>
          <div class="devtools-body">
            <p class="devtools-label">API esempio</p>
            <pre class="zpl-preview api-example">{{ buildApiExample() }}</pre>
            <p class="devtools-label">ZPL Preview</p>
            <pre class="zpl-preview">{{ zplPreview }}</pre>
          </div>
        </details>
      </aside>
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

.toolbar {
  flex-shrink: 0;
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 1rem;
  padding: 0.65rem 1.25rem;
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
  align-items: end;
  gap: 0.75rem;
}

.toolbar-actions label {
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
  font-size: 0.8rem;
}

.toolbar-actions select {
  min-width: 220px;
  padding: 0.4rem 0.5rem;
  border-radius: 6px;
  border: none;
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
  padding: 0.45rem 0.6rem;
  border: 1px solid #ccc;
  border-radius: 6px;
  font: inherit;
}

.workspace {
  flex: 1;
  min-height: 0;
  display: grid;
  grid-template-columns: 260px minmax(0, 1fr) 290px;
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

.properties label {
  display: grid;
  gap: 0.25rem;
  font-size: 0.85rem;
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

.devtools-panel {
  flex-shrink: 0;
  margin-top: 0.5rem;
  padding-top: 0.5rem;
  border-top: 1px solid #eee;
}

.devtools-panel summary {
  cursor: pointer;
  font-size: 0.82rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  color: #555;
  user-select: none;
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
