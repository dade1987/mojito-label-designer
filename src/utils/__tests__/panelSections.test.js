import { beforeEach, describe, expect, it } from 'vitest'
import {
  PANEL_SECTIONS_KEY,
  defaultPanelSections,
  loadPanelSections,
  savePanelSections,
} from '../panelSections.js'

/**
 * Le sezioni dei pannelli si aprono e si chiudono, e la scelta resta: chi
 * lavora sempre sulle stesse cose non vuole richiudere il resto ogni volta.
 */
describe('sezioni dei pannelli', () => {
  beforeEach(() => {
    localStorage.clear()
  })

  it('di default è tutto aperto tranne gli strumenti per sviluppatori', () => {
    const sections = defaultPanelSections()

    expect(sections.layout).toBe(true)
    expect(sections.properties).toBe(true)
    expect(sections.devtools).toBe(false)
  })

  it('senza niente salvato usa i default', () => {
    expect(loadPanelSections()).toEqual(defaultPanelSections())
  })

  it('ricorda cosa è stato chiuso', () => {
    savePanelSections({ ...defaultPanelSections(), layout: false, devtools: true })

    const sections = loadPanelSections()
    expect(sections.layout).toBe(false)
    expect(sections.devtools).toBe(true)
    expect(sections.properties).toBe(true)
  })

  it('ignora valori salvati che non sono vero/falso o chiavi sconosciute', () => {
    localStorage.setItem(PANEL_SECTIONS_KEY, JSON.stringify({ layout: 'no', altro: false }))

    const sections = loadPanelSections()
    expect(sections.layout).toBe(true)
    expect(sections).not.toHaveProperty('altro')
  })

  it('riparte dai default se lo storage è corrotto o assente', () => {
    localStorage.setItem(PANEL_SECTIONS_KEY, '{non json')
    expect(loadPanelSections()).toEqual(defaultPanelSections())

    expect(loadPanelSections(null)).toEqual(defaultPanelSections())
  })

  it('salva solo le chiavi conosciute, come booleani', () => {
    const saved = savePanelSections({ layout: 0, altro: true })

    expect(saved.layout).toBe(false)
    expect(saved).not.toHaveProperty('altro')
    expect(JSON.parse(localStorage.getItem(PANEL_SECTIONS_KEY)).layout).toBe(false)
  })

  it('non esplode se lo storage rifiuta la scrittura', () => {
    const rotto = {
      setItem() {
        throw new Error('quota')
      },
    }

    expect(() => savePanelSections(defaultPanelSections(), rotto)).not.toThrow()
  })
})
