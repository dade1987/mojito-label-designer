import { describe, expect, it } from 'vitest'
import { ZOOM_MAX, ZOOM_MIN, clampZoom } from '../zoomRange.js'

/**
 * Quanto si può ingrandire il foglio.
 *
 * Il tetto era 600%: su un'etichetta piccola non basta a lavorare sui
 * dettagli — un codice a barre fitto o un testo di pochi millimetri restano
 * illeggibili proprio quando li si sta sistemando.
 */
describe('intervallo di zoom', () => {
  it('arriva oltre il 600% di prima', () => {
    expect(ZOOM_MAX).toBeGreaterThan(6)
  })

  it('non scende sotto il minimo utile', () => {
    expect(clampZoom(0.01)).toBe(ZOOM_MIN)
  })

  it('non supera il massimo', () => {
    expect(clampZoom(999)).toBe(ZOOM_MAX)
  })

  it('lascia passare i valori dentro l’intervallo', () => {
    expect(clampZoom(3)).toBe(3)
    expect(clampZoom(12)).toBe(12)
  })

  it('un valore non numerico riporta alla dimensione reale', () => {
    // Meglio tornare a 1:1 che restare con il foglio a una scala assurda.
    expect(clampZoom(Number.NaN)).toBe(1)
    expect(clampZoom(undefined)).toBe(1)
  })
})
