import { app, BrowserWindow, dialog, ipcMain } from 'electron'
import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const __filename = fileURLToPath(import.meta.url)
const __dirname = path.dirname(__filename)

const isDev = process.env.NODE_ENV === 'development'

function getLayoutsDir() {
  return path.join(app.getPath('documents'), 'Mojito', 'layouts')
}

ipcMain.handle('layout:save-file', async (_event, payload) => {
  const layoutsDir = getLayoutsDir()

  if (!fs.existsSync(layoutsDir)) {
    fs.mkdirSync(layoutsDir, { recursive: true })
  }

  const suggestedName = `${String(payload?.name ?? 'layout').replace(/\s+/g, '_').toLowerCase()}.mojito.json`
  const { canceled, filePath } = await dialog.showSaveDialog({
    title: 'Salva layout etichetta',
    defaultPath: path.join(layoutsDir, suggestedName),
    filters: [{ name: 'Layout Mojito', extensions: ['json'] }],
  })

  if (canceled || !filePath) {
    return { canceled: true }
  }

  fs.writeFileSync(filePath, JSON.stringify(payload, null, 2), 'utf-8')

  return { canceled: false, filePath }
})

ipcMain.handle('layout:open-file', async () => {
  const layoutsDir = getLayoutsDir()

  if (!fs.existsSync(layoutsDir)) {
    fs.mkdirSync(layoutsDir, { recursive: true })
  }

  const { canceled, filePaths } = await dialog.showOpenDialog({
    title: 'Apri layout etichetta',
    defaultPath: layoutsDir,
    filters: [{ name: 'Layout Mojito', extensions: ['json'] }],
    properties: ['openFile'],
  })

  if (canceled || filePaths.length === 0) {
    return { canceled: true }
  }

  const filePath = filePaths[0]
  const content = fs.readFileSync(filePath, 'utf-8')

  return {
    canceled: false,
    filePath,
    content: JSON.parse(content),
  }
})

function createWindow() {
  const win = new BrowserWindow({
    width: 1400,
    height: 900,
    webPreferences: {
      preload: path.join(__dirname, 'preload.js'),
      contextIsolation: true,
      nodeIntegration: false,
    },
  })

  if (isDev) {
    win.loadURL('http://localhost:5174')
    win.webContents.openDevTools()
  } else {
    win.loadFile(path.join(__dirname, '..', 'dist', 'index.html'))
  }
}

app.whenReady().then(() => {
  createWindow()

  app.on('activate', () => {
    if (BrowserWindow.getAllWindows().length === 0) createWindow()
  })
})

app.on('window-all-closed', () => {
  if (process.platform !== 'darwin') {
    app.quit()
  }
})
