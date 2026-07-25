<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { authApi, type PasskeyCredential } from '@/api/auth'
import { createCredential, getCredential, isWebAuthnAvailable } from '@/security/webauthn'
import { useAuthStore } from '@/stores/auth'
import { useSessionSecurityStore } from '@/stores/sessionSecurity'

const { t } = useI18n()
const auth = useAuthStore()
const sessionSecurity = useSessionSecurityStore()
const list = ref<PasskeyCredential[]>([])
const label = ref('')
const currentPassword = ref('')
const totpCode = ref('')
const busy = ref(false)
const error = ref('')
const passkeySupported = isWebAuthnAvailable()

async function load() {
  list.value = await authApi.passkeys()
}

async function stepUp(operation: string): Promise<string> {
  if (/^\d{6}$/.test(totpCode.value)) {
    return authApi.totpStepUp(operation, totpCode.value)
  }
  const flow = await authApi.passkeyStepUpOptions(operation)
  const credential = await getCredential(flow.public_key)
  return authApi.passkeyStepUpVerify(flow.flow_token, operation, credential)
}

async function add() {
  if (!label.value.trim() || !passkeySupported) return
  busy.value = true
  error.value = ''
  try {
    const authorization: { current_password?: string; step_up_token?: string } = {}
    if (list.value.length > 0 || auth.user?.totp_enabled) {
      authorization.step_up_token = await stepUp('passkey.register')
    } else {
      authorization.current_password = currentPassword.value
    }
    const flow = await authApi.passkeyRegisterOptions(authorization)
    const credential = await createCredential(flow.public_key)
    const registered = await authApi.passkeyRegisterVerify(flow.flow_token, credential, label.value.trim())
    if (registered.csrf_token) {
      auth.setSessionCsrfToken(registered.csrf_token)
    }
    await auth.refresh()
    await sessionSecurity.refresh({ force: true })
    label.value = ''
    currentPassword.value = ''
    totpCode.value = ''
    await load()
  } catch (e: any) {
    error.value = e?.response?.data?.error?.message || t('passkeys.operation_failed')
  } finally {
    busy.value = false
  }
}

async function rename(item: PasskeyCredential) {
  const next = window.prompt(t('passkeys.rename_prompt'), item.label)?.trim()
  if (!next || next === item.label) return
  try {
    await authApi.passkeyRename(item.id, next)
    await load()
  } catch (e: any) {
    error.value = e?.response?.data?.error?.message || t('passkeys.operation_failed')
  }
}

async function revoke(item: PasskeyCredential) {
  if (!window.confirm(t('passkeys.revoke_confirm', { label: item.label }))) return
  busy.value = true
  error.value = ''
  try {
    const proof = await stepUp(`passkey.revoke:${item.id}`)
    await authApi.passkeyRevoke(item.id, proof)
    totpCode.value = ''
    await auth.refresh()
    await sessionSecurity.refresh({ force: true })
    await load()
  } catch (e: any) {
    error.value = e?.response?.data?.error?.message || t('passkeys.operation_failed')
  } finally {
    busy.value = false
  }
}

onMounted(() => void load())
</script>

<template>
  <div>
    <p class="text-sm text-neutral-500 mb-5">{{ t('passkeys.subtitle') }}</p>
    <p v-if="!passkeySupported"
       class="mb-5 rounded-md bg-warning-50 border border-warning-300 px-3 py-2 text-sm text-warning-700">
      {{ t('passkeys.unsupported') }}
    </p>

    <div class="bg-surface border border-neutral-200 rounded-lg p-5 shadow-sm space-y-3 mb-5">
      <div>
        <label for="passkey-label" class="block text-sm font-medium text-neutral-700 mb-1">
          {{ t('passkeys.credential_label') }} *
        </label>
        <input id="passkey-label" v-model="label" type="text" maxlength="100"
               autocomplete="off" required :placeholder="t('passkeys.label_placeholder')"
               class="w-full h-10 px-3 border border-neutral-300 rounded-md" />
      </div>
      <div v-if="list.length === 0 && !auth.user?.totp_enabled">
        <label for="passkey-current-password" class="block text-sm font-medium text-neutral-700 mb-1">
          {{ t('auth.current_password') }} *
        </label>
        <input id="passkey-current-password" v-model="currentPassword"
               type="password" autocomplete="current-password" required
               class="w-full h-10 px-3 border border-neutral-300 rounded-md" />
      </div>
      <div v-if="auth.user?.totp_enabled">
        <label for="passkey-totp" class="block text-sm font-medium text-neutral-700 mb-1">
          {{ t('passkeys.totp_label') }}
        </label>
        <input id="passkey-totp" v-model="totpCode" type="text" inputmode="numeric"
               autocomplete="one-time-code" maxlength="6" pattern="\d{6}"
               :placeholder="t('passkeys.totp_optional')"
               class="w-full h-10 px-3 border border-neutral-300 rounded-md font-mono" />
      </div>
      <button type="button" @click="add" :disabled="busy || !label.trim() || !passkeySupported"
              class="h-10 px-4 bg-primary-600 hover:bg-primary-700 disabled:bg-neutral-300 text-white rounded-md font-medium">
        {{ t('passkeys.add') }}
      </button>
      <p class="text-xs text-neutral-500">{{ t('passkeys.fresh_auth_hint') }}</p>
    </div>

    <p v-if="error" class="mb-4 rounded-md bg-danger-50 border border-danger-500/40 px-3 py-2 text-sm text-danger-500">{{ error }}</p>
    <div class="space-y-3">
      <div v-for="item in list" :key="item.id"
           class="bg-surface border border-neutral-200 rounded-lg p-4 flex items-center justify-between gap-4">
        <div>
          <div class="font-medium">{{ item.label }}</div>
          <div class="text-xs text-neutral-500 mt-1">
            {{ t('passkeys.created') }} {{ item.created_at?.replace('T', ' ').slice(0, 16) || '—' }}
            · {{ t('passkeys.last_used') }} {{ item.last_used_at?.replace('T', ' ').slice(0, 16) || '—' }}
          </div>
        </div>
        <div class="flex gap-3 text-sm">
          <button @click="rename(item)" class="text-primary-600 hover:underline">{{ t('passkeys.rename') }}</button>
          <button @click="revoke(item)"
                  :disabled="!passkeySupported && !auth.user?.totp_enabled"
                  class="text-danger-500 hover:underline disabled:text-neutral-400 disabled:no-underline">
            {{ t('passkeys.revoke') }}
          </button>
        </div>
      </div>
      <p v-if="list.length === 0" class="text-sm text-neutral-500 text-center py-8">{{ t('passkeys.empty') }}</p>
    </div>
  </div>
</template>
