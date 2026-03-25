import api from './api'

export default {
  list(params = {}) {
    return api.get('/suspicious-sales', { params })
  },

  stats(params = {}) {
    return api.get('/suspicious-sales/stats', { params })
  },

  analyze(clientId) {
    return api.post(`/suspicious-sales/analyze/${clientId}`)
  },

  approve(id) {
    return api.post(`/suspicious-sales/${id}/approve`)
  },

  reject(id) {
    return api.post(`/suspicious-sales/${id}/reject`)
  },

  unapprove(id) {
    return api.post(`/suspicious-sales/${id}/unapprove`)
  },
}
