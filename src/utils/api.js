import { authHeaders } from './authStorage.js'

const API_BASE = import.meta.env.VITE_API_BASE ?? ''

function resolveBaseUrl() {
  if (API_BASE !== '') {
    return API_BASE.replace(/\/+$/, '')
  }

  if (typeof window !== 'undefined') {
    return window.location.origin.replace(/\/+$/, '')
  }

  return ''
}

async function request(path, options = {}) {
  const response = await fetch(`${resolveBaseUrl()}${path}`, {
    headers: {
      'Content-Type': 'application/json',
      ...authHeaders(),
      ...(options.headers ?? {}),
    },
    ...options,
  })

  const payload = await response.json().catch(() => ({}))

  if (!response.ok) {
    const error = new Error(payload.error ?? `Errore HTTP ${response.status}`)
    error.status = response.status
    throw error
  }

  return payload
}

/** Se l'installazione richiede la password e se quella memorizzata vale. */
export function fetchAuthStatus() {
  return request('/api/auth')
}

export function checkPassword(password) {
  return request('/api/auth/check', {
    method: 'POST',
    body: JSON.stringify({ password }),
  })
}

export function fetchPrinters() {
  return request('/api/printers')
}

export function fetchDefaultTemplate() {
  return request('/api/template/default')
}

export function fetchTemplates() {
  return request('/api/templates')
}

export function fetchTemplate(id) {
  return request(`/api/templates/${encodeURIComponent(id)}`)
}

export function saveTemplate(template) {
  return request('/api/templates', {
    method: 'POST',
    body: JSON.stringify(template),
  })
}

export function deleteTemplate(id) {
  return request(`/api/templates/${encodeURIComponent(id)}`, {
    method: 'DELETE',
  })
}

export function previewZpl(body) {
  return request('/api/zpl/preview', {
    method: 'POST',
    body: JSON.stringify(body),
  })
}

export function printLabel(body) {
  return request('/api/print', {
    method: 'POST',
    body: JSON.stringify(body),
  })
}
