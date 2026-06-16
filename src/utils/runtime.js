export function getCurrentOrigin() {
  if (typeof window === 'undefined') return 'http://localhost:8000'
  return (window.location?.origin || 'http://localhost:8000').replace(/\/+$/, '')
}

/** Base API: stessa origine del server (GreenEnergy o dev Vite con proxy). */
export function getApiBaseUrl() {
  const fromEnv = import.meta.env.VITE_API_BASE
  if (fromEnv !== undefined && fromEnv !== '') {
    return String(fromEnv).replace(/\/+$/, '')
  }
  return getCurrentOrigin()
}
