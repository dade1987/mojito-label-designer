/**
 * Ridimensionare mantenendo le proporzioni.
 *
 * Cambiando larghezza e altezza a mano, una alla volta, l'immagine si
 * schiaccia: un logo diventa ovale, e sullo schermo piccolo non si nota
 * finché non è stampato.
 */

export function ratioOf(element) {
  const width = Number(element?.width)
  const height = Number(element?.height)

  if (!Number.isFinite(width) || !Number.isFinite(height)) return null
  if (width <= 0 || height <= 0) return null

  return width / height
}

/**
 * Le nuove misure, a proporzioni bloccate.
 *
 * Quando arrivano entrambe — è il caso del trascinamento su un angolo, dove
 * il mouse muove i due lati insieme — si segue quella che si è mossa di più:
 * inseguirle tutte e due farebbe saltare l'immagine fra le due letture.
 */
export function resizeKeepingRatio(element, changes, minimum = 10) {
  const ratio = ratioOf(element)

  if (ratio === null) {
    return { width: Number(element?.width) || 0, height: Number(element?.height) || 0 }
  }

  const nextWidth = Number(changes?.width)
  const nextHeight = Number(changes?.height)
  const hasWidth = Number.isFinite(nextWidth)
  const hasHeight = Number.isFinite(nextHeight)

  let width
  if (hasWidth && hasHeight) {
    const widthShift = Math.abs(nextWidth - element.width)
    const heightShift = Math.abs(nextHeight - element.height)
    width = widthShift >= heightShift ? nextWidth : nextHeight * ratio
  } else if (hasWidth) {
    width = nextWidth
  } else if (hasHeight) {
    width = nextHeight * ratio
  } else {
    width = element.width
  }

  width = Math.max(minimum, width)
  const height = Math.max(minimum / ratio, width / ratio)

  // La stampa lavora a punti interi: mezzi punti non esistono sulla carta.
  return { width: Math.round(width), height: Math.round(height) }
}

/**
 * L'ingrandimento che la stampante applichera' davvero.
 *
 * Lo ZPL accetta solo numeri interi da 1 a 10: scrivendo 2,5 la stampante
 * stampa 2, mentre lo schermo disegnava 2,5. Passando da qui, disegno e
 * stampa dicono la stessa cosa.
 */
export function printableMagnification(value) {
  const magnification = Number(value)

  if (!Number.isFinite(magnification)) return 4

  return Math.max(1, Math.min(10, Math.floor(magnification)))
}
