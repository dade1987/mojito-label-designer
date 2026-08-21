import { describe, expect, it } from 'vitest'
import { startNewLayout, hasWork } from '../newLayout.js'

/**
 * Iniziare un layout da zero.
 *
 * Il pannello aveva "salva", "apri" e "carica", ma non un modo per
 * ricominciare: chi voleva un'etichetta nuova doveva ricaricare la pagina o
 * cancellare a mano gli elementi di quella precedente.
 */
describe('nuovo layout', () => {
  it('parte da un foglio pulito', () => {
    const nuovo = startNewLayout()

    expect(nuovo.elements).toEqual([])
    expect(nuovo.name).toBeTruthy()
    expect(nuovo.labelWidth).toBeGreaterThan(0)
    expect(nuovo.labelHeight).toBeGreaterThan(0)
  })

  it('conserva formato e risoluzione di quello che si stava usando', () => {
    // Chi disegna etichette lavora quasi sempre sullo stesso formato: farglielo
    // reimpostare a ogni layout nuovo è tempo perso.
    const corrente = { labelWidth: 812, labelHeight: 406, dpi: 300, elements: [{ id: 'a' }] }

    const nuovo = startNewLayout(corrente)

    expect(nuovo.labelWidth).toBe(812)
    expect(nuovo.labelHeight).toBe(406)
    expect(nuovo.dpi).toBe(300)
    expect(nuovo.elements).toEqual([])
  })

  it('senza un layout di partenza usa le misure predefinite', () => {
    const nuovo = startNewLayout(null)

    expect(nuovo.dpi).toBe(203)
  })

  it('non porta con sé il nome del layout precedente', () => {
    const nuovo = startNewLayout({ name: 'Etichetta pacco 48V', elements: [] })

    expect(nuovo.name).not.toBe('Etichetta pacco 48V')
  })

  it('riconosce se c’è del lavoro da perdere', () => {
    // Serve a decidere se chiedere conferma: su un foglio già vuoto la
    // domanda sarebbe solo un clic in più.
    expect(hasWork({ elements: [{ id: 'a' }] })).toBe(true)
    expect(hasWork({ elements: [] })).toBe(false)
    expect(hasWork(null)).toBe(false)
  })
})
