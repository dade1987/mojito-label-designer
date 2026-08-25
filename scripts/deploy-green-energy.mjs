import { cp, mkdir, readdir, rm, stat } from 'node:fs/promises'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const __filename = fileURLToPath(import.meta.url)
const __dirname = path.dirname(__filename)
const rootDir = path.resolve(__dirname, '..')
const greenEnergyRoot = process.env.GREEN_ENERGY_ROOT
  ? path.resolve(rootDir, process.env.GREEN_ENERGY_ROOT)
  : path.resolve(rootDir, '..', 'GreenEnergyServer')

const webSourceDir = path.join(rootDir, 'dist')
const webTargetDir = process.env.GREEN_ENERGY_MOJITO_DEST
  ? path.resolve(rootDir, process.env.GREEN_ENERGY_MOJITO_DEST)
  : path.join(greenEnergyRoot, 'public', 'stations', 'apps', 'mojito')

const phpSourceDir = path.join(rootDir, 'server', 'src')
const phpTargetDir = path.join(greenEnergyRoot, 'lib', 'mojito-label', 'src')

try {
  await stat(webSourceDir)
} catch {
  throw new Error(`Build non trovata in ${webSourceDir}. Esegui prima npm run build:renderer.`)
}

await mkdir(webTargetDir, { recursive: true })

for (const entry of await readdir(webTargetDir)) {
  await rm(path.join(webTargetDir, entry), { recursive: true, force: true })
}

await cp(webSourceDir, webTargetDir, { recursive: true })
console.log(`Mojito web deploy: ${webSourceDir} -> ${webTargetDir}`)

await mkdir(phpTargetDir, { recursive: true })

for (const entry of await readdir(phpTargetDir)) {
  await rm(path.join(phpTargetDir, entry), { force: true })
}

await cp(phpSourceDir, phpTargetDir, { recursive: true })
console.log(`Mojito PHP deploy: ${phpSourceDir} -> ${phpTargetDir}`)

const psTargetDir = path.join(greenEnergyRoot, 'lib', 'mojito-label', 'bin')
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
const fontsTargetDir = path.join(greenEnergyRoot, 'lib', 'mojito-label', 'resources', 'fonts')
await mkdir(fontsTargetDir, { recursive: true })
await cp(fontsSource, fontsTargetDir, { recursive: true })
console.log(`Mojito font deploy: ${fontsSource} -> ${fontsTargetDir}`)

console.log('Deploy completato. Su GreenEnergyServer esegui: composer dump-autoload -o')
