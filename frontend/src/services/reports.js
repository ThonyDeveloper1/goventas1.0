import api from './api'

export default {
  summary() {
    return api.get('/reports/summary')
  },

  sales(params = {}) {
    return api.get('/reports/sales', { params })
  },

  vendors() {
    return api.get('/reports/vendors')
  },

  clients(params = {}) {
    return api.get('/reports/clients', { params })
  },

  clientsPDF(params = {}) {
    return api.get('/reports/clients/pdf', {
      params,
      responseType: 'blob',
    })
  },

  map(params = {}) {
    return api.get('/reports/map', { params })
  },
}
