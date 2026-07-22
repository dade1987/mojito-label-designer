import { afterEach, describe, expect, it, vi } from 'vitest'
import { generateId } from '../id.js'

const UUID_RE =
  /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/

describe('generateId', () => {
  afterEach(() => {
    vi.unstubAllGlobals()
  })

  it('uses crypto.randomUUID when available (secure context)', () => {
    const randomUUID = vi.fn(() => '11111111-1111-4111-8111-111111111111')
    vi.stubGlobal('crypto', { randomUUID })

    expect(generateId()).toBe('11111111-1111-4111-8111-111111111111')
    expect(randomUUID).toHaveBeenCalledOnce()
  })

  it('falls back to getRandomValues on insecure context (no randomUUID)', () => {
    const getRandomValues = vi.fn((bytes) => {
      for (let index = 0; index < bytes.length; index += 1) {
        bytes[index] = index
      }
      return bytes
    })
    vi.stubGlobal('crypto', { getRandomValues })

    const id = generateId()
    expect(id).toMatch(UUID_RE)
    expect(getRandomValues).toHaveBeenCalledOnce()
  })

  it('produces a valid v4 UUID even without any crypto', () => {
    vi.stubGlobal('crypto', undefined)
    expect(generateId()).toMatch(UUID_RE)
  })

  it('does not collide across many calls', () => {
    const getRandomValues = (bytes) => {
      for (let index = 0; index < bytes.length; index += 1) {
        bytes[index] = Math.floor(Math.random() * 256)
      }
      return bytes
    }
    vi.stubGlobal('crypto', { getRandomValues })

    const ids = new Set(Array.from({ length: 1000 }, () => generateId()))
    expect(ids.size).toBe(1000)
  })
})
