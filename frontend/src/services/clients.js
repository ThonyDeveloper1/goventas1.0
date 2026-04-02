import api from './api'

export default {
  /**
   * List clients with optional filters + pagination.
   * @param {Object} params
   */
  list(params = {}) {
    return api.get('/clients', { params })
  },

  get(id) {
    return api.get(`/clients/${id}`)
  },

  /**
   * Create a client (possibly with photos).
   * @param {Object} data  - plain fields
   * @param {File[]} fotos - array of File objects
   */
  create(data, fotos = [], options = {}) {
    return postWithRetry('/clients', buildFormData(data, fotos), options)
  },

  /**
   * Update a client (PATCH, supports photos).
   */
  update(id, data, fotos = [], options = {}) {
    const fd = buildFormData(data, fotos)
    fd.append('_method', 'PUT')
    return postWithRetry(`/clients/${id}`, fd, options)
  },

  remove(id) {
    return api.delete(`/clients/${id}`)
  },

  updateStatus(id, estado) {
    return api.post(`/clients/${id}/status`, { estado })
  },

  removePhoto(clientId, photoId) {
    return api.delete(`/clients/${clientId}/photos/${photoId}`)
  },

  /**
   * Look up a person by DNI via RENIEC service.
   * @param {string} dni
   * @returns {Promise<{nombres: string, apellidos: string}>}
   */
  lookupDni(dni) {
    return api.get(`/reniec/${dni}`)
  },

  assignIp(clientId, data) {
    return api.post(`/clients/${clientId}/assign-ip`, data)
  },

  clearIp(clientId) {
    return api.post(`/clients/${clientId}/clear-ip`)
  },

  ipHistory(clientId, page = 1) {
    return api.get(`/clients/${clientId}/ip-history`, { params: { page } })
  },
}

/* ── Helpers ──────────────────────────────────────────────── */

async function postWithRetry(url, formData, options = {}) {
  const retries = Number.isInteger(options.retries) ? options.retries : 1
  const baseDelayMs = options.baseDelayMs ?? 900

  let lastError
  for (let attempt = 0; attempt <= retries; attempt++) {
    try {
      return await api.post(url, formData, {
        headers: { 'Content-Type': 'multipart/form-data' },
        timeout: options.timeout ?? 180000,
        onUploadProgress: options.onUploadProgress,
      })
    } catch (error) {
      lastError = error
      const status = error?.response?.status
      const code = error?.code
      const retriableStatus = [408, 429, 500, 502, 503, 504].includes(status)
      const retriableCode = ['ECONNABORTED', 'ERR_NETWORK', 'ETIMEDOUT'].includes(code)
      const shouldRetry = attempt < retries && (retriableStatus || retriableCode || !status)

      if (!shouldRetry) {
        throw error
      }

      const waitMs = baseDelayMs * (attempt + 1)
      await new Promise((resolve) => setTimeout(resolve, waitMs))
    }
  }

  throw lastError
}

function buildFormData(data, fotos) {
  const fd = new FormData()

  for (const [key, value] of Object.entries(data)) {
    if (value !== null && value !== undefined) {
      fd.append(key, value)
    }
  }

  if (Array.isArray(fotos)) {
    for (const file of fotos) {
      fd.append('fotos[]', file)
    }
    return fd
  }

  for (const file of fotos.fachada ?? []) {
    fd.append('fotos_fachada[]', file)
  }

  for (const file of fotos.dni ?? []) {
    fd.append('fotos_dni[]', file)
  }

  return fd
}
