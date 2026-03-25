import api from './api'

export default {
  networkOverview() {
    return api.get('/mikrotik/network-overview')
  },

  clientStatus(clientId) {
    return api.get(`/mikrotik/status/${clientId}`)
  },

  activate(clientId) {
    return api.post(`/mikrotik/activate/${clientId}`)
  },

  suspend(clientId) {
    return api.post(`/mikrotik/suspend/${clientId}`)
  },

  provision(clientId, data) {
    return api.post(`/mikrotik/provision/${clientId}`, data)
  },

  syncAll() {
    return api.post('/mikrotik/sync-all')
  },

  /* ── ISP Multi-Router ──────────────────────────────── */

  getRouters() {
    return api.get('/admin/routers')
  },

  getCortesMorosos(routerId) {
    return api.get(`/mikrotik/isp/${routerId}/corte-moroso`)
  },

  syncMorosos(routerId) {
    return api.post(`/mikrotik/isp/${routerId}/sync-morosos`)
  },
}
