/**
 * La password del designer, quando l'installazione la richiede.
 *
 * Sul server di produzione (MOJITO_PASSWORD impostata) il designer non deve
 * essere aperto a chiunque passi davanti alla postazione. La password vive in
 * sessionStorage: chiudendo la scheda si ripresenta la richiesta.
 */

const STORAGE_KEY = 'mojito_auth_password'

export function getStoredPassword() {
  try {
    return sessionStorage.getItem(STORAGE_KEY) || ''
  } catch {
    return ''
  }
}

export function setStoredPassword(password) {
  try {
    const value = String(password || '')
    if (value === '') {
      sessionStorage.removeItem(STORAGE_KEY)
    } else {
      sessionStorage.setItem(STORAGE_KEY, value)
    }
    return true
  } catch {
    return false
  }
}

export function clearStoredPassword() {
  return setStoredPassword('')
}

/** Le intestazioni da allegare alle chiamate API. */
export function authHeaders() {
  const password = getStoredPassword()
  return password === '' ? {} : { 'X-Mojito-Auth': password }
}
