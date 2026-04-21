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

  start(id) {
    return api.post(`/supervisions/${id}/start`)
  },

  complete(id, data = {}) {
    return api.post(`/supervisions/${id}/complete`, data)
  },

  uploadPhotos(id, files) {
    const form = new FormData()
    files.forEach((file) => form.append('fotos[]', file))
    return api.post(`/supervisions/${id}/photos`, form, {
      headers: { 'Content-Type': 'multipart/form-data' },
    })
  },

  removePhoto(id, photoId) {
    return api.delete(`/supervisions/${id}/photos/${photoId}`)
  },
}
