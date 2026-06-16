const { contextBridge, ipcRenderer } = require('electron')

contextBridge.exposeInMainWorld('electronAPI', {
  platform: process.platform,
  layout: {
    saveFile: (payload) => ipcRenderer.invoke('layout:save-file', payload),
    openFile: () => ipcRenderer.invoke('layout:open-file'),
  },
})
