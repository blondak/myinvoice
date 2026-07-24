import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import test from 'node:test'

const root = new URL('../src/', import.meta.url)
const polling = await readFile(new URL('composables/useSessionAwarePolling.ts', root), 'utf8')
const webauthn = await readFile(new URL('security/webauthn.ts', root), 'utf8')
const sessionSecurity = await readFile(new URL('stores/sessionSecurity.ts', root), 'utf8')
const app = await readFile(new URL('App.vue', root), 'utf8')
const lockOverlay = await readFile(new URL('components/SessionLockOverlay.vue', root), 'utf8')

test('session-aware polling stops for hidden or covered private UI and aborts in-flight work', () => {
  assert.match(polling, /document\.visibilityState === 'visible'/)
  assert.match(polling, /!security\.privacyCurtain/)
  assert.match(polling, /new AbortController\(\)/)
  assert.match(polling, /controller\?\.abort\(\)/)
})

test('locking cancels an active WebAuthn ceremony', () => {
  assert.match(webauthn, /signal:\s*controller\.signal/)
  assert.match(webauthn, /activeCeremony\?\.abort\(\)/)
  assert.match(sessionSecurity, /cancelActiveWebAuthnCeremony\(\)/)
})

test('cold-start lock keeps the private route unmounted until full profile hydration', () => {
  assert.match(app, /RouterView v-if="showRoutedContent"/)
  assert.match(sessionSecurity, /await auth\.refresh\(\)/)
  assert.match(sessionSecurity, /!auth\.profileHydrated/)
})

test('disabled automatic lock does not emit activity heartbeats', () => {
  assert.match(sessionSecurity, /state\.value\?\.lock_after_minutes === 0/)
  assert.match(lockOverlay, /automaticLockEnabled/)
  assert.match(lockOverlay, /!automaticLockEnabled\.value/)
})

test('known private pollers use the shared lifecycle helper instead of raw intervals', async () => {
  const pages = [
    'pages/admin/Integrations.vue',
    'pages/admin/CronJobs.vue',
    'pages/admin/Update.vue',
    'pages/documents/DocumentsBrowser.vue',
    'pages/reports/MonthlyExportReport.vue',
  ]

  for (const page of pages) {
    const source = await readFile(new URL(page, root), 'utf8')
    assert.match(source, /useSessionAwarePolling/)
    assert.doesNotMatch(source, /setInterval\s*\(/)
  }
})
