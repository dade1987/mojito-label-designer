import { afterEach, describe, expect, it } from 'vitest'
import {
  DEFAULT_PX_PER_MM,
  getScreenPxPerMm,
  resetScreenCalibration,
  setScreenPxPerMm,
} from '../screenCalibration.js'

describe('screenCalibration', () => {
  afterEach(() => {
    localStorage.clear()
  })

  it('default = riferimento CSS (96/25.4 px/mm)', () => {
    expect(DEFAULT_PX_PER_MM).toBeCloseTo(3.7795, 4)
    expect(getScreenPxPerMm()).toBeCloseTo(96 / 25.4, 6)
  })

  it('salva e rilegge un valore valido', () => {
    expect(setScreenPxPerMm(5.2)).toBe(true)
    expect(getScreenPxPerMm()).toBe(5.2)
  })

  it('rifiuta valori non validi e resta al default', () => {
    expect(setScreenPxPerMm(0)).toBe(false)
    expect(setScreenPxPerMm(-3)).toBe(false)
    expect(setScreenPxPerMm('x')).toBe(false)
    expect(getScreenPxPerMm()).toBeCloseTo(DEFAULT_PX_PER_MM, 6)
  })

  it('reset torna al default', () => {
    setScreenPxPerMm(7)
    resetScreenCalibration()
    expect(getScreenPxPerMm()).toBeCloseTo(DEFAULT_PX_PER_MM, 6)
  })

  it('valore corrotto in storage → default', () => {
    localStorage.setItem('mojito-screen-pxpermm', 'boh')
    expect(getScreenPxPerMm()).toBeCloseTo(DEFAULT_PX_PER_MM, 6)
  })
})
