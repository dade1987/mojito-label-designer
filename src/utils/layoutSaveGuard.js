import { generateId } from './id.js'
import { sanitizeTemplateForSave } from './layoutStorage.js'

/**
 * Quando un salvataggio va fermato per non perdere un layout esistente.
 *
 * "Salva" scriveva sempre sopra il layout con lo stesso identificativo: chi
 * apriva un'etichetta, la rinominava e salvava perdeva l'originale senza un
 * avviso. Qui si decide se quello che si sta per scrivere è un normale
 * aggiornamento (stesso id, stesso nome) o una rinomina sospetta, e come si
 * costruisce una copia nuova per "Salva con nome…".
 */

const UNNAMED = 'Etichetta senza nome'

function normalizeName(name) {
  return String(name ?? '').trim()
}

function sameName(a, b) {
  return normalizeName(a).toLowerCase() === normalizeName(b).toLowerCase()
}

/**
 * Il layout già salvato che il salvataggio sovrascriverebbe, o null se
 * l'identificativo non esiste ancora. `renamed` è true quando il nome è
 * cambiato: è il caso in cui l'utente probabilmente voleva un layout nuovo.
 */
export function findOverwriteTarget(layouts, template) {
  const id = template?.id
  if (!id) return null

  const existing = (layouts ?? []).find((layout) => layout.id === id)
  if (!existing) return null

  return {
    id: existing.id,
    name: existing.name,
    renamed: normalizeName(existing.name) !== normalizeName(template.name),
  }
}

export function findLayoutByName(layouts, name, { excludeId } = {}) {
  const wanted = normalizeName(name)
  if (wanted === '') return null

  return (
    (layouts ?? []).find((layout) => layout.id !== excludeId && sameName(layout.name, wanted)) ?? null
  )
}

/**
 * Il nome da proporre per la copia: quello attuale se è libero, altrimenti
 * "(copia)", "(copia 2)", …
 */
export function proposeSaveAsName(name, layouts) {
  const base = normalizeName(name) || UNNAMED

  if (!findLayoutByName(layouts, base)) return base

  let index = 1
  let candidate = `${base} (copia)`

  while (findLayoutByName(layouts, candidate)) {
    index += 1
    candidate = `${base} (copia ${index})`
  }

  return candidate
}

/** Una copia del layout con identificativo nuovo e il nome scelto. */
export function prepareSaveAs(template, name) {
  return {
    ...sanitizeTemplateForSave(template),
    id: generateId(),
    name: normalizeName(name),
  }
}

export function overwriteQuestion(target, newName, where) {
  return (
    `Esiste già ${where} il layout «${target.name}» con lo stesso identificativo: ` +
    `sovrascriverlo con «${normalizeName(newName)}»? ` +
    'Annulla e usa «Salva con nome…» per tenerli entrambi.'
  )
}

/**
 * Il template di partenza è un punto di partenza, non un layout salvato:
 * senza identificativo il primo salvataggio ne crea uno nuovo invece di
 * scrivere sempre sullo stesso file da tutte le postazioni.
 */
export function asUnsavedStartingPoint(template) {
  if (!template) return null

  const { id: _ignored, ...rest } = template

  return rest
}
