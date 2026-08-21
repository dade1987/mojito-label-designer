/**
 * La risoluzione a cui stampa la stampante scelta, secondo il server.
 *
 * Disegnare a 203 punti per pollice un'etichetta che verrà stampata a 300
 * significa mandarla in stampa di misura sbagliata: giusta sullo schermo,
 * storta sulla carta. È un errore che non dà nessun segnale finché non si
 * guarda l'etichetta stampata.
 */
export function resolutionForPrinter(resolutions, printer) {
  if (!resolutions || typeof resolutions !== 'object') return null

  const name = String(printer ?? '').trim()
  if (name === '') return null

  const dpi = Number(resolutions[name])

  return Number.isFinite(dpi) && dpi > 0 ? dpi : null
}

/**
 * Se vale la pena avvisare: solo quando la stampante dichiara una risoluzione
 * e il disegno ne usa un'altra.
 */
export function shouldWarnResolution(templateDpi, printerDpi) {
  if (!printerDpi) return false

  return Number(templateDpi) !== Number(printerDpi)
}
