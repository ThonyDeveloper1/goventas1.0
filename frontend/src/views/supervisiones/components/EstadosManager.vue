<script setup>
import { ref, onMounted } from 'vue'
import { useSupervisionsStore } from '@/store/supervisions'
import supervisionsApi from '@/services/supervisions'

const store = useSupervisionsStore()

/* ── Form state ────────────────────────────────────────── */
const showForm  = ref(false)
const editing   = ref(null)   // null = create, object = editing
const form      = ref(defaultForm())
const formError = ref('')
const saving    = ref(false)
const deleteTarget  = ref(null)
const deleteLoading = ref(false)
function defaultForm() {
  return { nombre: '', color: '#3B82F6', descripcion: '', orden: 0, activo: true }
}

onMounted(() => store.fetchEstados())

/* ── Open form ─────────────────────────────────────────── */
function openCreate() {
  editing.value = null
  form.value    = defaultForm()
  formError.value = ''
  showForm.value  = true
}

function openEdit(estado) {
  editing.value = estado
  form.value = {
    nombre:      estado.nombre,
    color:       estado.color,
    descripcion: estado.descripcion ?? '',
    orden:       estado.orden,
    activo:      estado.activo,
  }
  formError.value = ''
  showForm.value  = true
}

/* ── Save ──────────────────────────────────────────────── */
async function save() {
  formError.value = ''
  saving.value    = true
  try {
    if (editing.value) {
      const { data } = await supervisionsApi.updateEstado(editing.value.id, form.value)
      const idx = store.estados.findIndex((e) => e.id === editing.value.id)
      if (idx !== -1) store.estados[idx] = data.data
    } else {
      await supervisionsApi.createEstado(form.value)
      await store.fetchEstados()
    }
    showForm.value = false
  } catch (e) {
    const errs = e.response?.data?.errors
    formError.value = errs
      ? Object.values(errs).flat().join(' ')
      : e.response?.data?.message || 'Error al guardar.'
  } finally {
    saving.value = false
  }
}

/* ── Delete ────────────────────────────────────────────── */
async function confirmDelete() {
  deleteLoading.value = true
  try {
    await supervisionsApi.deleteEstado(deleteTarget.value.id)
    store.estados = store.estados.filter((e) => e.id !== deleteTarget.value.id)
    deleteTarget.value = null
  } catch (e) {
    alert(e.response?.data?.message || 'No se pudo eliminar.')
  } finally {
    deleteLoading.value = false
  }
}
</script>

<template>
  <div>
    <!-- Header -->
    <div class="flex items-center justify-between mb-4">
      <div>
        <p class="text-sm text-gray-500">{{ store.estados.length }} estado(s) configurados</p>
      </div>
      <button @click="openCreate" class="btn-primary text-sm flex items-center gap-1.5">
        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
        </svg>
        Nuevo estado
      </button>
    </div>

    <!-- List -->
    <div v-if="!store.estados.length" class="text-center py-10 text-gray-400 text-sm">
      No hay estados configurados.
    </div>
    <div v-else class="space-y-2">
      <div
        v-for="estado in store.estados"
        :key="estado.id"
        class="flex items-center gap-3 bg-white border border-gray-100 rounded-xl px-4 py-3 shadow-sm"
      >
        <!-- Color swatch -->
        <span
          class="w-8 h-8 rounded-lg flex-shrink-0 border border-white shadow-sm"
          :style="{ backgroundColor: estado.color }"
        />

        <!-- Info -->
        <div class="flex-1 min-w-0">
          <div class="flex items-center gap-2 flex-wrap">
            <span class="font-semibold text-gray-800 text-sm">{{ estado.nombre }}</span>
            <span
              class="px-2 py-0.5 rounded-full text-xs font-medium"
              :style="{ backgroundColor: estado.color + '22', color: estado.color }"
            >
              {{ estado.color }}
            </span>
            <span v-if="!estado.activo" class="px-2 py-0.5 rounded-full text-xs bg-gray-100 text-gray-400">Inactivo</span>
          </div>
          <p v-if="estado.descripcion" class="text-xs text-gray-400 mt-0.5 truncate">{{ estado.descripcion }}</p>
        </div>

        <!-- Orden -->
        <span class="text-xs text-gray-400 tabular-nums w-6 text-center">{{ estado.orden }}</span>

        <!-- Actions -->
        <div class="flex items-center gap-1">
          <button
            @click="openEdit(estado)"
            class="p-1.5 rounded-lg text-gray-400 hover:text-gray-700 hover:bg-gray-100 transition-colors"
            title="Editar"
          >
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
            </svg>
          </button>
          <button
            @click="deleteTarget = estado"
            class="p-1.5 rounded-lg text-gray-400 hover:text-red-600 hover:bg-red-50 transition-colors"
            title="Eliminar"
          >
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
            </svg>
          </button>
        </div>
      </div>
    </div>

    <!-- ── Form Modal ─────────────────────────────────────── -->
    <Transition name="fade">
      <div
        v-if="showForm"
        class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4"
        @click.self="showForm = false"
      >
        <div class="bg-white rounded-2xl shadow-xl w-full max-w-sm p-6">
          <h3 class="font-semibold text-gray-900 mb-4">
            {{ editing ? 'Editar estado' : 'Nuevo estado' }}
          </h3>

          <div v-if="formError" class="bg-red-50 text-red-600 text-xs rounded-xl px-3 py-2 mb-3">{{ formError }}</div>

          <div class="space-y-3">
            <div>
              <label class="block text-xs font-medium text-gray-500 mb-1">Nombre *</label>
              <input v-model="form.nombre" type="text" class="input text-sm w-full" placeholder="Ej. En revisión" maxlength="100"/>
            </div>
            <div>
              <label class="block text-xs font-medium text-gray-500 mb-1">Color *</label>
              <div class="flex items-center gap-2">
                <input type="color" v-model="form.color" class="w-12 h-9 rounded-lg border border-gray-200 cursor-pointer p-0.5"/>
                <input v-model="form.color" type="text" class="input text-sm flex-1 font-mono" placeholder="#3B82F6" maxlength="7"/>
              </div>
            </div>
            <div>
              <label class="block text-xs font-medium text-gray-500 mb-1">Descripción</label>
              <input v-model="form.descripcion" type="text" class="input text-sm w-full" placeholder="Descripción breve" maxlength="500"/>
            </div>
            <div class="flex gap-3">
              <div class="flex-1">
                <label class="block text-xs font-medium text-gray-500 mb-1">Orden</label>
                <input v-model.number="form.orden" type="number" class="input text-sm w-full" min="0"/>
              </div>
              <div class="flex items-end pb-1">
                <label class="flex items-center gap-2 text-sm text-gray-600 cursor-pointer h-9">
                  <input type="checkbox" v-model="form.activo" class="rounded accent-primary"/>
                  Activo
                </label>
              </div>
            </div>
          </div>

          <div class="flex gap-2 justify-end mt-5">
            <button @click="showForm = false" class="btn-ghost text-sm px-4">Cancelar</button>
            <button
              @click="save"
              :disabled="!form.nombre.trim() || saving"
              class="btn-primary text-sm px-4 flex items-center gap-1.5"
            >
              <svg v-if="saving" class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
              </svg>
              <span v-else>Guardar</span>
            </button>
          </div>
        </div>
      </div>
    </Transition>

    <!-- ── Delete confirm ─────────────────────────────────── -->
    <Transition name="fade">
      <div
        v-if="deleteTarget"
        class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4"
        @click.self="deleteTarget = null"
      >
        <div class="bg-white rounded-2xl shadow-xl w-full max-w-sm p-6 text-center">
          <div class="w-12 h-12 bg-red-100 rounded-2xl flex items-center justify-center mx-auto mb-3">
            <svg class="w-6 h-6 text-red-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/>
            </svg>
          </div>
          <h3 class="font-semibold text-gray-900 mb-1">Eliminar estado</h3>
          <p class="text-sm text-gray-500 mb-4">¿Eliminar <strong>{{ deleteTarget.nombre }}</strong>? No se puede deshacer.</p>
          <div class="flex gap-2 justify-center">
            <button @click="deleteTarget = null" class="btn-ghost text-sm px-4">Cancelar</button>
            <button
              @click="confirmDelete"
              :disabled="deleteLoading"
              class="bg-red-500 hover:bg-red-600 text-white text-sm font-semibold px-4 py-2 rounded-xl transition-colors flex items-center gap-1.5"
            >
              <svg v-if="deleteLoading" class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
              </svg>
              <span v-else>Eliminar</span>
            </button>
          </div>
        </div>
      </div>
    </Transition>
  </div>
</template>

<style scoped>
.fade-enter-active, .fade-leave-active { transition: opacity .2s; }
.fade-enter-from, .fade-leave-to { opacity: 0; }
</style>
