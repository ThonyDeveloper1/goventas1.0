import api from './api'

export default {
  /** Config for all authenticated users (no secrets). */
  getPublicConfig() {
    return api.get('/app-config')
  },

  /** Admin: status indicators — never the actual tokens. */
  getSettings() {
    return api.get('/admin/settings')
  },

  /** Admin: save settings (toggles and/or token). */
  saveSettings(data) {
    return api.post('/admin/settings', data)
  },

  /** Admin: save RENIEC token. */
  saveToken(token) {
    return api.post('/admin/settings', { reniec_token: token })
  },

  /** Admin: clear the stored RENIEC token. */
  clearToken() {
    return api.delete('/admin/settings/reniec-token')
  },

  /** Admin: test the RENIEC token. */
  testReniec() {
    return api.post('/admin/settings/test-reniec')
  },

  /* ─── MikroTik Routers ─────────────────────────────── */

  getRouters() {
    return api.get('/admin/routers')
  },

  createRouter(data) {
    return api.post('/admin/routers', data)
  },

  updateRouter(id, data) {
    return api.put(`/admin/routers/${id}`, data)
  },

  deleteRouter(id) {
    return api.delete(`/admin/routers/${id}`)
  },

  testRouter(id) {
    return api.post(`/admin/routers/${id}/test`)
  },
}

