<script setup>
import { ref, reactive, onMounted } from 'vue'
import { usePlansStore } from '@/store/plans'

const store = usePlansStore()

onMounted(() => store.fetchPlans())

/* ── Modal ──────────────────────────────────────────── */
const showModal   = ref(false)
const editingPlan = ref(null)
const saving      = ref(false)
const error       = ref('')

const form = reactive({
  nombre: '',
  velocidad_bajada: '',
  velocidad_subida: '',
  precio: '',
  condiciones: '',
  activo: true,
})

function openCreate() {
  editingPlan.value = null
  Object.assign(form, { nombre: '', velocidad_bajada: '', velocidad_subida: '', precio: '', condiciones: '', activo: true })
  error.value = ''
  showModal.value = true
}

function openEdit(plan) {
  editingPlan.value = plan
  Object.assign(form, {
    nombre: plan.nombre,
    velocidad_bajada: plan.velocidad_bajada,
    velocidad_subida: plan.velocidad_subida,
    precio: plan.precio,
    condiciones: plan.condiciones || '',
    activo: plan.activo,
  })
  error.value = ''
  showModal.value = true
}

async function handleSave() {
  saving.value = true
  error.value = ''
  try {
    if (editingPlan.value) {
      await store.updatePlan(editingPlan.value.id, { ...form })
    } else {
      await store.createPlan({ ...form })
    }
    showModal.value = false
  } catch (e) {
    error.value = e.response?.data?.message || 'Error al guardar el plan.'
  } finally {
    saving.value = false
  }
}

async function toggleActive(plan) {
  try {
    await store.updatePlan(plan.id, { activo: !plan.activo })
  } catch (e) {
    console.error(e)
  }
}

async function removePlan(plan) {
  if (!confirm(`¿Eliminar el plan "${plan.nombre}"?`)) return
  try {
    await store.removePlan(plan.id)
  } catch (e) {
    alert(e.response?.data?.message || 'No se pudo eliminar.')
  }
}
</script>

<template>
  <div>
    <!-- Header -->
    <div class="flex items-center justify-between mb-6">
      <div>
        <h1 class="text-2xl font-bold text-gray-900">Planes de Servicio</h1>
        <p class="text-gray-500 text-sm mt-0.5">Gestiona los planes de internet disponibles</p>
      </div>
      <button @click="openCreate" class="btn-primary flex items-center gap-2">
        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
        Nuevo Plan
      </button>
    </div>

    <!-- Loading -->
    <div v-if="store.loading" class="flex justify-center py-12">
      <svg class="animate-spin h-8 w-8 text-primary" viewBox="0 0 24 24">
        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" fill="none"/>
        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
      </svg>
    </div>

    <!-- Plans grid -->
    <div v-else-if="store.items.length" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
      <div
        v-for="plan in store.items"
        :key="plan.id"
        :class="[
          'card relative overflow-hidden transition-all',
          plan.activo ? 'hover:shadow-md' : 'opacity-60',
        ]"
      >
        <!-- Active badge -->
        <div class="absolute top-3 right-3">
          <span
            :class="[
              'text-xs px-2 py-0.5 rounded-full font-medium',
              plan.activo ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-500',
            ]"
          >{{ plan.activo ? 'Activo' : 'Inactivo' }}</span>
        </div>

        <!-- Plan info -->
        <h3 class="text-lg font-bold text-gray-900 pr-16">{{ plan.nombre }}</h3>

        <div class="mt-3 flex items-baseline gap-1">
          <span class="text-3xl font-extrabold text-primary">S/ {{ plan.precio }}</span>
          <span class="text-sm text-gray-400">/mes</span>
        </div>

        <div class="mt-4 space-y-2">
          <div class="flex items-center gap-2 text-sm text-gray-600">
            <svg class="w-4 h-4 text-green-500" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 10l7-7m0 0l7 7m-7-7v18"/></svg>
            <span><strong>{{ plan.velocidad_bajada }}</strong> Mbps bajada</span>
          </div>
          <div class="flex items-center gap-2 text-sm text-gray-600">
            <svg class="w-4 h-4 text-blue-500" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 14l-7 7m0 0l-7-7m7 7V3"/></svg>
            <span><strong>{{ plan.velocidad_subida }}</strong> Mbps subida</span>
          </div>
          <div class="flex items-center gap-2 text-sm text-gray-600">
            <svg class="w-4 h-4 text-purple-500" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/></svg>
            <span>{{ plan.clients_count ?? 0 }} clientes</span>
          </div>
        </div>

        <p v-if="plan.condiciones" class="mt-3 text-xs text-gray-400 line-clamp-2">{{ plan.condiciones }}</p>

        <!-- Actions -->
        <div class="mt-4 pt-3 border-t border-gray-100 flex items-center gap-2">
          <button
            @click="openEdit(plan)"
            class="flex-1 text-sm text-primary hover:bg-primary/5 py-1.5 rounded-lg transition-colors"
          >Editar</button>
          <button
            @click="toggleActive(plan)"
            :class="['flex-1 text-sm py-1.5 rounded-lg transition-colors', plan.activo ? 'text-yellow-600 hover:bg-yellow-50' : 'text-green-600 hover:bg-green-50']"
          >{{ plan.activo ? 'Desactivar' : 'Activar' }}</button>
          <button
            v-if="!plan.clients_count"
            @click="removePlan(plan)"
            class="text-sm text-red-500 hover:bg-red-50 px-3 py-1.5 rounded-lg transition-colors"
          >
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
          </button>
        </div>
      </div>
    </div>

    <!-- Empty state -->
    <div v-else class="card text-center py-12">
      <svg class="w-16 h-16 text-gray-200 mx-auto mb-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"/>
      </svg>
      <p class="text-gray-500 mb-3">No hay planes de servicio configurados</p>
      <button @click="openCreate" class="btn-primary">Crear primer plan</button>
    </div>

    <!-- ═══════════════ Modal ═══════════════ -->
    <Teleport to="body">
      <Transition name="fade">
        <div v-if="showModal" class="fixed inset-0 z-50 flex items-center justify-center p-4">
          <div class="absolute inset-0 bg-black/40 backdrop-blur-sm" @click="showModal = false" />
          <div class="relative bg-white rounded-2xl shadow-2xl w-full max-w-md p-6 space-y-5">
            <h2 class="text-lg font-bold text-gray-900">
              {{ editingPlan ? 'Editar Plan' : 'Nuevo Plan' }}
            </h2>

            <div v-if="error" class="bg-red-50 border border-red-200 rounded-xl px-4 py-2 text-sm text-red-600">{{ error }}</div>

            <form @submit.prevent="handleSave" class="space-y-4">
              <!-- Nombre -->
              <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Nombre del plan *</label>
                <input v-model="form.nombre" type="text" class="input" placeholder="Ej: Plan Básico" required />
              </div>

              <!-- Velocidades -->
              <div class="grid grid-cols-2 gap-3">
                <div>
                  <label class="block text-sm font-medium text-gray-700 mb-1">Bajada (Mbps) *</label>
                  <input v-model.number="form.velocidad_bajada" type="number" class="input" min="1" placeholder="50" required />
                </div>
                <div>
                  <label class="block text-sm font-medium text-gray-700 mb-1">Subida (Mbps) *</label>
                  <input v-model.number="form.velocidad_subida" type="number" class="input" min="1" placeholder="25" required />
                </div>
              </div>

              <!-- Precio -->
              <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Precio mensual (S/) *</label>
                <input v-model.number="form.precio" type="number" class="input" min="0" step="0.01" placeholder="59.90" required />
              </div>

              <!-- Condiciones -->
              <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Condiciones</label>
                <textarea v-model="form.condiciones" class="input" rows="2" maxlength="500" placeholder="Condiciones del servicio (opcional)"></textarea>
              </div>

              <!-- Activo -->
              <label class="flex items-center gap-3 cursor-pointer">
                <div
                  :class="['relative w-10 h-6 rounded-full transition-colors', form.activo ? 'bg-primary' : 'bg-gray-300']"
                  @click="form.activo = !form.activo"
                >
                  <div
                    :class="['absolute top-0.5 w-5 h-5 rounded-full bg-white shadow transition-transform', form.activo ? 'translate-x-4.5' : 'translate-x-0.5']"
                  />
                </div>
                <span class="text-sm text-gray-700">Plan activo</span>
              </label>

              <!-- Actions -->
              <div class="flex gap-3 pt-2">
                <button
                  type="button"
                  @click="showModal = false"
                  class="flex-1 px-4 py-2.5 rounded-xl border border-gray-200 text-sm font-medium text-gray-600 hover:border-gray-300"
                >Cancelar</button>
                <button
                  type="submit"
                  :disabled="saving"
                  class="flex-1 btn-primary"
                >{{ saving ? 'Guardando...' : editingPlan ? 'Actualizar' : 'Crear Plan' }}</button>
              </div>
            </form>
          </div>
        </div>
      </Transition>
    </Teleport>
  </div>
</template>

<style scoped>
.fade-enter-active, .fade-leave-active { transition: opacity 0.2s ease; }
.fade-enter-from, .fade-leave-to { opacity: 0; }
</style>
