/**
 * L'immagine come la stamperà la stampante.
 *
 * La stampante è termica: fa punti neri oppure niente. I grigi e i colori
 * non esistono sulla carta, ma a schermo l'immagine si vedeva a colori — il
 * disegno mostrava qualcosa che la stampa non poteva dare, e ci si accorgeva
 * dell'errore solo con l'etichetta in mano.
 */

export const DEFAULT_THRESHOLD = 128

/**
 * Porta ogni pixel a bianco o nero, con la stessa formula che usa la
 * conversione di stampa: i canali pesano come li pesa l'occhio, quindi un
 * verde acceso è chiaro e un blu dello stesso valore è scuro.
 *
 * Il trasparente resta trasparente: convertirlo darebbe un rettangolo nero
 * al posto di un logo ritagliato.
 */
export function thresholdPixels(pixels, threshold = DEFAULT_THRESHOLD) {
  const out = new Uint8ClampedArray(pixels.length)

  for (let i = 0; i < pixels.length; i += 4) {
    const alpha = pixels[i + 3]
    const luminance = 0.299 * pixels[i] + 0.587 * pixels[i + 1] + 0.114 * pixels[i + 2]
    const value = luminance < threshold ? 0 : 255

    out[i] = value
    out[i + 1] = value
    out[i + 2] = value
    out[i + 3] = alpha
  }

  return out
}
