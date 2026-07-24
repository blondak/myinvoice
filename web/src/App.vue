<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { RouterView, useRoute } from 'vue-router'
import Toaster from '@/components/Toaster.vue'
import SessionLockOverlay from '@/components/SessionLockOverlay.vue'
import { useTheme } from '@/composables/useTheme'
import { useAuthStore } from '@/stores/auth'
import { useSessionSecurityStore } from '@/stores/sessionSecurity'

// Inicializace barevného režimu (System/Light/Dark) — aplikuje .dark na <html>.
useTheme()
const route = useRoute()
const auth = useAuthStore()
const security = useSessionSecurityStore()
const protectedPrivateRoute = computed(() => route.matched.some(record => record.meta.requiresAuth)
  && route.name !== 'setup-mfa')
const privateContentCovered = computed(() => security.privacyCurtain && protectedPrivateRoute.value)
const privateTreeMounted = ref(security.state?.session_state === 'active')
const showRoutedContent = computed(() => !protectedPrivateRoute.value || privateTreeMounted.value)

watch(
  [() => auth.isAuthenticated, () => security.state],
  ([authenticated, session]) => {
    if (!authenticated) privateTreeMounted.value = false
    else if (session?.session_state === 'active') privateTreeMounted.value = true
  },
  { immediate: true },
)

watch(protectedPrivateRoute, isProtected => {
  if (!isProtected) privateTreeMounted.value = false
})
</script>

<template>
  <div :inert="privateContentCovered" :aria-hidden="privateContentCovered ? 'true' : undefined">
    <RouterView v-if="showRoutedContent" />
    <Toaster />
  </div>
  <SessionLockOverlay />
</template>
