import axios from 'axios'

const envBaseUrl = import.meta.env.VITE_API_URL
const baseURL = envBaseUrl && envBaseUrl.trim() !== ''
  ? envBaseUrl.replace(/\/$/, '')
  : '/api'

const api = axios.create({
  baseURL,
  headers: {
    'Content-Type': 'application/json',
    'Accept': 'application/json',
  },
  timeout: 15000,
})

/* ── Request interceptor: attach Bearer token ─── */
api.interceptors.request.use((config) => {
  const token = localStorage.getItem('token') ?? sessionStorage.getItem('token')
  if (token) {
    config.headers.Authorization = `Bearer ${token}`
  }
  return config
})

/* ── Response interceptor: handle 401 globally ── */
api.interceptors.response.use(
  (response) => response,
  (error) => {
    if (error.response?.status === 401) {
      localStorage.removeItem('token')
      sessionStorage.removeItem('token')
      // Redirect only if not already on login page
      if (!window.location.pathname.includes('/login')) {
        window.location.href = '/login'
      }
    }
    return Promise.reject(error)
  }
)

export default api
