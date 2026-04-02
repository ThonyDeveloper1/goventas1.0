import api from './api'

export default {
  list(params = {}) {
    return api.get('/admin/users', { params })
  },

  get(id) {
    return api.get(`/admin/users/${id}`)
  },

  create(data) {
    return api.post('/admin/users', data)
  },

  update(id, data) {
    return api.put(`/admin/users/${id}`, data)
  },

  remove(id, force = false) {
    return api.delete(`/admin/users/${id}`, { params: force ? { force: 1 } : {} })
  },
}
