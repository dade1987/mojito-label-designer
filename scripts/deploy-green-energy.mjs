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

console.log('Deploy completato. Su GreenEnergyServer esegui: composer dump-autoload -o')
