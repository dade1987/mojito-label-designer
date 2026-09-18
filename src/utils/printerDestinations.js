// Dove stampare dal designer.
//
// Oltre alle stampanti del server, il server che ospita Mojito puo' offrire
// stampanti di rete ("ip:HOST:PORTA") e le stampanti collegate ai PC di
// reparto ("pc:NOMEPC|STAMPANTE", stampate dall'agente di quel PC). Nella
// tendina compaiono con un nome leggibile e divise per dove si trovano.

const NETWORK_PREFIX = 'ip:'
const PC_PREFIX = 'pc:'
const DEFAULT_PORT = 9100
const STORAGE_KEY = 'mojito.networkPrinters'

export function destinationKind(value) {
  const text = String(value ?? '')
  if (text.startsWith(NETWORK_PREFIX)) return 'network'
  if (text.startsWith(PC_PREFIX)) return 'pc'
  return 'server'
}

/** Il nome da mostrare: quello dato dal server, altrimenti ricostruito. */
export function printerLabel(value, labels = {}) {
  const given = labels?.[value]
  if (typeof given === 'string' && given !== '') return given

  const kind = destinationKind(value)
  if (kind === 'network') return `Rete · ${value.slice(NETWORK_PREFIX.length)}`
  if (kind === 'pc') {
    const [station, printer] = value.slice(PC_PREFIX.length).split('|')
    return printer ? `${station} · ${printer}` : station
  }
  return value
}

const GROUPS = [
  ['server', 'Stampanti del server'],
  ['network', 'Stampanti di rete'],
  ['pc', 'Stampanti dei PC (agente di stampa)'],
]

export function groupPrinters(list, labels = {}) {
  const printers = Array.isArray(list) ? list : []

  return GROUPS
    .map(([kind, title]) => ({
      title,
      items: printers
        .filter((value) => destinationKind(value) === kind)
        .map((value) => ({ value, label: printerLabel(value, labels) })),
    }))
    .filter((group) => group.items.length > 0)
}

/**
 * La stampante di serie: la Citizen del server se c'e', poi la prima del
 * server. Mai una stampante di un PC per caso: "SURFACE9 · Citizen" contiene
 * "citizen" anche lei, e il designer manderebbe tutto in reparto.
 */
export function pickDefaultPrinter(list) {
  const printers = Array.isArray(list) ? list : []
  const server = printers.filter((value) => destinationKind(value) === 'server')

  if (server.length) return server.find((name) => /citizen/i.test(name)) ?? server[0]

  return printers[0] ?? ''
}

const IPV4 = /^(\d{1,3})\.(\d{1,3})\.(\d{1,3})\.(\d{1,3})$/

/** "192.168.1.50" o "192.168.1.50:9101" -> "ip:192.168.1.50:9101". */
export function parseNetworkPrinter(input) {
  const text = String(input ?? '').trim()
  if (!text) throw new Error('Scrivi l\'indirizzo IP della stampante di rete (es. 192.168.1.50).')

  const [host, portText] = text.split(':')
  const match = IPV4.exec(host)
  if (!match || match.slice(1).some((part) => Number(part) > 255)) {
    throw new Error(`"${host}" non e' un indirizzo IP (es. 192.168.1.50).`)
  }

  const port = portText === undefined ? DEFAULT_PORT : Number(portText)
  if (!Number.isInteger(port) || port < 1 || port > 65535) {
    throw new Error(`La porta "${portText}" non e' valida: di solito e' 9100.`)
  }

  return `${NETWORK_PREFIX}${host}:${port}`
}

function defaultStorage() {
  return typeof localStorage === 'undefined' ? null : localStorage
}

/** Le stampanti di rete aggiunte a mano in questo browser. */
export function loadSavedNetworkPrinters(storage = defaultStorage()) {
  try {
    const parsed = JSON.parse(storage?.getItem(STORAGE_KEY) ?? '[]')
    if (!Array.isArray(parsed)) return []
    return parsed.filter((value) => typeof value === 'string' && destinationKind(value) === 'network')
  } catch {
    return []
  }
}

export function saveNetworkPrinter(value, storage = defaultStorage()) {
  const saved = loadSavedNetworkPrinters(storage)
  if (!saved.includes(value)) saved.push(value)
  try {
    storage?.setItem(STORAGE_KEY, JSON.stringify(saved))
  } catch {
    // Finestra privata o memoria bloccata: la stampante resta per questa sessione.
  }
}

export function mergeSavedNetworkPrinters(list, saved) {
  const printers = Array.isArray(list) ? [...list] : []
  for (const value of saved) {
    if (!printers.includes(value)) printers.push(value)
  }
  return printers
}

/** Rete e PC ricevono solo ZPL: il designer imposta il layout di conseguenza. */
export function zplOnlyModes(list) {
  return Object.fromEntries(
    list.filter((value) => destinationKind(value) !== 'server').map((value) => [value, 'zpl'])
  )
}

export function printOutcomeMessage(result, label, subject) {
  if (result?.status === 'queued') {
    return `${subject} in coda per ${label}: la stampa l'agente di quel PC`
  }
  return `${subject} inviata a ${label}`
}
