import api from './api'

export default {
  list(params = {}) {
    return api.get('/supervisions', { params })
  },

  get(id) {
    return api.get(`/supervisions/${id}`)
  },

  supervisors() {
    return api.get('/supervisions/supervisors')
  },

  assign(data) {
    return api.post('/supervisions/assign', data)
  },

  setState(id, estadoId, comentario = null) {
    return api.post(`/supervisions/${id}/estado`, { estado_id: estadoId, comentario })
  },

  updateDetail(id, data) {
    return api.patch(`/supervisions/${id}`, data)
  },

  start(id) {
    return api.post(`/supervisions/${id}/start`)
  },

  complete(id, data = {}) {
    return api.post(`/supervisions/${id}/complete`, data)
  },

  uploadPhotos(id, files, tipo = 'general') {
    const form = new FormData()
    files.forEach((file) => form.append('fotos[]', file))
    form.append('tipo', tipo)
    return api.post(`/supervisions/${id}/photos`, form, {
      headers: { 'Content-Type': 'multipart/form-data' },
    })
  },

  removePhoto(id, photoId) {
    return api.delete(`/supervisions/${id}/photos/${photoId}`)
  },

  tickets(params = {}) {
    return api.get('/supervisions/tickets', { params })
  },

  // Estados CRUD
  getEstados() {
    return api.get('/supervisions/estados')
  },

  createEstado(data) {
    return api.post('/supervisions/estados', data)
  },

  updateEstado(id, data) {
    return api.put(`/supervisions/estados/${id}`, data)
  },

  deleteEstado(id) {
    return api.delete(`/supervisions/estados/${id}`)
  },
}
