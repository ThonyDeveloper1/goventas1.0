import api from './api'

export default {
  list(params = {}) {
    return api.get('/plans', { params })
  },

  get(id) {
    return api.get(`/admin/plans/${id}`)
  },

  create(data) {
    return api.post('/admin/plans', data)
  },

  update(id, data) {
    return api.put(`/admin/plans/${id}`, data)
  },

  remove(id) {
    return api.delete(`/admin/plans/${id}`)
  },
}
