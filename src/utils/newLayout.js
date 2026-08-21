import { createEmptyTemplate } from './templateStore.js'

/**
 * Ricominciare da un foglio pulito.
 *
 * Il pannello aveva "salva", "apri" e "carica", ma non un modo per iniziare
 * un'etichetta nuova: si ricaricava la pagina, o si cancellavano a mano gli
 * elementi della precedente.
 *
 * Formato e risoluzione vengono conservati da quello che si stava usando:
 * chi disegna etichette lavora quasi sempre sullo stesso formato, e
 * rimpostarlo ogni volta è tempo perso. Il nome no, quello riparte: un
 * layout nuovo che si chiama come il precedente si salva sopra per sbaglio.
 */
export function startNewLayout(current = null) {
  const fresh = createEmptyTemplate()

  if (!current) return fresh

  return {
    ...fresh,
    labelWidth: Number(current.labelWidth) > 0 ? current.labelWidth : fresh.labelWidth,
    labelHeight: Number(current.labelHeight) > 0 ? current.labelHeight : fresh.labelHeight,
    dpi: Number(current.dpi) > 0 ? current.dpi : fresh.dpi,
  }
}

/**
 * Se sul foglio c'è qualcosa da perdere. Su un layout già vuoto chiedere
 * conferma sarebbe solo un clic in più.
 */
export function hasWork(template) {
  return Boolean(template && Array.isArray(template.elements) && template.elements.length > 0)
}
