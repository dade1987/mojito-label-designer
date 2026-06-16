/**
 * Clona oggetti serializzabili evitando structuredClone su proxy Vue/reactive.
 */
export function toPlainObject(value) {
  if (value === undefined || value === null) {
    return value
  }

  return JSON.parse(JSON.stringify(value))
}

export function cloneTemplateState(template) {
  return toPlainObject(template)
}
