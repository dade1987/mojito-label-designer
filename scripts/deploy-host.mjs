import { cp, mkdir, readdir, rename, rm, stat, writeFile } from 'node:fs/promises'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const __filename = fileURLToPath(import.meta.url)
const __dirname = path.dirname(__filename)
const rootDir = path.resolve(__dirname, '..')
// Il gestionale Laravel che ospita Mojito come "station": il percorso si da' da fuori,
// cosi' nel repository non compare il nome dell'installazione.
//   MOJITO_HOST_ROOT=../mio-gestionale npm run deploy:host
if (!process.env.MOJITO_HOST_ROOT) {
  throw new Error('Indica il gestionale che ospita Mojito: MOJITO_HOST_ROOT=../percorso npm run deploy:host')
}
const hostRoot = path.resolve(rootDir, process.env.MOJITO_HOST_ROOT)

// Build con percorsi assoluti (npm run build:host): la pagina del designer la
// serve il gestionale da un altro indirizzo, gli asset restano qui sotto.
const webSourceDir = path.join(rootDir, 'dist-host')
const webTargetDir = process.env.MOJITO_HOST_WEB_DEST
  ? path.resolve(rootDir, process.env.MOJITO_HOST_WEB_DEST)
  : path.join(hostRoot, 'public', 'stations', 'apps', 'mojito')

// La pagina vera la serve il gestionale solo a chi ha il permesso; nella
// cartella pubblica resta un rimando, cosi' i vecchi link portano al login.
const shellTargetDir = path.join(hostRoot, 'resources', 'stations', 'mojito')
const hostAppUrl = '/mojito'
const redirectStub = `<!doctype html>
<html lang="it">
<head>
<meta charset="utf-8">
<meta http-equiv="refresh" content="0; url=${hostAppUrl}">
<title>Mojito - Label Designer</title>
</head>
<body><a href="${hostAppUrl}">Apri Mojito</a></body>
</html>
`

const phpSourceDir = path.join(rootDir, 'server', 'src')
const phpTargetDir = path.join(hostRoot, 'lib', 'mojito-label', 'src')

try {
  await stat(webSourceDir)
} catch {
  throw new Error(`Build non trovata in ${webSourceDir}. Esegui prima npm run build:host.`)
}

await mkdir(webTargetDir, { recursive: true })

for (const entry of await readdir(webTargetDir)) {
  await rm(path.join(webTargetDir, entry), { recursive: true, force: true })
}

await cp(webSourceDir, webTargetDir, { recursive: true })
await mkdir(shellTargetDir, { recursive: true })
await rename(path.join(webTargetDir, 'index.html'), path.join(shellTargetDir, 'index.html'))
await writeFile(path.join(webTargetDir, 'index.html'), redirectStub)
console.log(`Mojito web deploy: ${webSourceDir} -> ${webTargetDir} (pagina in ${shellTargetDir})`)

await mkdir(phpTargetDir, { recursive: true })

for (const entry of await readdir(phpTargetDir)) {
  await rm(path.join(phpTargetDir, entry), { force: true })
}

await cp(phpSourceDir, phpTargetDir, { recursive: true })
console.log(`Mojito PHP deploy: ${phpSourceDir} -> ${phpTargetDir}`)

const psTargetDir = path.join(hostRoot, 'lib', 'mojito-label', 'bin')
await mkdir(psTargetDir, { recursive: true })

// print-raw.ps1 manda lo ZPL in RAW, print-image.ps1 stampa l'etichetta gia'
// disegnata: senza il secondo, sulle stampanti non ZPL non esce niente.
for (const script of ['print-raw.ps1', 'print-image.ps1']) {
  const psSource = path.join(rootDir, 'server', 'bin', script)
  await cp(psSource, path.join(psTargetDir, script))
  console.log(`Mojito PS1 deploy: ${psSource} -> ${psTargetDir}`)
}

// Il font con cui viene disegnato il testo nella stampa grafica: senza, il
// server ripiega sui font di sistema e le etichette cambiano faccia da una
// macchina all'altra.
const fontsSource = path.join(rootDir, 'server', 'resources', 'fonts')
const fontsTargetDir = path.join(hostRoot, 'lib', 'mojito-label', 'resources', 'fonts')
await mkdir(fontsTargetDir, { recursive: true })
await cp(fontsSource, fontsTargetDir, { recursive: true })
console.log(`Mojito font deploy: ${fontsSource} -> ${fontsTargetDir}`)

console.log('Deploy completato. Nel gestionale che ospita Mojito esegui: composer dump-autoload -o')
