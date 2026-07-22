/**
 * ID univoco per elementi/template.
 *
 * `crypto.randomUUID()` esiste SOLO in secure context (HTTPS o localhost):
 * quando l'app è servita da GreenEnergy su http (es. http://server.test)
 * è `undefined` e lanciava un TypeError, impedendo la creazione degli
 * elementi. `crypto.getRandomValues()` invece è disponibile anche in
 * contesti non sicuri, quindi lo usiamo per comporre un UUID v4 valido.
 */
export function generateId() {
  const cryptoObj = typeof crypto !== 'undefined' ? crypto : undefined

  if (cryptoObj?.randomUUID) {
    return cryptoObj.randomUUID()
  }

  const bytes = new Uint8Array(16)

  if (cryptoObj?.getRandomValues) {
    cryptoObj.getRandomValues(bytes)
  } else {
    for (let index = 0; index < bytes.length; index += 1) {
      bytes[index] = Math.floor(Math.random() * 256)
    }
  }

  // Versione 4 e variante RFC 4122.
  bytes[6] = (bytes[6] & 0x0f) | 0x40
  bytes[8] = (bytes[8] & 0x3f) | 0x80

  const hex = Array.from(bytes, (byte) => byte.toString(16).padStart(2, '0'))

  return (
    `${hex[0]}${hex[1]}${hex[2]}${hex[3]}-${hex[4]}${hex[5]}-${hex[6]}${hex[7]}-` +
    `${hex[8]}${hex[9]}-${hex[10]}${hex[11]}${hex[12]}${hex[13]}${hex[14]}${hex[15]}`
  )
}
