import { describe, expect, it } from 'vitest'
import { thresholdPixels, DEFAULT_THRESHOLD } from '../monochrome.js'

/**
 * L'immagine come la stamperà la stampante.
 *
 * La stampante è termica: fa punti neri o niente, i grigi e i colori non
 * esistono. A schermo però l'immagine si vedeva a colori, quindi il disegno
 * mostrava qualcosa che la carta non poteva dare.
 */
describe('conversione in bianco e nero', () => {
  const pixel = (r, g, b, a = 255) => Uint8ClampedArray.from([r, g, b, a])

  it('un colore chiaro diventa bianco', () => {
    const out = thresholdPixels(pixel(240, 240, 240), DEFAULT_THRESHOLD)

    expect([out[0], out[1], out[2]]).toEqual([255, 255, 255])
  })

  it('un colore scuro diventa nero', () => {
    const out = thresholdPixels(pixel(20, 20, 20), DEFAULT_THRESHOLD)

    expect([out[0], out[1], out[2]]).toEqual([0, 0, 0])
  })

  it('pesa i canali come li pesa l’occhio', () => {
    // Il verde pesa più del blu: un verde acceso è chiaro, un blu dello
    // stesso valore è scuro. È la stessa formula che usa la stampa.
    // Stesso valore su canali diversi: il verde pieno supera la soglia, il
    // blu pieno no.
    const verde = thresholdPixels(pixel(0, 255, 0), DEFAULT_THRESHOLD)
    const blu = thresholdPixels(pixel(0, 0, 255), DEFAULT_THRESHOLD)

    expect(verde[0]).toBe(255)
    expect(blu[0]).toBe(0)
  })

  it('alzando la soglia diventa più nero', () => {
    const grigio = pixel(150, 150, 150)

    expect(thresholdPixels(grigio, 100)[0]).toBe(255)
    expect(thresholdPixels(grigio, 200)[0]).toBe(0)
  })

  it('il trasparente resta trasparente, non diventa un rettangolo nero', () => {
    const out = thresholdPixels(pixel(10, 10, 10, 0), DEFAULT_THRESHOLD)

    expect(out[3]).toBe(0)
  })

  it('converte immagini intere, pixel per pixel', () => {
    const due = Uint8ClampedArray.from([250, 250, 250, 255, 10, 10, 10, 255])

    const out = thresholdPixels(due, DEFAULT_THRESHOLD)

    expect(out[0]).toBe(255)
    expect(out[4]).toBe(0)
  })
})
