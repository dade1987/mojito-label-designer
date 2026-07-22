<script setup>
import { nextTick, ref, computed } from 'vue'
import {
  BARCODE_TEXT_FONT_FAMILY,
  buildElementDisplayValues,
  computeBarcodeMetrics,
  computeTextStyle,
  formatBarcodeValue,
  formatDisplayText,
} from '../utils/canvasDisplay.js'
import {
  DEFAULT_PX_PER_MM,
  getScreenPxPerMm,
  resetScreenCalibration,
  setScreenPxPerMm,
} from '../utils/screenCalibration.js'
import {
  cycleStackSelection,
  elementsAtPoint,
  normalizeRect,
  qrPlaceholderSize,
  selectElementsInRect,
} from '../utils/canvasSelection.js'
import { dotsToMm } from '../utils/labelFormats.js'
import { resolveElementValue } from '../utils/templateStore.js'

const template = defineModel('template', { required: true })
const selectedIds = defineModel('selectedIds', { default: () => [] })

const emit = defineEmits(['update-text-value'])

const props = defineProps({
  dataValues: {
    type: Object,
    required: true,
  },
})

const canvasRef = ref(null)
const groupDrag = ref(null)
const editingId = ref(null)
const editValue = ref('')
const editInputRef = ref(null)
const marquee = ref(null)

// Zoom scelto dall'utente: 1 = dimensione reale (grande come su carta).
const ZOOM_MIN = 0.25
const ZOOM_MAX = 6
const ZOOM_STEP = 1.25
const zoom = ref(1)

// px CSS per mm dello schermo (calibrabile col righello per la dimensione reale).
const pxPerMm = ref(getScreenPxPerMm())
const showCalibration = ref(false)
// Larghezza/altezza di una carta ISO (bancomat/credito): riferimento comodo.
const CARD_MM_W = 85.6
const CARD_MM_H = 53.98

// px CSS per dot = px/mm × mm/dot; a zoom 1 l'etichetta è a dimensione reale.
const baseScale = computed(() => (pxPerMm.value * 25.4) / (template.value.dpi || 203))
const scale = computed(() => baseScale.value * zoom.value)
const zoomPercent = computed(() => Math.round(zoom.value * 100))
const cardStyle = computed(() => ({
  width: `${CARD_MM_W * pxPerMm.value}px`,
  height: `${CARD_MM_H * pxPerMm.value}px`,
}))

function saveCalibration() {
  setScreenPxPerMm(pxPerMm.value)
  showCalibration.value = false
}

function resetCalibration() {
  resetScreenCalibration()
  pxPerMm.value = DEFAULT_PX_PER_MM
}

function clampZoom(value) {
  return Math.min(ZOOM_MAX, Math.max(ZOOM_MIN, value))
}

function zoomIn() {
  zoom.value = clampZoom(zoom.value * ZOOM_STEP)
}

function zoomOut() {
  zoom.value = clampZoom(zoom.value / ZOOM_STEP)
}

function zoomReal() {
  zoom.value = 1
}

const canvasStyle = computed(() => ({
  width: `${template.value.labelWidth * scale.value}px`,
  height: `${template.value.labelHeight * scale.value}px`,
}))

const elementDisplayValues = computed(() =>
  buildElementDisplayValues(
    template.value.elements,
    props.dataValues,
    template.value.dataSources
  )
)

const marqueeStyle = computed(() => {
  if (!marquee.value) return null

  const rect = normalizeRect(
    marquee.value.startX,
    marquee.value.startY,
    marquee.value.currentX,
    marquee.value.currentY
  )

  return {
    left: `${rect.x * scale.value}px`,
    top: `${rect.y * scale.value}px`,
    width: `${rect.width * scale.value}px`,
    height: `${rect.height * scale.value}px`,
  }
})

function isSelected(id) {
  return selectedIds.value.includes(id)
}

function setSelection(ids) {
  selectedIds.value = ids
}

function toggleSelection(id) {
  if (isSelected(id)) {
    setSelection(selectedIds.value.filter((item) => item !== id))
    return
  }

  setSelection([...selectedIds.value, id])
}

function clientToCanvasDots(clientX, clientY) {
  const rect = canvasRef.value.getBoundingClientRect()

  return {
    x: (clientX - rect.left) / scale.value,
    y: (clientY - rect.top) / scale.value,
  }
}

function startMarquee(event) {
  if (editingId.value) return

  const point = clientToCanvasDots(event.clientX, event.clientY)
  marquee.value = {
    startX: point.x,
    startY: point.y,
    currentX: point.x,
    currentY: point.y,
  }

  window.addEventListener('mousemove', onMarqueeMove)
  window.addEventListener('mouseup', stopMarquee)
}

function onMarqueeMove(event) {
  if (!marquee.value) return

  const point = clientToCanvasDots(event.clientX, event.clientY)
  marquee.value.currentX = point.x
  marquee.value.currentY = point.y
}

function stopMarquee() {
  if (!marquee.value) return

  const rect = normalizeRect(
    marquee.value.startX,
    marquee.value.startY,
    marquee.value.currentX,
    marquee.value.currentY
  )

  if (rect.width < 4 && rect.height < 4) {
    setSelection([])
  } else {
    setSelection(
      selectElementsInRect(template.value.elements, elementDisplayValues.value, rect)
    )
  }

  marquee.value = null
  window.removeEventListener('mousemove', onMarqueeMove)
  window.removeEventListener('mouseup', stopMarquee)
}

function startDrag(event, element) {
  if (editingId.value === element.id) return
  if (event.detail > 1) return
  if (!canvasRef.value) return

  if (event.shiftKey) {
    toggleSelection(element.id)
    return
  }

  // Alt+clic: raggiungi l'elemento sotto quello in cima nel punto cliccato,
  // così elementi sovrapposti restano selezionabili/spostabili singolarmente.
  let targetId = element.id
  if (event.altKey) {
    const point = clientToCanvasDots(event.clientX, event.clientY)
    const stack = elementsAtPoint(template.value.elements, elementDisplayValues.value, point)
    const current = selectedIds.value.length === 1 ? selectedIds.value[0] : element.id
    targetId = cycleStackSelection(stack, current) ?? element.id
  }

  const targetElement = template.value.elements.find((item) => item.id === targetId) ?? element

  if (!isSelected(targetId)) {
    setSelection([targetId])
  }

  const rect = canvasRef.value.getBoundingClientRect()
  const pointerX = (event.clientX - rect.left) / scale.value
  const pointerY = (event.clientY - rect.top) / scale.value

  const starts = {}
  for (const item of template.value.elements) {
    if (isSelected(item.id)) {
      starts[item.id] = { x: item.x, y: item.y }
    }
  }

  groupDrag.value = {
    starts,
    anchorId: targetId,
    offsetX: pointerX - targetElement.x,
    offsetY: pointerY - targetElement.y,
  }

  window.addEventListener('mousemove', onDrag)
  window.addEventListener('mouseup', stopDrag)
}

function onDrag(event) {
  if (!groupDrag.value || !canvasRef.value) return

  const rect = canvasRef.value.getBoundingClientRect()
  const pointerX = (event.clientX - rect.left) / scale.value
  const pointerY = (event.clientY - rect.top) / scale.value
  const anchorStart = groupDrag.value.starts[groupDrag.value.anchorId]
  if (!anchorStart) return

  const anchorX = Math.max(0, Math.round(pointerX - groupDrag.value.offsetX))
  const anchorY = Math.max(0, Math.round(pointerY - groupDrag.value.offsetY))
  const deltaX = anchorX - anchorStart.x
  const deltaY = anchorY - anchorStart.y

  for (const item of template.value.elements) {
    const start = groupDrag.value.starts[item.id]
    if (!start) continue

    item.x = Math.max(0, Math.round(start.x + deltaX))
    item.y = Math.max(0, Math.round(start.y + deltaY))
  }
}

function stopDrag() {
  groupDrag.value = null
  window.removeEventListener('mousemove', onDrag)
  window.removeEventListener('mouseup', stopDrag)
}

async function startTextEdit(event, element) {
  event.stopPropagation()
  setSelection([element.id])
  editingId.value = element.id
  editValue.value = resolveElementValue(
    element,
    props.dataValues,
    template.value.dataSources
  )

  await nextTick()
  editInputRef.value?.focus()
  editInputRef.value?.select()
}

function commitTextEdit(element) {
  if (editingId.value !== element.id) return

  emit('update-text-value', { element, value: editValue.value })
  editingId.value = null
  editValue.value = ''
}

function cancelTextEdit() {
  editingId.value = null
  editValue.value = ''
}

function setEditInputRef(element, node) {
  if (editingId.value === element.id) {
    editInputRef.value = node
  }
}

function onElementClick(event, id) {
  // La selezione con Alt (elemento sottostante) è gestita in startDrag: qui la
  // ignoriamo, altrimenti il click finale ri-selezionerebbe quello in cima.
  if (event.altKey) {
    return
  }

  if (event.shiftKey) {
    toggleSelection(id)
    return
  }

  if (!isSelected(id)) {
    setSelection([id])
  }
}

function elementStyle(element) {
  return {
    left: `${element.x * scale.value}px`,
    top: `${element.y * scale.value}px`,
  }
}

function textStyle(element) {
  return computeTextStyle(element, scale.value)
}

const barcodeMetricsById = computed(() => {
  const map = {}

  for (const element of template.value.elements) {
    if (element.type === 'barcode') {
      map[element.id] = computeBarcodeMetrics(element, elementDisplayValues.value)
    }
  }

  return map
})

function barcodeMetrics(element) {
  return (
    barcodeMetricsById.value[element.id] ??
    computeBarcodeMetrics(element, elementDisplayValues.value)
  )
}

function barcodeTextStyle(element) {
  const metrics = barcodeMetrics(element)

  return {
    fontSize: `${metrics.textFontDots * scale.value}px`,
    marginTop: `${metrics.textGapDots * scale.value}px`,
    fontFamily: BARCODE_TEXT_FONT_FAMILY,
    fontWeight: '400',
    lineHeight: '1',
  }
}

function imageStyle(element) {
  return {
    width: `${(element.width ?? 80) * scale.value}px`,
    height: `${(element.height ?? 80) * scale.value}px`,
  }
}

function qrStyle(element) {
  const size = qrPlaceholderSize(element) * scale.value

  return { width: `${size}px`, height: `${size}px` }
}

function displayText(element) {
  return formatDisplayText(element, elementDisplayValues.value)
}

function displayBarcodeValue(element) {
  return formatBarcodeValue(element, elementDisplayValues.value)
}
</script>

<template>
  <div class="canvas-wrapper">
    <div class="canvas-meta">
      <span class="meta-dims">
        {{ template.labelWidth }} × {{ template.labelHeight }} dots @ {{ template.dpi }} DPI ·
        {{ dotsToMm(template.labelWidth, template.dpi) }} × {{ dotsToMm(template.labelHeight, template.dpi) }} mm
      </span>
      <span class="zoom-ctrl" role="group" aria-label="Zoom anteprima">
        <button type="button" title="Riduci zoom" aria-label="Riduci zoom" @click="zoomOut">−</button>
        <button
          type="button"
          class="zoom-value"
          title="Torna alla dimensione reale (come su carta)"
          @click="zoomReal"
        >{{ zoomPercent }}%</button>
        <button type="button" title="Aumenta zoom" aria-label="Aumenta zoom" @click="zoomIn">+</button>
      </span>
      <button
        type="button"
        class="cal-toggle"
        :class="{ active: showCalibration }"
        title="Calibra la dimensione reale dello schermo"
        @click="showCalibration = !showCalibration"
      >📐 Calibra</button>
      <span class="meta-hint">trascina area vuota per selezione multipla</span>
    </div>

    <div v-if="showCalibration" class="calibrate">
      <p class="cal-text">
        Appoggia allo schermo una carta (bancomat/credito, <b>85,6 mm</b>) e regola il cursore
        finché il rettangolo è largo esattamente come la carta. Poi salva: al <b>100%</b> di zoom
        l'etichetta sarà a dimensione reale.
      </p>
      <div class="cal-card" :style="cardStyle"><span>85,6 × 54 mm</span></div>
      <div class="cal-row">
        <input
          type="range"
          min="0.3"
          max="16"
          step="0.02"
          :value="pxPerMm"
          aria-label="Calibrazione px per mm"
          @input="pxPerMm = Number($event.target.value)"
        />
        <span class="cal-val">
          <input
            type="number"
            min="0.2"
            max="40"
            step="0.05"
            :value="pxPerMm.toFixed(2)"
            aria-label="px per mm"
            @input="pxPerMm = Number($event.target.value) || pxPerMm"
          />
          px/mm
        </span>
      </div>
      <div class="cal-actions">
        <button type="button" class="cal-save" @click="saveCalibration">Salva</button>
        <button type="button" @click="resetCalibration">Reimposta</button>
        <button type="button" @click="showCalibration = false">Chiudi</button>
      </div>
    </div>

    <div
      ref="canvasRef"
      class="canvas"
      :style="canvasStyle"
      @mousedown.self="startMarquee"
    >
      <div
        v-if="marqueeStyle"
        class="marquee"
        :style="marqueeStyle"
      />

      <div
        v-for="element in template.elements"
        :key="`${element.id}-${elementDisplayValues[element.id] ?? ''}-${element.bold ? 'b' : 'n'}-${element.underline ? 'u' : 'n'}`"
        class="element"
        :class="[element.type, { selected: isSelected(element.id), editing: editingId === element.id }]"
        :style="elementStyle(element)"
        @mousedown.prevent="startDrag($event, element)"
        @click.stop="onElementClick($event, element.id)"
      >
        <template v-if="element.type === 'text'">
          <input
            v-if="editingId === element.id"
            :ref="(node) => setEditInputRef(element, node)"
            v-model="editValue"
            class="text-edit-input"
            :style="textStyle(element)"
            @mousedown.stop
            @click.stop
            @dblclick.stop
            @blur="commitTextEdit(element)"
            @keydown.enter.prevent="commitTextEdit(element)"
            @keydown.esc.prevent="cancelTextEdit"
          />
          <span
            v-else
            :style="textStyle(element)"
            title="Doppio clic per modificare"
            @dblclick.stop.prevent="startTextEdit($event, element)"
          >{{ displayText(element) || '(testo)' }}</span>
        </template>

        <template v-else-if="element.type === 'barcode'">
          <div class="barcode">
            <svg
              class="bars"
              :width="barcodeMetrics(element).widthDots * scale"
              :height="barcodeMetrics(element).barsHeightDots * scale"
              :viewBox="`0 0 ${barcodeMetrics(element).totalModules} 1`"
              preserveAspectRatio="none"
            >
              <rect
                v-for="(bar, index) in barcodeMetrics(element).bars"
                :key="index"
                :x="bar.x"
                y="0"
                :width="bar.width"
                height="1"
              />
            </svg>
            <small v-if="barcodeMetrics(element).showText" :style="barcodeTextStyle(element)">
              {{ displayBarcodeValue(element) }}
            </small>
          </div>
        </template>

        <template v-else-if="element.type === 'qr'">
          <div class="qr-placeholder" :style="qrStyle(element)">
            <span>QR</span>
            <small>{{ elementDisplayValues[element.id] || '' }}</small>
          </div>
        </template>

        <template v-else-if="element.type === 'image'">
          <img
            v-if="element.imageData"
            :src="element.imageData"
            alt="logo"
            :style="imageStyle(element)"
          />
          <div v-else class="image-placeholder" :style="imageStyle(element)">IMG</div>
        </template>
      </div>
    </div>
  </div>
</template>

<style scoped>
.canvas-wrapper {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 0.5rem;
  width: 100%;
  min-height: min-content;
}

.canvas-meta {
  font-size: 0.85rem;
  color: #666;
  text-align: center;
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: center;
  gap: 0.5rem 0.9rem;
}

.zoom-ctrl {
  display: inline-flex;
  align-items: stretch;
  border: 1px solid #d0d0d0;
  border-radius: 7px;
  overflow: hidden;
  background: #fff;
}

.zoom-ctrl button {
  border: none;
  background: #fff;
  color: #333;
  font-size: 0.85rem;
  line-height: 1;
  padding: 0.25rem 0.55rem;
  cursor: pointer;
}

.zoom-ctrl button:hover {
  background: #f0f0f0;
}

.zoom-ctrl button:focus-visible {
  outline: 2px solid #0f9d58;
  outline-offset: -2px;
}

.zoom-ctrl .zoom-value {
  min-width: 3.4rem;
  font-variant-numeric: tabular-nums;
  border-inline: 1px solid #e5e5e5;
  font-weight: 600;
}

.meta-hint {
  color: #999;
}

.cal-toggle {
  border: 1px solid #d0d0d0;
  border-radius: 7px;
  background: #fff;
  color: #333;
  font-size: 0.8rem;
  padding: 0.25rem 0.6rem;
  cursor: pointer;
}

.cal-toggle:hover { background: #f0f0f0; }
.cal-toggle.active { border-color: #0f9d58; color: #0f9d58; }

.calibrate {
  width: min(520px, 92%);
  border: 1px solid #d7ddd9;
  border-radius: 12px;
  background: #fafbfa;
  padding: 1rem 1.1rem 1.1rem;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 0.85rem;
  box-shadow: 0 6px 20px -12px rgba(0, 0, 0, 0.35);
}

.cal-text {
  margin: 0;
  font-size: 0.85rem;
  color: #555;
  text-align: center;
  line-height: 1.45;
}

.cal-card {
  border: 2px solid #0f9d58;
  border-radius: 10px;
  background: repeating-linear-gradient(-45deg, #eef6f1 0 8px, #e4f0ea 8px 16px);
  display: grid;
  place-items: center;
  min-width: 40px;
  min-height: 26px;
}

.cal-card span {
  font-size: 0.72rem;
  color: #0b7a41;
  font-weight: 600;
  background: rgba(255, 255, 255, 0.75);
  padding: 1px 6px;
  border-radius: 5px;
}

.cal-row {
  display: flex;
  align-items: center;
  gap: 0.75rem;
  width: 100%;
}

.cal-row input[type="range"] { flex: 1; accent-color: #0f9d58; }

.cal-val {
  font-variant-numeric: tabular-nums;
  font-size: 0.8rem;
  color: #444;
  min-width: 6.5rem;
  text-align: right;
  display: inline-flex;
  align-items: center;
  gap: 0.3rem;
  white-space: nowrap;
}

.cal-val input[type="number"] {
  width: 4.2rem;
  padding: 0.2rem 0.35rem;
  border: 1px solid #cfcfcf;
  border-radius: 6px;
  font-size: 0.8rem;
  text-align: right;
  font-variant-numeric: tabular-nums;
}

.cal-actions { display: flex; gap: 0.5rem; }

.cal-actions button {
  border: 1px solid #cfcfcf;
  background: #fff;
  border-radius: 7px;
  padding: 0.35rem 0.9rem;
  font-size: 0.85rem;
  cursor: pointer;
}

.cal-actions button:hover { background: #f2f2f2; }

.cal-actions .cal-save {
  background: #0f9d58;
  border-color: #0f9d58;
  color: #fff;
  font-weight: 600;
}

.cal-actions .cal-save:hover { background: #0c8a4c; }

.canvas {
  position: relative;
  background: #fff;
  border: 2px dashed #bbb;
  border-radius: 8px;
  box-shadow: inset 0 0 0 1px #eee;
  overflow: hidden;
  cursor: crosshair;
}

.marquee {
  position: absolute;
  border: 1px dashed #1a73e8;
  background: rgba(26, 115, 232, 0.12);
  pointer-events: none;
  z-index: 2;
}

.element {
  /* Niente padding/bordi: l'angolo in alto a sinistra coincide con ^FO x,y. */
  position: absolute;
  cursor: move;
  user-select: none;
  outline: 1px dashed transparent;
  outline-offset: 2px;
  z-index: 1;
}

.element.selected {
  outline-color: #0f9d58;
  background: rgba(15, 157, 88, 0.08);
  /* L'elemento selezionato passa sopra gli altri: così, se un nuovo elemento
     nasce sopra un altro, lo si può afferrare e spostare senza incastri. */
  z-index: 3;
}

.element.editing {
  outline-color: #1a73e8;
  background: rgba(26, 115, 232, 0.08);
  z-index: 4;
}

.element.text span {
  display: inline-block;
  /* pre (non nowrap): preserva gli spazi multipli come fa la stampante,
     altrimenti "46.8 V    6Ah" verrebbe collassato e la scritta risulterebbe
     più stretta a schermo che in stampa. */
  white-space: pre;
  cursor: text;
}

.text-edit-input {
  min-width: 4rem;
  border: 1px solid #1a73e8;
  border-radius: 4px;
  padding: 0 4px;
  font-family: monospace;
  background: #fff;
  color: #111;
}

.barcode {
  display: flex;
  flex-direction: column;
  align-items: center;
}

.barcode .bars {
  display: block;
}

.barcode .bars rect {
  fill: #000;
}

.image-placeholder {
  display: grid;
  place-items: center;
  background: #f5f5f5;
  border: 1px dashed #999;
  color: #666;
  font-size: 0.75rem;
}

.qr-placeholder {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 2px;
  background: repeating-conic-gradient(#333 0% 25%, #fff 0% 50%) 0 0 / 10px 10px;
  border: 1px solid #333;
  color: #333;
  font-size: 0.7rem;
  font-weight: 700;
  overflow: hidden;
  text-align: center;
}

.qr-placeholder span {
  background: #fff;
  padding: 0 3px;
}

.qr-placeholder small {
  background: #fff;
  padding: 0 2px;
  max-width: 100%;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  font-weight: 400;
}

.element img {
  display: block;
  object-fit: contain;
}
</style>
