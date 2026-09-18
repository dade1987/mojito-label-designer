import { describe, expect, it, vi } from 'vitest'
import {
  destinationKind,
  groupPrinters,
  loadSavedNetworkPrinters,
  mergeSavedNetworkPrinters,
  parseNetworkPrinter,
  pickDefaultPrinter,
  printerLabel,
  printOutcomeMessage,
  saveNetworkPrinter,
  zplOnlyModes,
} from '../printerDestinations.js'

function memoryStorage(initial = {}) {
  const data = { ...initial }
  return {
    getItem: (k) => (k in data ? data[k] : null),
    setItem: (k, v) => { data[k] = String(v) },
    data,
  }
}

describe('destinationKind', () => {
  it('distingue server, rete e PC dal valore', () => {
    expect(destinationKind('Citizen_CL_S703Z')).toBe('server')
    expect(destinationKind('ip:192.168.1.50:9100')).toBe('network')
    expect(destinationKind('pc:SURFACE9|Citizen CL-S703')).toBe('pc')
    expect(destinationKind('')).toBe('server')
    expect(destinationKind(undefined)).toBe('server')
  })
})

describe('printerLabel', () => {
  it('usa il nome dato dal server quando c\'e\'', () => {
    expect(printerLabel('pc:SURFACE9|Citizen', { 'pc:SURFACE9|Citizen': 'SURFACE9 · Citizen (agente non in linea)' }))
      .toBe('SURFACE9 · Citizen (agente non in linea)')
  })

  it('altrimenti ricostruisce un nome leggibile', () => {
    expect(printerLabel('ip:192.168.1.50:9100')).toBe('Rete · 192.168.1.50:9100')
    expect(printerLabel('pc:SURFACE9|Citizen CL-S703')).toBe('SURFACE9 · Citizen CL-S703')
    expect(printerLabel('pc:SURFACE9')).toBe('SURFACE9')
    expect(printerLabel('Citizen_CL_S703Z')).toBe('Citizen_CL_S703Z')
    expect(printerLabel('Citizen_CL_S703Z', null)).toBe('Citizen_CL_S703Z')
  })
})

describe('groupPrinters', () => {
  it('divide le stampanti per dove si trovano, saltando i gruppi vuoti', () => {
    const groups = groupPrinters(
      ['Citizen_CL_S703Z', 'pc:SURFACE9|Citizen', 'ip:10.0.0.5:9100', 'Brother'],
      { 'pc:SURFACE9|Citizen': 'SURFACE9 · Citizen' },
    )

    expect(groups).toEqual([
      {
        title: 'Stampanti del server',
        items: [
          { value: 'Citizen_CL_S703Z', label: 'Citizen_CL_S703Z' },
          { value: 'Brother', label: 'Brother' },
        ],
      },
      { title: 'Stampanti di rete', items: [{ value: 'ip:10.0.0.5:9100', label: 'Rete · 10.0.0.5:9100' }] },
      { title: 'Stampanti dei PC (agente di stampa)', items: [{ value: 'pc:SURFACE9|Citizen', label: 'SURFACE9 · Citizen' }] },
    ])
    expect(groupPrinters(['Citizen'])).toHaveLength(1)
    expect(groupPrinters([])).toEqual([])
    expect(groupPrinters(null)).toEqual([])
  })
})

describe('pickDefaultPrinter', () => {
  /**
   * "SURFACE9 · Citizen" contiene "citizen" anche lei: la scelta di serie deve
   * restare fra le stampanti del server, o il designer manderebbe tutto sul
   * PC di reparto senza che nessuno l'abbia chiesto.
   */
  it('di serie sceglie la Citizen del server, non quella di un PC', () => {
    expect(pickDefaultPrinter(['pc:SURFACE9|Citizen CL-S703', 'Brother', 'Citizen_CL_S703Z'])).toBe('Citizen_CL_S703Z')
    expect(pickDefaultPrinter(['pc:SURFACE9|Citizen', 'Brother'])).toBe('Brother')
  })

  it('senza stampanti del server ripiega sulla prima disponibile', () => {
    expect(pickDefaultPrinter(['ip:10.0.0.5:9100', 'pc:SURFACE9|Citizen'])).toBe('ip:10.0.0.5:9100')
    expect(pickDefaultPrinter([])).toBe('')
    expect(pickDefaultPrinter(undefined)).toBe('')
  })
})

describe('parseNetworkPrinter', () => {
  it('accetta un indirizzo, con o senza porta', () => {
    expect(parseNetworkPrinter(' 192.168.1.50 ')).toBe('ip:192.168.1.50:9100')
    expect(parseNetworkPrinter('192.168.1.50:6101')).toBe('ip:192.168.1.50:6101')
  })

  it('rifiuta quello che non e\' un indirizzo di stampante', () => {
    expect(() => parseNetworkPrinter('')).toThrow('Scrivi l\'indirizzo IP')
    expect(() => parseNetworkPrinter(null)).toThrow('Scrivi l\'indirizzo IP')
    expect(() => parseNetworkPrinter('stampante.local')).toThrow('non e\' un indirizzo IP')
    expect(() => parseNetworkPrinter('192.168.1.300')).toThrow('non e\' un indirizzo IP')
    expect(() => parseNetworkPrinter('192.168.1')).toThrow('non e\' un indirizzo IP')
    expect(() => parseNetworkPrinter('192.168.1.50:0')).toThrow('porta')
    expect(() => parseNetworkPrinter('192.168.1.50:70000')).toThrow('porta')
    expect(() => parseNetworkPrinter('192.168.1.50:abc')).toThrow('porta')
  })
})

describe('stampanti di rete ricordate dal browser', () => {
  it('salva e rilegge le stampanti aggiunte, senza doppioni', () => {
    const storage = memoryStorage()

    saveNetworkPrinter('ip:10.0.0.5:9100', storage)
    saveNetworkPrinter('ip:10.0.0.6:9100', storage)
    saveNetworkPrinter('ip:10.0.0.5:9100', storage)

    expect(loadSavedNetworkPrinters(storage)).toEqual(['ip:10.0.0.5:9100', 'ip:10.0.0.6:9100'])
  })

  it('ignora quello che non e\' una stampante di rete e i dati rovinati', () => {
    expect(loadSavedNetworkPrinters(memoryStorage({ 'mojito.networkPrinters': '["ip:10.0.0.5:9100","Brother",5]' })))
      .toEqual(['ip:10.0.0.5:9100'])
    expect(loadSavedNetworkPrinters(memoryStorage({ 'mojito.networkPrinters': 'non json' }))).toEqual([])
    expect(loadSavedNetworkPrinters(memoryStorage({ 'mojito.networkPrinters': '{"a":1}' }))).toEqual([])
    expect(loadSavedNetworkPrinters(memoryStorage())).toEqual([])
  })

  it('senza memoria del browser (finestra privata) non si rompe', () => {
    const broken = {
      getItem: () => { throw new Error('bloccato') },
      setItem: () => { throw new Error('bloccato') },
    }

    expect(loadSavedNetworkPrinters(broken)).toEqual([])
    expect(() => saveNetworkPrinter('ip:10.0.0.5:9100', broken)).not.toThrow()
    expect(loadSavedNetworkPrinters(null)).toEqual([])
    expect(() => saveNetworkPrinter('ip:10.0.0.5:9100', null)).not.toThrow()
  })

  it('di serie usa la memoria del browser', () => {
    saveNetworkPrinter('ip:10.0.0.7:9100')

    expect(Array.isArray(loadSavedNetworkPrinters())).toBe(true)
  })

  it('senza memoria del browser del tutto non si rompe', () => {
    vi.stubGlobal('localStorage', undefined)
    try {
      expect(loadSavedNetworkPrinters()).toEqual([])
      expect(() => saveNetworkPrinter('ip:10.0.0.8:9100')).not.toThrow()
    } finally {
      vi.unstubAllGlobals()
    }
  })

  it('le aggiunge all\'elenco del server, se non ci sono gia\'', () => {
    expect(mergeSavedNetworkPrinters(['Citizen', 'ip:10.0.0.5:9100'], ['ip:10.0.0.5:9100', 'ip:10.0.0.6:9100']))
      .toEqual(['Citizen', 'ip:10.0.0.5:9100', 'ip:10.0.0.6:9100'])
    expect(mergeSavedNetworkPrinters(null, ['ip:10.0.0.6:9100'])).toEqual(['ip:10.0.0.6:9100'])
  })
})

describe('zplOnlyModes', () => {
  it('rete e PC ricevono solo ZPL', () => {
    expect(zplOnlyModes(['Citizen', 'ip:10.0.0.5:9100', 'pc:SURFACE9|Citizen'])).toEqual({
      'ip:10.0.0.5:9100': 'zpl',
      'pc:SURFACE9|Citizen': 'zpl',
    })
  })
})

describe('printOutcomeMessage', () => {
  it('dice se l\'etichetta e\' stampata o in coda per un PC', () => {
    expect(printOutcomeMessage({ status: 'printed' }, 'Citizen', 'Etichetta'))
      .toBe('Etichetta inviata a Citizen')
    expect(printOutcomeMessage({ status: 'queued' }, 'SURFACE9 · Citizen', 'Serie'))
      .toBe('Serie in coda per SURFACE9 · Citizen: la stampa l\'agente di quel PC')
    expect(printOutcomeMessage(undefined, 'Citizen', 'Etichetta')).toBe('Etichetta inviata a Citizen')
  })
})
