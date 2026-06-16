<script setup>
import { nextTick, ref, computed } from 'vue'
import {
  buildElementDisplayValues,
  computeBarcodeBarsStyle,
  computeBarcodeStyle,
  computeScale,
  formatBarcodeValue,
  formatDisplayText,
} from '../utils/canvasDisplay.js'
import { resolveElementValue } from '../utils/templateStore.js'

const template = defineModel('template', { required: true })
const selectedId = defineModel('selectedId', { default: null })

const emit = defineEmits(['update-text-value'])

const props = defineProps({
  dataValues: {
    type: Object,
    required: true,
  },
})

const canvasRef = ref(null)
const dragging = ref(null)
const dragOffset = ref({ x: 0, y: 0 })
const editingId = ref(null)
const editValue = ref('')
const editInputRef = ref(null)

const scale = computed(() => computeScale(template.value.labelWidth))

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

function selectElement(id) {
  selectedId.value = id
}

function startDrag(event, element) {
  if (editingId.value === element.id) return
  if (event.detail > 1) return
  if (!canvasRef.value) return

  dragging.value = element
  selectedId.value = element.id

  const rect = canvasRef.value.getBoundingClientRect()
  dragOffset.value = {
    x: (event.clientX - rect.left) / scale.value - element.x,
    y: (event.clientY - rect.top) / scale.value - element.y,
  }

  window.addEventListener('mousemove', onDrag)
  window.addEventListener('mouseup', stopDrag)
}

function onDrag(event) {
  if (!dragging.value || !canvasRef.value) return

  const rect = canvasRef.value.getBoundingClientRect()
  const x = Math.max(0, Math.round((event.clientX - rect.left) / scale.value - dragOffset.value.x))
  const y = Math.max(0, Math.round((event.clientY - rect.top) / scale.value - dragOffset.value.y))

  dragging.value.x = x
  dragging.value.y = y
}

function stopDrag() {
  dragging.value = null
  window.removeEventListener('mousemove', onDrag)
  window.removeEventListener('mouseup', stopDrag)
}

async function startTextEdit(event, element) {
  event.stopPropagation()
  selectedId.value = element.id
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

function elementStyle(element) {
  return {
    left: `${element.x * scale.value}px`,
    top: `${element.y * scale.value}px`,
  }
}

function textStyle(element) {
  const factor = scale.value * (element.fontHeight ?? 30) / 30
  return {
    fontSize: `${Math.max(10, 12 * factor)}px`,
    fontWeight: element.bold ? '700' : '400',
  }
}

function barcodeStyle(element) {
  return computeBarcodeStyle(element, elementDisplayValues.value, scale.value)
}

function barcodeBarsStyle(element) {
  return computeBarcodeBarsStyle(element, elementDisplayValues.value)
}

function imageStyle(element) {
  return {
    width: `${(element.width ?? 80) * scale.value}px`,
    height: `${(element.height ?? 80) * scale.value}px`,
  }
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
      {{ template.labelWidth }} × {{ template.labelHeight }} dots @ {{ template.dpi }} DPI
      (scale {{ Math.round(scale * 100) }}%)
    </div>

    <div
      ref="canvasRef"
      class="canvas"
      :style="canvasStyle"
      @click.self="selectedId = null"
    >
      <div
        v-for="element in template.elements"
        :key="`${element.id}-${elementDisplayValues[element.id] ?? ''}-${element.bold ? 'b' : 'n'}`"
        class="element"
        :class="[element.type, { selected: selectedId === element.id, editing: editingId === element.id }]"
        :style="elementStyle(element)"
        @mousedown.prevent="startDrag($event, element)"
        @click.stop="selectElement(element.id)"
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
          >
            {{ displayText(element) || '(testo)' }}
          </span>
        </template>

        <template v-else-if="element.type === 'barcode'">
          <div class="barcode" :style="barcodeStyle(element)">
            <div class="bars" :style="barcodeBarsStyle(element)"></div>
            <small>{{ displayBarcodeValue(element) }}</small>
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
}

.canvas-meta {
  font-size: 0.85rem;
  color: #666;
}

.canvas {
  position: relative;
  background: #fff;
  border: 2px dashed #bbb;
  border-radius: 8px;
  box-shadow: inset 0 0 0 1px #eee;
  overflow: hidden;
}

.element {
  position: absolute;
  cursor: move;
  user-select: none;
  padding: 2px;
  border: 1px solid transparent;
}

.element.selected {
  border-color: #0f9d58;
  background: rgba(15, 157, 88, 0.08);
}

.element.editing {
  border-color: #1a73e8;
  background: rgba(26, 115, 232, 0.08);
}

.element.text span {
  white-space: nowrap;
  font-family: monospace;
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
  justify-content: center;
  background: #fff;
  border: 1px solid #333;
}

.barcode .bars {
  flex: 1;
  width: 92%;
  margin-bottom: 4px;
}

.barcode small {
  font-size: 0.65rem;
  font-family: monospace;
}

.image-placeholder {
  display: grid;
  place-items: center;
  background: #f5f5f5;
  border: 1px dashed #999;
  color: #666;
  font-size: 0.75rem;
}

.element img {
  display: block;
  object-fit: contain;
}
</style>
