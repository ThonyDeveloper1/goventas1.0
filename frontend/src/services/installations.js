import api from './api'

export default {
  list(params = {}) {
    return api.get('/installations', { params })
  },

  get(id) {
    return api.get(`/installations/${id}`)
  },

  /**
   * @param {Object} data - { client_id, fecha, hora_inicio, duracion?, estado?, notas? }
   */
  create(data) {
    return api.post('/installations', data)
  },

  update(id, data) {
    return api.put(`/installations/${id}`, data)
  },

  remove(id) {
    return api.delete(`/installations/${id}`)
  },

  /**
   * Legacy: Get occupied/available slots for a date (fixed 3h blocks).
   */
  availability(fecha, excludeId = null) {
    return api.get('/installations/availability', {
      params: { fecha, ...(excludeId ? { exclude_id: excludeId } : {}) },
    })
  },

  /**
   * Get per-hour, per-duration availability for a date.
   * Returns slots with availability for 1h, 2h, 3h durations.
   * Respects working hours (08:00–18:00) and lunch block (13:00–15:00).
   * @param {string} date       - YYYY-MM-DD
   * @param {number} excludeId  - installation ID to exclude (for edit mode)
   */
  availableSlots(date, excludeId = null) {
    return api.get('/installations/available-slots', {
      params: { date, ...(excludeId ? { exclude_id: excludeId } : {}) },
    })
  },
}
