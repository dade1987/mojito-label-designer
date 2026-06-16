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
import { buildValuesFromSources, createElement, updateElementTextValue } from '../utils/templateStore.js'
import { cloneTemplateState } from '../utils/cloneSerializable.js'
import {
  deleteLocalLayout,
  importLayoutFromFile,
  openLayoutFromFile,
  saveLayoutToFile,
  isDataSourceInUse,
  listLocalLayouts,
  loadLocalLayout,
  removeDataSource,
  saveLocalLayout,
} from '../utils/layoutStorage.js'
import LabelCanvas from './LabelCanvas.vue'

const template = ref(null)
const selectedId = ref(null)
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

const dataValues = computed(() => {
  if (!template.value) return {}
  return buildValuesFromSources(template.value.dataSources)
})

const selectedElement = computed(() =>
  template.value?.elements.find((el) => el.id === selectedId.value) ?? null
)

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
    template.value = hydrateTemplate(defaultTemplate)
    serverLayouts.value = templates
    refreshLocalLayouts()
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

function refreshLocalLayouts() {
  localLayouts.value = listLocalLayouts()
}

function showStatus(message, type = 'info') {
  statusMessage.value = message
  statusType.value = type
}

function addElement(type) {
  if (!template.value) return
  const element = createElement(type, 40, 40 + template.value.elements.length * 40)
  template.value.elements.push(element)
  selectedId.value = element.id
}

function removeSelected() {
  if (!template.value || !selectedId.value) return
  template.value.elements = template.value.elements.filter((el) => el.id !== selectedId.value)
  selectedId.value = null
}

function handleKeydown(event) {
  const target = event.target
  const tag = target?.tagName?.toLowerCase() ?? ''

  if (tag === 'input' || tag === 'textarea' || tag === 'select' || target?.isContentEditable) {
    return
  }

  if (event.key === 'Delete' && selectedId.value) {
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

  if (isDataSourceInUse(template.value, name)) {
    const confirmed = window.confirm(
      `Il data source "${name}" è usato da uno o più elementi. Eliminarlo comunque? Gli elementi verranno riassegnati al primo data source disponibile.`
    )
    if (!confirmed) return
  }

  template.value = removeDataSource(template.value, name)
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

    const currentValues = buildValuesFromSources(template.value.dataSources)
    template.value = hydrateTemplate({
      ...loaded,
      dataSources: (loaded.dataSources ?? []).map((source) => ({
        ...source,
        defaultValue: currentValues[source.name] ?? source.defaultValue ?? '',
      })),
    })
    selectedId.value = null
    showStatus(`Layout caricato: ${loaded.name}`, 'success')
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
    selectedId.value = null
    showStatus(
      opened.filePath ? `Layout aperto: ${opened.filePath}` : `Layout aperto: ${opened.name}`,
      'success'
    )
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
    selectedId.value = null
    showStatus(`Layout importato: ${imported.name}`, 'success')
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
      {{ statusMessage }}
    </div>

    <main v-if="template" class="workspace">
      <aside class="panel left">
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
        <div class="data-sources">
          <div v-for="source in template.dataSources" :key="source.name" class="data-row">
            <div class="data-row-header">
              <input v-model="source.label" type="text" placeholder="Etichetta" />
              <button
                type="button"
                class="icon-btn"
                title="Elimina data source"
                @click="handleRemoveDataSource(source.name)"
              >
                ✕
              </button>
            </div>
            <input v-model="source.name" type="text" placeholder="nome_variabile" />
            <input v-model="source.defaultValue" type="text" placeholder="Valore di test" />
          </div>
          <button type="button" class="btn ghost" @click="addDataSource">+ Data source</button>
        </div>
      </aside>

      <section class="canvas-area">
        <LabelCanvas
          v-model:template="template"
          v-model:selected-id="selectedId"
          :data-values="dataValues"
          @update-text-value="handleUpdateTextValue"
        />
      </section>

      <aside class="panel right">
        <h2>Proprietà</h2>
        <div v-if="selectedElement" class="properties">
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
            <label>
              Data source
              <select v-model="selectedElement.dataSource">
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
          </template>

          <template v-if="selectedElement.type === 'barcode'">
            <label>
              Data source
              <select v-model="selectedElement.dataSource">
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
        <p v-else class="hint">Seleziona un elemento sul canvas</p>

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

        <h2>API esempio</h2>
        <pre class="zpl-preview api-example">{{ buildApiExample() }}</pre>

        <h2>ZPL Preview</h2>
        <pre class="zpl-preview">{{ zplPreview }}</pre>
      </aside>
    </main>

    <div v-else class="loading">Caricamento template...</div>
  </div>
</template>

<style scoped>
.designer {
  min-height: 100vh;
  display: flex;
  flex-direction: column;
}

.toolbar {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 1rem;
  padding: 1rem 1.5rem;
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
  padding: 0.75rem 1.5rem;
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
  display: grid;
  grid-template-columns: 280px 1fr 320px;
  gap: 1rem;
  padding: 1rem;
}

.panel {
  background: #fff;
  border-radius: 12px;
  padding: 1rem;
  box-shadow: 0 1px 4px rgba(0, 0, 0, 0.08);
  overflow: auto;
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
  gap: 0.5rem;
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
  min-height: 500px;
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

.zpl-preview {
  background: #1e1e1e;
  color: #d4d4d4;
  padding: 0.75rem;
  border-radius: 8px;
  font-size: 0.72rem;
  max-height: 240px;
  overflow: auto;
  white-space: pre-wrap;
  word-break: break-all;
}

.api-example {
  max-height: 180px;
}

.loading {
  padding: 2rem;
  text-align: center;
}
</style>
