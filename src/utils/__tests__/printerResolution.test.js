import { describe, expect, it } from 'vitest'
import { resolutionForPrinter, shouldWarnResolution } from '../printerResolution.js'

/**
 * La risoluzione da usare per il disegno.
 *
 * Disegnare a 203 punti per pollice un'etichetta che verrà stampata a 300
 * significa mandarla in stampa di misura sbagliata: giusta sullo schermo,
 * storta sulla carta. Se il server sa a quanto stampa quella stampante, non
 * c'è motivo di far indovinare.
 */
describe('risoluzione della stampante', () => {
  const mappa = { Citizen_CL_S703Z: 300, Zebra_ZT230: 203 }

  it('trova la risoluzione della stampante scelta', () => {
    expect(resolutionForPrinter(mappa, 'Citizen_CL_S703Z')).toBe(300)
  })

  it('non inventa niente per una stampante che il server non conosce', () => {
    expect(resolutionForPrinter(mappa, 'Stampante Ufficio')).toBeNull()
    expect(resolutionForPrinter(mappa, '')).toBeNull()
    expect(resolutionForPrinter(null, 'Citizen_CL_S703Z')).toBeNull()
  })

  it('avvisa quando il disegno è a una risoluzione diversa dalla stampante', () => {
    // È il caso che rovina le etichette senza dare segnali: sullo schermo
    // sembra tutto a posto.
    expect(shouldWarnResolution(203, 300)).toBe(true)
  })

  it('non avvisa quando coincidono', () => {
    expect(shouldWarnResolution(300, 300)).toBe(false)
  })

  it('non avvisa se la stampante non dichiara la sua risoluzione', () => {
    expect(shouldWarnResolution(203, null)).toBe(false)
  })
})
