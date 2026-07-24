import { defineStore } from 'pinia'
import { ref } from 'vue'
import { authApi, type SessionState } from '@/api/auth'
import { cancelActiveWebAuthnCeremony, getCredential } from '@/security/webauthn'
import { useAuthStore } from './auth'
import { broadcastSessionEvent, subscribeSessionEvents } from '@/security/sessionChannel'

export const useSessionSecurityStore = defineStore('session-security', () => {
  const state = ref<SessionState | null>(null)
  const privacyCurtain = ref(false)
  const busy = ref(false)
  const error = ref('')
  let deadlineTimer: number | null = null

  function clearDeadlineTimer() {
    if (deadlineTimer !== null) {
      window.clearTimeout(deadlineTimer)
      deadlineTimer = null
    }
  }

  function scheduleDeadline(next: SessionState) {
    clearDeadlineTimer()
    if (next.session_state !== 'active' || next.idle_expires_at === null) return
    const remaining = Date.parse(next.idle_expires_at) - Date.parse(next.server_time)
    if (!Number.isFinite(remaining) || remaining <= 0) {
      privacyCurtain.value = true
      void refresh()
      return
    }
    deadlineTimer = window.setTimeout(() => {
      deadlineTimer = null
      privacyCurtain.value = true
      void refresh()
    }, Math.min(remaining, 2_147_000_000))
  }

  function apply(next: SessionState) {
    state.value = next
    error.value = ''
    const auth = useAuthStore()
    auth.setSessionCsrfToken(next.csrf_token)
    auth.setLockedSession(next.session_state === 'locked' ? next : null)
    privacyCurtain.value = next.session_state === 'locked'
    scheduleDeadline(next)
  }

  function markLocked() {
    clearDeadlineTimer()
    cancelActiveWebAuthnCeremony()
    privacyCurtain.value = true
    if (state.value) state.value = { ...state.value, session_state: 'locked' }
  }

  async function refresh(): Promise<boolean> {
    privacyCurtain.value = true
    try {
      const next = await authApi.sessionStatus()
      const auth = useAuthStore()
      if (next.session_state === 'active' && !auth.profileHydrated) {
        auth.setSessionCsrfToken(next.csrf_token)
        if (!await auth.refresh()) {
          error.value = 'session_status_failed'
          return false
        }
        if (!auth.profileHydrated) {
          if (auth.lockedSession) apply(auth.lockedSession)
          error.value = 'session_status_failed'
          return false
        }
      }
      apply(next)
      return true
    } catch {
      error.value = 'session_status_failed'
      clearDeadlineTimer()
      return false
    }
  }

  async function recordActivity() {
    if (document.visibilityState !== 'visible'
      || state.value?.session_state === 'locked'
      || state.value?.lock_after_minutes === 0
    ) return
    try {
      apply(await authApi.sessionActivity())
    } catch {
      // 423 zpracuje interceptor; síťový výpadek nesmí lokálně odemknout.
    }
  }

  async function lock() {
    apply(await authApi.sessionLock())
    broadcastSessionEvent('locked')
  }

  async function unlock() {
    busy.value = true
    error.value = ''
    try {
      const flow = await authApi.sessionUnlockOptions()
      const credential = await getCredential(flow.public_key)
      const unlocked = await authApi.sessionUnlockVerify(flow.flow_token, credential)
      const auth = useAuthStore()
      auth.setSessionCsrfToken(unlocked.csrf_token)
      if (!await auth.refresh() || !auth.profileHydrated) {
        throw new Error('session_restore_failed')
      }
      apply(unlocked)
      broadcastSessionEvent('unlocked')
    } catch (e: any) {
      error.value = e?.response?.data?.error?.message || 'unlock_failed'
      markLocked()
    } finally {
      busy.value = false
    }
  }

  subscribeSessionEvents((type) => {
    if (type === 'locked') {
      markLocked()
    } else if (type === 'unlocked') {
      privacyCurtain.value = true
      void refresh()
    } else {
      clearDeadlineTimer()
      window.location.href = '/login'
    }
  })

  return { state, privacyCurtain, busy, error, apply, markLocked, refresh, recordActivity, lock, unlock }
})
