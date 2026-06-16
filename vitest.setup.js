import { beforeEach, afterEach, vi } from 'vitest'

function createMemoryStorage() {
  const map = new Map()
  return {
    getItem: (k) => (map.has(String(k)) ? String(map.get(String(k))) : null),
    setItem: (k, v) => {
      map.set(String(k), String(v))
    },
    removeItem: (k) => {
      map.delete(String(k))
    },
    clear: () => {
      map.clear()
    },
  }
}

beforeEach(() => {
  Object.defineProperty(globalThis, 'localStorage', {
    value: createMemoryStorage(),
    configurable: true,
  })
})

afterEach(() => {
  vi.restoreAllMocks()
})
