import { describe, expect, it } from 'vitest'
import { zplRotationDegrees, zplRotationTransform } from '../zplRotation.js'

/**
 * La rotazione in anteprima deve essere quella che la stampante fara' davvero:
 * riquadro ancorato in alto a sinistra su ^FO, angoli non stampabili dritti.
 */
describe('zplRotationDegrees', () => {
  it('accetta solo i quattro orientamenti ZPL', () => {
    expect(zplRotationDegrees(0)).toBe(0)
    expect(zplRotationDegrees(90)).toBe(90)
    expect(zplRotationDegrees(180)).toBe(180)
    expect(zplRotationDegrees(270)).toBe(270)
  })

  it('normalizza i giri completi come il server', () => {
    expect(zplRotationDegrees(360)).toBe(0)
    expect(zplRotationDegrees(450)).toBe(90)
  })

  it('tutto il resto torna dritto, come stampera la stampante', () => {
    expect(zplRotationDegrees(45)).toBe(0)
    expect(zplRotationDegrees(-90)).toBe(0)
    expect(zplRotationDegrees(null)).toBe(0)
    expect(zplRotationDegrees(undefined)).toBe(0)
    expect(zplRotationDegrees('abc')).toBe(0)
  })
})

describe('zplRotationTransform', () => {
  it('non trasforma gli elementi dritti', () => {
    expect(zplRotationTransform(0)).toBe('')
    expect(zplRotationTransform(45)).toBe('')
  })

  it('compensa la rotazione per tenere fermo l\'angolo in alto a sinistra', () => {
    // rotate() da solo manda il contenuto sopra o a sinistra dell'origine;
    // la translate lo riporta col riquadro ancorato come fa lo ZPL.
    expect(zplRotationTransform(90)).toBe('rotate(90deg) translateY(-100%)')
    expect(zplRotationTransform(180)).toBe('rotate(180deg) translate(-100%, -100%)')
    expect(zplRotationTransform(270)).toBe('rotate(270deg) translateX(-100%)')
  })
})
