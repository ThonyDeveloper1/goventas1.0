<script setup>
import { ref } from 'vue'
import Sidebar from '@/components/Sidebar.vue'
import Navbar  from '@/components/Navbar.vue'

const sidebarOpen = ref(false)
</script>

<template>
  <div class="flex h-screen bg-gray-50 overflow-hidden">

    <!-- Mobile overlay -->
    <Transition name="fade">
      <div
        v-if="sidebarOpen"
        class="fixed inset-0 z-20 bg-black/50 lg:hidden"
        @click="sidebarOpen = false"
      />
    </Transition>

    <!-- Sidebar -->
    <Sidebar :open="sidebarOpen" @close="sidebarOpen = false" />

    <!-- Main -->
    <div class="flex flex-col flex-1 min-w-0 overflow-hidden">
      <Navbar :sidebar-open="sidebarOpen" @toggle-sidebar="sidebarOpen = !sidebarOpen" />

      <main class="flex-1 overflow-y-auto p-4 md:p-6">
        <RouterView v-slot="{ Component }">
          <Transition name="page" mode="out-in">
            <component :is="Component" />
          </Transition>
        </RouterView>
      </main>
    </div>
  </div>
</template>

<style scoped>
.fade-enter-active, .fade-leave-active { transition: opacity 0.2s; }
.fade-enter-from, .fade-leave-to        { opacity: 0; }

.page-enter-active, .page-leave-active { transition: opacity 0.12s ease, transform 0.12s ease; }
.page-enter-from  { opacity: 0; transform: translateY(6px); }
.page-leave-to    { opacity: 0; }
</style>
