/**
 * La strada di stampa della stampante scelta, secondo il server.
 *
 * Mandare comandi ZPL a una stampante che non li parla non dà errori: esce
 * carta bianca o testo a caso. Se il server sa che modello è, il layout può
 * mettersi da solo sulla strada giusta invece di far indovinare.
 */
export function printModeForPrinter(modes, printer) {
  if (!modes || typeof modes !== 'object') return null

  const name = String(printer ?? '').trim()
  if (name === '') return null

  const mode = modes[name]

  return mode === 'zpl' || mode === 'graphic' ? mode : null
}

/**
 * Se vale la pena avvisare: solo quando la stampante dichiara la sua strada e
 * il layout ne usa un'altra.
 */
export function shouldWarnPrintMode(templateMode, printerMode) {
  if (!printerMode) return false

  return (templateMode === 'graphic' ? 'graphic' : 'zpl') !== printerMode
}

export function describePrintMode(mode) {
  return mode === 'graphic' ? 'stampa normale di sistema (immagine)' : 'comandi ZPL'
}
