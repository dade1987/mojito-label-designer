import { describe, expect, it } from 'vitest'
import { MAX_LABELS_PER_RUN, buildSeriesJobs, normalizeSeriesSettings } from '../serialRange.js'

/**
 * Etichette piccole: il codice/QR porta il codice completo del pacco
 * (CTW10140T20S0926111), il testo visibile solo il numero (111). Prefisso e
 * campi si salvano nel layout, cosi' non si riscrivono a ogni stampa.
 */
describe('serie con codice completo e solo numero', () => {
  it('il codice completo in un campo, il solo numero nell\'altro', () => {
    const jobs = buildSeriesJobs(
      { start: 110, end: 112, prefix: 'CTW10140T20S0926' },
      { codeField: 'qr_1', numberField: 'text_1' },
    )

    expect(jobs).toEqual([
      { qr_1: 'CTW10140T20S0926110', text_1: '110' },
      { qr_1: 'CTW10140T20S0926111', text_1: '111' },
      { qr_1: 'CTW10140T20S0926112', text_1: '112' },
    ])
  })

  it('zeri e passo valgono per entrambi, prefisso e suffisso solo per il codice', () => {
    const jobs = buildSeriesJobs(
      { start: 1, end: 5, step: 2, pad: 3, prefix: 'LOT', suffix: '-A' },
      { codeField: 'barcode', numberField: 'n' },
    )

    expect(jobs).toEqual([
      { barcode: 'LOT001-A', n: '001' },
      { barcode: 'LOT003-A', n: '003' },
      { barcode: 'LOT005-A', n: '005' },
    ])
  })

  it('senza campo per il numero si comporta come prima', () => {
    expect(buildSeriesJobs({ start: 1, end: 2, prefix: 'X' }, { codeField: 'sn', numberField: '' })).toEqual([
      { sn: 'X1' },
      { sn: 'X2' },
    ])
  })

  it('prefisso e suffisso mancanti o nulli non sporcano il codice', () => {
    expect(buildSeriesJobs({ start: 1, end: 1, prefix: null, suffix: null }, { codeField: 'sn' })).toEqual([{ sn: '1' }])
    expect(buildSeriesJobs({ start: 1, end: 1 }, { codeField: 'sn', numberField: null })).toEqual([{ sn: '1' }])
    expect(() => buildSeriesJobs({ start: 1, end: 1 })).toThrow('Scegli la sorgente dati')
  })

  it('serve il campo del codice e i due campi devono essere diversi', () => {
    expect(() => buildSeriesJobs({ start: 1, end: 2 }, { codeField: ' ', numberField: 'n' })).toThrow(
      'Scegli la sorgente dati che riceve il numero di serie.',
    )
    expect(() => buildSeriesJobs({ start: 1, end: 2 }, { codeField: 'sn', numberField: 'sn' })).toThrow(
      'Il codice completo e il solo numero vanno in due campi diversi.',
    )
  })

  it('rispetta il limite di etichette per stampa', () => {
    expect(() => buildSeriesJobs({ start: 1, end: MAX_LABELS_PER_RUN + 1 }, { codeField: 'sn' })).toThrow(/Troppe etichette/)
  })

  it('le impostazioni salvate nel layout tornano sempre complete e pulite', () => {
    expect(normalizeSeriesSettings()).toEqual({ codeField: '', numberField: '', prefix: '', suffix: '', pad: 0, step: 1 })
    expect(normalizeSeriesSettings(null)).toEqual(normalizeSeriesSettings())
    expect(normalizeSeriesSettings('rotto')).toEqual(normalizeSeriesSettings())

    expect(
      normalizeSeriesSettings({ codeField: 'qr_1', numberField: 'text_1', prefix: 'CTW10140T20S0926', suffix: 'B', pad: '3', step: 2, extra: 'via' }),
    ).toEqual({ codeField: 'qr_1', numberField: 'text_1', prefix: 'CTW10140T20S0926', suffix: 'B', pad: 3, step: 2 })

    // valori assurdi tornano a valori ammessi
    expect(normalizeSeriesSettings({ pad: -4, step: 0 })).toMatchObject({ pad: 0, step: 1 })
    expect(normalizeSeriesSettings({ pad: 40, step: 2.5 })).toMatchObject({ pad: 12, step: 1 })
    expect(normalizeSeriesSettings({ pad: 'x', codeField: 7, prefix: null })).toMatchObject({ pad: 0, codeField: '', prefix: '' })
    // null e testo vuoto non sono zero: si torna ai valori di partenza
    expect(normalizeSeriesSettings({ pad: null, step: '' })).toMatchObject({ pad: 0, step: 1 })
    expect(normalizeSeriesSettings({ pad: '', step: null })).toMatchObject({ pad: 0, step: 1 })
  })
})
