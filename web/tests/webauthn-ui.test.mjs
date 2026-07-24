import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import test from 'node:test'

const root = new URL('../src/', import.meta.url)

test('passkey entry points use the shared WebAuthn capability check', async () => {
  const pages = [
    'pages/Login.vue',
    'pages/ApiTokens.vue',
    'pages/Passkeys.vue',
    'pages/ForcedMfaSetup.vue',
    'components/SessionLockOverlay.vue',
  ]

  for (const page of pages) {
    const source = await readFile(new URL(page, root), 'utf8')
    assert.match(source, /isWebAuthnAvailable/)
    assert.match(source, /passkeySupported/)
  }
})

test('login and locked-session UI expose a recovery message when WebAuthn is unavailable', async () => {
  const login = await readFile(new URL('pages/Login.vue', root), 'utf8')
  const overlay = await readFile(new URL('components/SessionLockOverlay.vue', root), 'utf8')

  assert.match(login, /passkey_unsupported_recovery/)
  assert.match(overlay, /session_lock\.unsupported/)
})

test('login clears one-time passkey flow for TOTP fallback and after failed verification', async () => {
  const login = await readFile(new URL('pages/Login.vue', root), 'utf8')
  const fallback = login.match(/function useTotpFallback\(\) \{([\s\S]*?)\n\}/)?.[1] || ''
  const verify = login.match(/async function verifyPasskey\(\) \{([\s\S]*?)\n\}/)?.[1] || ''

  assert.match(fallback, /passkeyFlow\.value = null[\s\S]*totpRequired\.value = true/)
  assert.match(verify, /catch[\s\S]*passkeyFlow\.value = null[\s\S]*turnstile\.reset\(\)/)
  assert.match(login, /:disabled="[^"]*!!passkeyFlow/)
})

test('passwordless login asks for discoverable passkey options before verification', async () => {
  const login = await readFile(new URL('pages/Login.vue', root), 'utf8')
  const authApi = await readFile(new URL('api/auth.ts', root), 'utf8')
  const flow = login.match(/async function loginWithPasskey\(\) \{([\s\S]*?)\n\}/)?.[1] || ''

  assert.match(login, /auth\.setupStatus\?\.passwordless_login_enabled/)
  assert.match(login, /type="button"[\s\S]*?@click="loginWithPasskey"/)
  assert.match(flow, /authApi\.passkeyLoginOptions\(turnstile\.token\.value \|\| undefined\)/)
  assert.match(
    flow,
    /passkeyLoginOptions[\s\S]*getCredential\(flow\.public_key\)[\s\S]*passkeyLoginVerify\(flow\.flow_token, credential\)[\s\S]*auth\.refresh\(\)/,
  )
  assert.match(authApi, /post<WebAuthnFlow>\('\/auth\/webauthn\/login\/options'/)
})

test('security management renders inside the shared profile tabs', async () => {
  const profile = await readFile(new URL('pages/PasswordChange.vue', root), 'utf8')
  const router = await readFile(new URL('router/index.ts', root), 'utf8')
  const authApi = await readFile(new URL('api/auth.ts', root), 'utf8')

  assert.match(profile, /type Tab = 'password' \| 'totp' \| 'passkeys' \| 'session-lock'/)
  assert.match(profile, /<Passkeys v-else-if="tab === 'passkeys'" \/>/)
  assert.match(profile, /sessionSecurity\.apply\(result\.session\)/)
  assert.match(profile, /<select[\s\S]*?class="sm:hidden/)
  assert.match(profile, /class="hidden sm:flex border-b/)
  assert.match(router, /profile\/passkeys[\s\S]+tab: 'passkeys'/)
  assert.match(router, /profile\/session-lock[\s\S]+tab: 'session-lock'/)
  assert.match(authApi, /put<SessionLockPreferenceUpdate>\('\/auth\/session\/lock-preference'/)
})

test('passkey management fields have visible associated labels', async () => {
  const passkeys = await readFile(new URL('pages/Passkeys.vue', root), 'utf8')

  assert.match(passkeys, /<label for="passkey-label"[\s\S]*?passkeys\.credential_label/)
  assert.match(passkeys, /<input id="passkey-label"/)
  assert.match(passkeys, /<label for="passkey-current-password"[\s\S]*?auth\.current_password/)
  assert.match(passkeys, /<input id="passkey-current-password"/)
  assert.match(passkeys, /<label for="passkey-totp"[\s\S]*?passkeys\.totp_label/)
  assert.match(passkeys, /<input id="passkey-totp"/)
})

test('passkey changes refresh the shared user profile independently of CSRF rotation', async () => {
  const passkeys = await readFile(new URL('pages/Passkeys.vue', root), 'utf8')
  const add = passkeys.match(/async function add\(\) \{([\s\S]*?)\n\}/)?.[1] || ''
  const revoke = passkeys.match(/async function revoke\(item: PasskeyCredential\) \{([\s\S]*?)\n\}/)?.[1] || ''

  assert.match(
    add,
    /if \(registered\.csrf_token\) \{[\s\S]*?auth\.setSessionCsrfToken\(registered\.csrf_token\)[\s\S]*?\}\s*await auth\.refresh\(\)/,
  )
  assert.match(revoke, /passkeyRevoke[\s\S]*?await auth\.refresh\(\)[\s\S]*?await load\(\)/)
})

test('forced passkey setup uses existing TOTP as transition step-up', async () => {
  const setup = await readFile(new URL('pages/ForcedMfaSetup.vue', root), 'utf8')

  assert.match(setup, /auth\.user\?\.totp_enabled/)
  assert.match(setup, /totpStepUp\('passkey\.register'/)
  assert.match(setup, /authorization\.step_up_token/)
  assert.match(setup, /authorization\.current_password/)
})
