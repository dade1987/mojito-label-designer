/**
 * Quanto si può ingrandire e rimpicciolire il foglio.
 *
 * Il tetto era il 600%: su un'etichetta piccola non basta per lavorare sui
 * dettagli, perché un codice a barre fitto o un testo di pochi millimetri
 * restano illeggibili proprio mentre li si sta sistemando. Ora si arriva al
 * 2000%, che su un'etichetta da 50 mm significa vedere il singolo punto di
 * stampa.
 */
export const ZOOM_MIN = 0.25
export const ZOOM_MAX = 20
export const ZOOM_STEP = 1.25

export function clampZoom(value) {
  const zoom = Number(value)

  // Un valore non numerico riporta alla dimensione reale: meglio 1:1 che un
  // foglio rimasto a una scala assurda.
  if (!Number.isFinite(zoom)) return 1

  return Math.min(ZOOM_MAX, Math.max(ZOOM_MIN, zoom))
}
