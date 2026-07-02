import { resolveElementValue } from './templateStore.js'

/**
 * Il font 0 ZPL è CG Triumvirate Bold Condensed: nel browser lo approssimiamo
 * con un sans-serif condensed in grassetto, così le larghezze in anteprima
 * restano vicine a quelle stampate.
 */
export const ZPL_FONT_FAMILY =
  "'Arial Narrow', 'Liberation Sans Narrow', 'Roboto Condensed', 'Helvetica Neue', Arial, sans-serif"

const CODE128_START_B = 104
const CODE128_STOP = 106

/**
 * Larghezze barra/spazio Code 128 (indice = code value). Ogni simbolo è largo
 * 11 moduli, lo stop 13: la somma delle cifre di ogni entry lo garantisce.
 */
export const CODE128_WIDTHS = [
  '212222', '222122', '222221', '121223', '121322', '131222', '122213',
  '122312', '132212', '221213', '221312', '231212', '112232', '122132',
  '122231', '113222', '123122', '123221', '223211', '221132', '221231',
  '213212', '223112', '312131', '311222', '321122', '321221', '312212',
  '322112', '322211', '212123', '212321', '232121', '111323', '131123',
  '131321', '112313', '132113', '132311', '211313', '231113', '231311',
  '112133', '112331', '132131', '113123', '113321', '133121', '313121',
  '211331', '231131', '213113', '213311', '213131', '311123', '311321',
  '331121', '312113', '312311', '332111', '314111', '221411', '431111',
  '111224', '111422', '121124', '121421', '141122', '141221', '112214',
  '112412', '122114', '122411', '142112', '142211', '241211', '221114',
  '413111', '241112', '134111', '111242', '121142', '121241', '114212',
  '124112', '124211', '411212', '421112', '421211', '212141', '214121',
  '412121', '111143', '111341', '131141', '114113', '114311', '411113',
  '411311', '113141', '114131', '311141', '411131', '211412', '211214',
  '211232', '2331112',
]

export function computeScale(labelWidth, maxWidth = 700) {
  const width = labelWidth || 600
  return Math.min(1, maxWidth / width)
}

export function buildElementDisplayValues(elements, dataValues, dataSources) {
  const values = {}

  for (const element of elements ?? []) {
    values[element.id] = resolveElementValue(element, dataValues, dataSources ?? [])
  }

  return values
}

export function formatDisplayText(element, displayValues) {
  const value = displayValues[element.id] ?? ''
  return `${element.prefix ?? ''}${value}${element.suffix ?? ''}`
}

export function formatBarcodeValue(element, displayValues) {
  return displayValues[element.id] || '(barcode)'
}

/**
 * Stile testo fedele allo ZPL: ^A0N,h,w disegna un carattere alto h dots,
 * quindi il font-size CSS è h × scala. La larghezza w si ottiene con scaleX.
 */
export function computeTextStyle(element, scale = 1) {
  const fontHeight = element.fontHeight ?? 30
  const fontWidth = element.fontWidth ?? fontHeight
  const stretch = fontHeight > 0 && fontWidth > 0 ? fontWidth / fontHeight : 1

  const style = {
    fontSize: `${fontHeight * scale}px`,
    lineHeight: '1',
    fontFamily: ZPL_FONT_FAMILY,
    fontWeight: '700',
    fontStretch: 'condensed',
    textDecoration: element.underline ? 'underline' : 'none',
  }

  if (element.bold) {
    // Il builder ZPL simula il grassetto ristampando il testo spostato di 1 dot.
    style.textShadow = `${scale}px 0 0 currentColor`
  }

  if (stretch !== 1) {
    style.transform = `scaleX(${stretch})`
    style.transformOrigin = 'left top'
  }

  return style
}

/**
 * Codifica Code 128 subset B, come fa la stampante con ^BC in modalità N
 * (nessuna ottimizzazione automatica dei subset).
 */
export function encodeCode128(value) {
  const codes = [CODE128_START_B]

  for (const char of String(value)) {
    const code = char.charCodeAt(0) - 32
    codes.push(code >= 0 && code < 96 ? code : 0)
  }

  let checksum = codes[0]
  for (let index = 1; index < codes.length; index += 1) {
    checksum += codes[index] * index
  }

  codes.push(checksum % 103)
  codes.push(CODE128_STOP)

  return codes
}

export function code128Bars(value) {
  const bars = []
  let x = 0

  for (const code of encodeCode128(value)) {
    const widths = CODE128_WIDTHS[code]

    for (let index = 0; index < widths.length; index += 1) {
      const width = Number(widths[index])

      if (index % 2 === 0) {
        bars.push({ x, width })
      }

      x += width
    }
  }

  return { bars, totalModules: x }
}

const CODE39_WIDE_RATIO = 3

/**
 * Code 39 con ratio 3:1 (default ^BY): larghezza esatta — 9 elementi di cui
 * 3 larghi per carattere più un modulo di gap — ma disposizione degli elementi
 * larghi indicativa, non scansionabile.
 */
export function code39Bars(value) {
  const chars = `*${String(value)}*`
  const bars = []
  let x = 0

  for (let position = 0; position < chars.length; position += 1) {
    if (position > 0) {
      x += 1
    }

    const wideAt = chars.charCodeAt(position) % 9

    for (let index = 0; index < 9; index += 1) {
      const wide =
        index === wideAt || index === (wideAt + 3) % 9 || index === (wideAt + 6) % 9
      const width = wide ? CODE39_WIDE_RATIO : 1

      if (index % 2 === 0) {
        bars.push({ x, width })
      }

      x += width
    }
  }

  return { bars, totalModules: x }
}

export function computeBarcodeMetrics(element, displayValues) {
  const value = String(displayValues[element.id] || '123456')
  const moduleWidth = element.moduleWidth ?? 2
  const showText = element.showText !== false
  const encoded =
    (element.barcodeType ?? 'code128') === 'code39'
      ? code39Bars(value)
      : code128Bars(value)

  const barsHeightDots = element.height ?? 100
  // La riga di interpretazione ZPL scala con il modulo (~10 dots per modulo).
  const textFontDots = Math.max(14, 10 * moduleWidth)
  const textGapDots = Math.round(textFontDots / 4)

  return {
    value,
    bars: encoded.bars,
    totalModules: encoded.totalModules,
    widthDots: encoded.totalModules * moduleWidth,
    barsHeightDots,
    showText,
    textFontDots,
    textGapDots,
    totalHeightDots: barsHeightDots + (showText ? textGapDots + textFontDots : 0),
  }
}

export function computeBarcodeStyle(element, displayValues, scale) {
  const metrics = computeBarcodeMetrics(element, displayValues)

  return {
    width: `${metrics.widthDots * scale}px`,
    height: `${metrics.totalHeightDots * scale}px`,
  }
}
