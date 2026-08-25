import { describe, expect, it } from 'vitest'
import {
  MAX_LABELS_PER_RUN,
  buildPrintJobs,
  buildSerialRange,
  describeRun,
  serialRangeCount,
} from '../serialRange.js'

describe('serialRange', () => {
  it('numera da un estremo all\'altro, estremi compresi', () => {
    expect(buildSerialRange({ start: 1, end: 5 })).toEqual(['1', '2', '3', '4', '5'])
  })

  it('un solo numero è una serie di uno', () => {
    expect(buildSerialRange({ start: 7, end: 7 })).toEqual(['7'])
  })

  it('riempie di zeri alla lunghezza chiesta', () => {
    expect(buildSerialRange({ start: 8, end: 11, pad: 4 })).toEqual(['0008', '0009', '0010', '0011'])
  })

  it('non taglia i numeri più lunghi del riempimento', () => {
    expect(buildSerialRange({ start: 998, end: 1001, pad: 3 })).toEqual(['998', '999', '1000', '1001'])
  })

  it('mette prefisso e suffisso attorno al numero', () => {
    expect(buildSerialRange({ prefix: 'CHL1225', start: 1, end: 3 })).toEqual([
      'CHL12251',
      'CHL12252',
      'CHL12253',
    ])
    expect(buildSerialRange({ start: 1, end: 2, suffix: '-A' })).toEqual(['1-A', '2-A'])
  })

  it('salta di passo quando il passo è maggiore di uno', () => {
    expect(buildSerialRange({ start: 1, end: 10, step: 3 })).toEqual(['1', '4', '7', '10'])
  })

  it('rifiuta un intervallo al contrario', () => {
    expect(() => buildSerialRange({ start: 10, end: 1 })).toThrow(/maggiore o uguale/i)
  })

  it('rifiuta numeri non validi', () => {
    expect(() => buildSerialRange({ start: 'a', end: 3 })).toThrow(/numero/i)
    expect(() => buildSerialRange({ start: 1, end: null })).toThrow(/numero/i)
    expect(() => buildSerialRange({ start: -1, end: 3 })).toThrow(/negativ/i)
  })

  it('rifiuta un passo non positivo', () => {
    expect(() => buildSerialRange({ start: 1, end: 3, step: 0 })).toThrow(/passo/i)
  })

  it('rifiuta una serie più lunga del massimo consentito', () => {
    expect(() => buildSerialRange({ start: 1, end: MAX_LABELS_PER_RUN + 1 })).toThrow(
      new RegExp(String(MAX_LABELS_PER_RUN)),
    )
  })

  it('conta le etichette senza costruirle', () => {
    expect(serialRangeCount({ start: 1, end: 96 })).toBe(96)
    expect(serialRangeCount({ start: 1, end: 10, step: 3 })).toBe(4)
    expect(serialRangeCount({ start: 5, end: 1 })).toBe(0)
    expect(serialRangeCount({ start: 'x', end: 1 })).toBe(0)
  })

  it('trasforma la serie nei valori da mandare al server', () => {
    expect(buildPrintJobs(['A1', 'A2'], 'serial')).toEqual([{ serial: 'A1' }, { serial: 'A2' }])
  })

  it('senza sorgente dati non si può costruire una serie', () => {
    expect(() => buildPrintJobs(['A1'], '')).toThrow(/sorgente dati/i)
  })

  it('riassume in italiano quante stampe partiranno', () => {
    expect(describeRun(96, 1)).toBe('96 etichette')
    expect(describeRun(1, 1)).toBe('1 etichetta')
    expect(describeRun(96, 2)).toBe('96 etichette × 2 copie = 192 stampe')
    expect(describeRun(0, 3)).toBe('nessuna etichetta')
  })

  it('i campi svuotati nel modulo valgono come i valori di partenza', () => {
    // Nei campi numerici del designer "vuoto" arriva come null.
    expect(buildSerialRange({ start: 1, end: 3, step: null, pad: null })).toEqual(['1', '2', '3'])
  })

  it('senza sorgente dati indicata affatto non si costruisce niente', () => {
    expect(() => buildPrintJobs(['A1'])).toThrow(/sorgente dati/i)
  })
})
