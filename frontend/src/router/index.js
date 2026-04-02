import { createRouter, createWebHistory } from 'vue-router'
import { useAuthStore } from '@/store/auth'

const routes = [
  /* ── Public ───────────────────────────────────────────────────── */
  {
    path: '/login',
    name: 'Login',
    component: () => import('@/views/Login.vue'),
    meta: { requiresGuest: true },
  },

  /* ── Protected (requires auth) ────────────────────────────────── */
  {
    path: '/',
    component: () => import('@/layouts/MainLayout.vue'),
    meta: { requiresAuth: true },
    children: [
      {
        path: '',
        redirect: '/dashboard',
      },
      {
        path: 'dashboard',
        name: 'Dashboard',
        component: () => import('@/views/Dashboard.vue'),
      },
      {
        path: 'credenciales',
        name: 'Credenciales',
        component: () => import('@/views/Credenciales.vue'),
        meta: { roles: ['admin', 'vendedora', 'supervisor'] },
      },

      /* Admin */
      {
        path: 'admin/usuarios',
        name: 'AdminUsuarios',
        component: () => import('@/views/admin/AdminPanel.vue'),
        meta: { roles: ['admin'] },
      },
      {
        path: 'configuracion',
        name: 'Configuracion',
        component: () => import('@/views/admin/Configuracion.vue'),
        meta: { roles: ['admin'] },
      },
      {
        path: 'backup',
        name: 'Backup',
        component: () => import('@/views/admin/Backup.vue'),
        meta: { roles: ['admin'] },
      },
      {
        path: 'admin/planes',
        name: 'AdminPlanes',
        component: () => import('@/views/admin/PlansManagement.vue'),
        meta: { roles: ['admin'] },
      },

      /* ── Clients ──────────────────────────────────────────────── */
      {
        path: 'clientes',
        name: 'Clientes',
        component: () => import('@/views/clientes/ClientsList.vue'),
        meta: { roles: ['admin', 'vendedora'] },
      },
      {
        path: 'clientes/subir-venta',
        name: 'SubirVenta',
        component: () => import('@/views/clientes/ClientForm.vue'),
        meta: { roles: ['admin'] },
      },
      {
        path: 'clientes/nuevo',
        name: 'ClienteNuevo',
        component: () => import('@/views/clientes/ClientForm.vue'),
        meta: { roles: ['admin', 'vendedora'] },
      },
      {
        path: 'clientes/:id/editar',
        name: 'ClienteEditar',
        component: () => import('@/views/clientes/ClientForm.vue'),
        meta: { roles: ['admin', 'vendedora'] },
      },
      {
        path: 'clientes/:id',
        name: 'ClienteDetalle',
        component: () => import('@/views/clientes/ClientForm.vue'),
        meta: { roles: ['admin', 'vendedora'] },
      },

      /* ── Installations ───────────────────────────────────────── */
      {
        path: 'instalaciones',
        name: 'Instalaciones',
        component: () => import('@/views/instalaciones/InstallationsCalendar.vue'),
        meta: { roles: ['admin', 'supervisor', 'vendedora'] },
      },
      {
        path: 'instalaciones/nueva',
        name: 'InstalacionNueva',
        component: () => import('@/views/instalaciones/InstallationForm.vue'),
        meta: { roles: ['admin', 'supervisor', 'vendedora'] },
      },
      {
        path: 'instalaciones/:id/editar',
        name: 'InstalacionEditar',
        component: () => import('@/views/instalaciones/InstallationForm.vue'),
        meta: { roles: ['admin', 'supervisor', 'vendedora'] },
      },

      /* ── Supervisions ─────────────────────────────────────────── */
      {
        path: 'supervisiones',
        name: 'Supervisiones',
        component: () => import('@/views/supervisiones/SupervisionList.vue'),
        meta: { roles: ['admin', 'supervisor'] },
      },
      {
        path: 'supervisiones/:id',
        name: 'SupervisionDetalle',
        component: () => import('@/views/supervisiones/SupervisionDetail.vue'),
        meta: { roles: ['admin', 'supervisor'] },
      },

      /* ── Network / MikroTik ─────────────────────────────────── */
      {
        path: 'red',
        name: 'EstadoRed',
        component: () => import('@/views/red/NetworkStatus.vue'),
        meta: { roles: ['admin'] },
      },
      {
        path: 'red/morosos',
        name: 'CortesMorosos',
        component: () => import('@/views/red/CortesMorosos.vue'),
        meta: { roles: ['admin'] },
      },

      /* ── Suspicious Sales / Fraud Detection ───────────────── */
      {
        path: 'ventas-sospechosas',
        name: 'VentasSospechosas',
        component: () => import('@/views/ventas-sospechosas/SuspiciousSales.vue'),
        meta: { roles: ['admin'] },
      },

      /* ── Reports ───────────────────────────────────────────── */
      {
        path: 'reportes',
        name: 'Reportes',
        component: () => import('@/views/reportes/ReportsDashboard.vue'),
        meta: { roles: ['admin'] },
      },
      {
        path: 'reportes/clientes',
        name: 'ReporteClientes',
        component: () => import('@/views/reportes/ClientsReport.vue'),
        meta: { roles: ['admin'] },
      },
      {
        path: 'reportes/mapa',
        name: 'ReporteMapa',
        component: () => import('@/views/reportes/ClientsMap.vue'),
        meta: { roles: ['admin'] },
      },
    ],
  },

  /* ── Catch-all ────────────────────────────────────────────────── */
  {
    path: '/:pathMatch(.*)*',
    redirect: '/',
  },
]

const router = createRouter({
  history: createWebHistory(),
  routes,
  scrollBehavior: () => ({ top: 0 }),
})

/* ── Navigation Guard ─────────────────────────────────────────── */
router.beforeEach(async (to, _from, next) => {
  const auth = useAuthStore()

  // Restore user from token on first load
  if (auth.token && !auth.user) {
    await auth.fetchUser()
  }

  if (to.meta.requiresAuth && !auth.isAuthenticated) {
    return next('/login')
  }

  if (to.meta.requiresGuest && auth.isAuthenticated) {
    return next('/dashboard')
  }

  if (to.meta.roles && !auth.hasRole(to.meta.roles)) {
    return next('/dashboard')   // Redirect to dashboard if unauthorized role
  }

  next()
})

export default router
