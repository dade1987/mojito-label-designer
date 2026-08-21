import { describe, expect, it } from 'vitest'
import { resizeKeepingRatio, ratioOf } from '../aspectRatio.js'

/**
 * Ridimensionare un'immagine senza deformarla.
 *
 * Cambiando larghezza e altezza a mano, una alla volta, l'immagine si
 * schiaccia: un logo diventa ovale e nessuno se ne accorge finché non è
 * stampato.
 */
describe('proporzioni', () => {
  it('ricava le proporzioni da larghezza e altezza', () => {
    expect(ratioOf({ width: 200, height: 100 })).toBe(2)
  })

  it('un elemento senza misure non ha proporzioni da mantenere', () => {
    expect(ratioOf({ width: 0, height: 100 })).toBeNull()
    expect(ratioOf(null)).toBeNull()
  })

  it('cambiando la larghezza adegua l’altezza', () => {
    const resized = resizeKeepingRatio({ width: 200, height: 100 }, { width: 300 })

    expect(resized).toEqual({ width: 300, height: 150 })
  })

  it('cambiando l’altezza adegua la larghezza', () => {
    const resized = resizeKeepingRatio({ width: 200, height: 100 }, { height: 25 })

    expect(resized).toEqual({ width: 50, height: 25 })
  })

  it('trascinando un angolo segue il lato che si è mosso di più', () => {
    // Sull'angolo il mouse muove entrambi i lati: si segue quello con lo
    // scostamento maggiore, o l'immagine "salta" fra le due letture.
    const resized = resizeKeepingRatio({ width: 200, height: 100 }, { width: 210, height: 160 })

    expect(resized).toEqual({ width: 320, height: 160 })
  })

  it('non scende sotto la misura minima', () => {
    const resized = resizeKeepingRatio({ width: 200, height: 100 }, { width: 2 }, 10)

    expect(resized.width).toBe(10)
    expect(resized.height).toBe(5)
  })

  it('arrotonda ai punti interi, perché la stampa non ha mezzi punti', () => {
    const resized = resizeKeepingRatio({ width: 300, height: 100 }, { width: 100 })

    expect(Number.isInteger(resized.width)).toBe(true)
    expect(Number.isInteger(resized.height)).toBe(true)
  })

  it('un elemento senza proporzioni valide resta com’è', () => {
    expect(resizeKeepingRatio({ width: 0, height: 0 }, { width: 50 })).toEqual({ width: 0, height: 0 })
  })
})

/**
 * L'ingrandimento di codici a barre e QR.
 *
 * Lo ZPL accetta solo numeri interi: scrivendo 2,5 la stampante stampa 2,
 * mentre lo schermo disegnava 2,5. Il disegno prometteva una cosa e la carta
 * ne dava un'altra.
 */
describe('ingrandimento stampabile', () => {
  it('tiene solo quello che la stampante sa fare', async () => {
    const { printableMagnification } = await import('../aspectRatio.js')

    expect(printableMagnification(2.5)).toBe(2)
    expect(printableMagnification(3.9)).toBe(3)
    expect(printableMagnification(4)).toBe(4)
  })

  it('resta nell’intervallo accettato dallo ZPL', async () => {
    const { printableMagnification } = await import('../aspectRatio.js')

    expect(printableMagnification(0)).toBe(1)
    expect(printableMagnification(99)).toBe(10)
    expect(printableMagnification(undefined)).toBe(4)
  })
})
