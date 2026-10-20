/**
 * La serie di numeri di serie da stampare in una volta sola.
 *
 * Sulle etichette dei pacchi il numero cambia da un'etichetta all'altra
 * (LOTTO1, LOTTO2, ...): senza una serie l'operatore dovrebbe cambiare a mano
 * il valore e premere Stampa per ogni pezzo. Qui si descrive l'intervallo una
 * volta e si ottengono tutti i valori, già formattati.
 */

/** Oltre questo numero non è una stampa manuale: è una coda che va fermata. */
export const MAX_LABELS_PER_RUN = 1000

function toWholeNumber(value, name) {
  if (typeof value === 'boolean' || value === null || value === undefined || value === '') {
    throw new Error(`${name} deve essere un numero.`)
  }

  const number = Number(value)

  if (!Number.isFinite(number) || !Number.isInteger(number)) {
    throw new Error(`${name} deve essere un numero intero.`)
  }

  if (number < 0) {
    throw new Error(`${name} non può essere negativo.`)
  }

  return number
}

function normalizeRange({ start, end, step = 1 }) {
  const from = toWholeNumber(start, 'Il numero iniziale')
  const to = toWholeNumber(end, 'Il numero finale')
  const by = toWholeNumber(step ?? 1, 'Il passo')

  if (by < 1) {
    throw new Error('Il passo deve essere almeno 1.')
  }

  if (to < from) {
    throw new Error('Il numero finale deve essere maggiore o uguale a quello iniziale.')
  }

  return { from, to, by }
}

/**
 * Quante etichette produrrà l'intervallo, 0 se l'intervallo non è valido.
 * Serve all'anteprima nella barra di stampa, dove un errore mentre si digita
 * non deve diventare un messaggio rosso.
 */
export function serialRangeCount({ start, end, step = 1 }) {
  try {
    const { from, to, by } = normalizeRange({ start, end, step })

    return Math.floor((to - from) / by) + 1
  } catch {
    return 0
  }
}

/**
 * I numeri di serie dell'intervallo, in ordine.
 */
export function buildSerialRange({ start, end, step = 1, pad = 0, prefix = '', suffix = '' }) {
  const { from, to, by } = normalizeRange({ start, end, step })
  const width = toWholeNumber(pad ?? 0, 'Il riempimento di zeri')
  const total = Math.floor((to - from) / by) + 1

  if (total > MAX_LABELS_PER_RUN) {
    throw new Error(`Troppe etichette (${total}): il massimo per una stampa è ${MAX_LABELS_PER_RUN}.`)
  }

  const serials = []

  for (let value = from; value <= to; value += by) {
    serials.push(`${prefix}${String(value).padStart(width, '0')}${suffix}`)
  }

  return serials
}

/**
 * I valori da mandare al server, uno per etichetta: ogni numero di serie
 * finisce nella sorgente dati scelta nel layout.
 */
export function buildPrintJobs(serials, dataSource) {
  const name = String(dataSource ?? '').trim()

  if (name === '') {
    throw new Error('Scegli la sorgente dati che riceve il numero di serie.')
  }

  return serials.map((serial) => ({ [name]: serial }))
}

/**
 * Etichette piccole: il codice o il QR portano il codice completo del pacco
 * (prefisso + numero + suffisso, es. CTW10140T20S0926111), un secondo campo
 * facoltativo solo il numero (111) da leggere a occhio.
 */
export function buildSeriesJobs(range, { codeField, numberField = '' } = {}) {
  const code = String(codeField ?? '').trim()
  const number = String(numberField ?? '').trim()

  if (code === '') {
    throw new Error('Scegli la sorgente dati che riceve il numero di serie.')
  }

  if (number === code) {
    throw new Error('Il codice completo e il solo numero vanno in due campi diversi.')
  }

  const { prefix = '', suffix = '' } = range
  const numbers = buildSerialRange({ ...range, prefix: '', suffix: '' })

  return numbers.map((value) => ({
    [code]: `${prefix ?? ''}${value}${suffix ?? ''}`,
    ...(number === '' ? {} : { [number]: value }),
  }))
}

const MAX_PAD = 12

function wholeNumberOr(value, fallback) {
  const parsed = Number(value)

  return value !== null && value !== '' && Number.isInteger(parsed) ? parsed : fallback
}

function textOr(value) {
  return typeof value === 'string' ? value : ''
}

/**
 * Le impostazioni della serie salvate nel layout (campi, prefisso, zeri,
 * passo): Da, A e copie cambiano a ogni stampa e non si salvano.
 */
export function normalizeSeriesSettings(raw = {}) {
  const source = raw !== null && typeof raw === 'object' ? raw : {}
  const step = wholeNumberOr(source.step, 1)

  return {
    codeField: textOr(source.codeField),
    numberField: textOr(source.numberField),
    prefix: textOr(source.prefix),
    suffix: textOr(source.suffix),
    pad: Math.min(MAX_PAD, Math.max(0, wholeNumberOr(source.pad, 0))),
    step: step >= 1 ? step : 1,
  }
}

/**
 * Il riepilogo che l'operatore legge prima di premere Stampa.
 */
export function describeRun(labels, copies) {
  if (labels < 1) {
    return 'nessuna etichetta'
  }

  const labelsText = labels === 1 ? '1 etichetta' : `${labels} etichette`

  if (copies <= 1) {
    return labelsText
  }

  return `${labelsText} × ${copies} copie = ${labels * copies} stampe`
}
