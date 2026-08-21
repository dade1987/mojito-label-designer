import { beforeEach, describe, expect, it, vi } from 'vitest'
import {
  authHeaders,
  clearStoredPassword,
  getStoredPassword,
  setStoredPassword,
} from '../authStorage.js'

describe('authStorage', () => {
  beforeEach(() => {
    sessionStorage.clear()
  })

  it('parte senza password memorizzata', () => {
    expect(getStoredPassword()).toBe('')
    expect(authHeaders()).toEqual({})
  })

  it('memorizza e rilegge la password, e la allega come intestazione', () => {
    expect(setStoredPassword('segreta')).toBe(true)
    expect(getStoredPassword()).toBe('segreta')
    expect(authHeaders()).toEqual({ 'X-Mojito-Auth': 'segreta' })
  })

  it('clearStoredPassword rimuove la password', () => {
    setStoredPassword('segreta')
    expect(clearStoredPassword()).toBe(true)
    expect(getStoredPassword()).toBe('')
    expect(sessionStorage.getItem('mojito_auth_password')).toBe(null)
  })

  it('valori falsy equivalgono a rimuovere', () => {
    setStoredPassword('segreta')
    expect(setStoredPassword('')).toBe(true)
    expect(getStoredPassword()).toBe('')
    expect(setStoredPassword(null)).toBe(true)
  })

  it('sopravvive agli errori dello storage', () => {
    const getSpy = vi.spyOn(Storage.prototype, 'getItem').mockImplementation(() => {
      throw new Error('fail')
    })
    expect(getStoredPassword()).toBe('')
    getSpy.mockRestore()

    const setSpy = vi.spyOn(Storage.prototype, 'setItem').mockImplementation(() => {
      throw new Error('fail')
    })
    expect(setStoredPassword('x')).toBe(false)
    setSpy.mockRestore()
  })
})
