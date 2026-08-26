import { describe, expect, it } from 'vitest'
import { describePrintMode, printModeForPrinter, shouldWarnPrintMode } from '../printerPrintMode.js'

/**
 * La strada di stampa da usare: ZPL per chi lo parla, etichetta disegnata per
 * chi no. Se il server conosce la stampante, il layout si mette da solo sulla
 * strada giusta.
 */
describe('strada di stampa della stampante', () => {
  const mappa = { Citizen_CL_S703Z: 'zpl', 'Munbyn ITPP941P': 'graphic', Strana: 'pdf' }

  it('trova la strada della stampante scelta', () => {
    expect(printModeForPrinter(mappa, 'Citizen_CL_S703Z')).toBe('zpl')
    expect(printModeForPrinter(mappa, 'Munbyn ITPP941P')).toBe('graphic')
  })

  it('non inventa niente per una stampante che il server non conosce', () => {
    expect(printModeForPrinter(mappa, 'Stampante Ufficio')).toBeNull()
    expect(printModeForPrinter(mappa, '')).toBeNull()
    expect(printModeForPrinter(null, 'Citizen_CL_S703Z')).toBeNull()
  })

  it('ignora una strada che non esiste', () => {
    expect(printModeForPrinter(mappa, 'Strana')).toBeNull()
  })

  it('avvisa quando il layout è su una strada diversa dalla stampante', () => {
    expect(shouldWarnPrintMode('zpl', 'graphic')).toBe(true)
    expect(shouldWarnPrintMode(undefined, 'graphic')).toBe(true)
  })

  it('non avvisa quando coincidono o la stampante non si dichiara', () => {
    expect(shouldWarnPrintMode('graphic', 'graphic')).toBe(false)
    expect(shouldWarnPrintMode('zpl', null)).toBe(false)
  })

  it('descrive la strada a parole', () => {
    expect(describePrintMode('graphic')).toContain('immagine')
    expect(describePrintMode('zpl')).toContain('ZPL')
  })
})
