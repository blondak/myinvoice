type JsonObject = Record<string, any>
let activeCeremony: AbortController | null = null

export function isWebAuthnAvailable(): boolean {
  return typeof window !== 'undefined'
    && typeof window.PublicKeyCredential !== 'undefined'
    && typeof navigator.credentials !== 'undefined'
}

export function cancelActiveWebAuthnCeremony(): void {
  activeCeremony?.abort()
  activeCeremony = null
}

function startCeremony(): AbortController {
  cancelActiveWebAuthnCeremony()
  activeCeremony = new AbortController()
  return activeCeremony
}

function finishCeremony(controller: AbortController): void {
  if (activeCeremony === controller) activeCeremony = null
}

export function fromBase64Url(value: string): Uint8Array<ArrayBuffer> {
  const padding = '='.repeat((4 - value.length % 4) % 4)
  const binary = atob(value.replace(/-/g, '+').replace(/_/g, '/') + padding)
  return Uint8Array.from(binary, char => char.charCodeAt(0))
}

export function toBase64Url(value: ArrayBuffer): string {
  const bytes = new Uint8Array(value)
  let binary = ''
  for (const byte of bytes) binary += String.fromCharCode(byte)
  return btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/g, '')
}

export function requestOptionsFromJson(options: JsonObject): PublicKeyCredentialRequestOptions {
  return {
    ...options,
    challenge: fromBase64Url(options.challenge),
    allowCredentials: (options.allowCredentials || []).map((item: JsonObject) => ({
      ...item,
      id: fromBase64Url(item.id),
    })),
  } as PublicKeyCredentialRequestOptions
}

export function creationOptionsFromJson(options: JsonObject): PublicKeyCredentialCreationOptions {
  return {
    ...options,
    challenge: fromBase64Url(options.challenge),
    user: { ...options.user, id: fromBase64Url(options.user.id) },
    excludeCredentials: (options.excludeCredentials || []).map((item: JsonObject) => ({
      ...item,
      id: fromBase64Url(item.id),
    })),
  } as unknown as PublicKeyCredentialCreationOptions
}

export function credentialToJson(credential: PublicKeyCredential): JsonObject {
  const response = credential.response
  const payload: JsonObject = {
    id: credential.id,
    rawId: toBase64Url(credential.rawId),
    type: credential.type,
    authenticatorAttachment: credential.authenticatorAttachment,
    clientExtensionResults: credential.getClientExtensionResults(),
    response: {
      clientDataJSON: toBase64Url(response.clientDataJSON),
    },
  }
  if (response instanceof AuthenticatorAssertionResponse) {
    payload.response.authenticatorData = toBase64Url(response.authenticatorData)
    payload.response.signature = toBase64Url(response.signature)
    payload.response.userHandle = response.userHandle ? toBase64Url(response.userHandle) : null
  } else if (response instanceof AuthenticatorAttestationResponse) {
    payload.response.attestationObject = toBase64Url(response.attestationObject)
    payload.response.transports = response.getTransports()
  }
  return payload
}

export async function getCredential(options: JsonObject): Promise<JsonObject> {
  if (!isWebAuthnAvailable()) throw new Error('webauthn_unavailable')
  const controller = startCeremony()
  try {
    const credential = await navigator.credentials.get({
      publicKey: requestOptionsFromJson(options),
      signal: controller.signal,
    })
    if (!(credential instanceof PublicKeyCredential)) throw new Error('webauthn_cancelled')
    return credentialToJson(credential)
  } finally {
    finishCeremony(controller)
  }
}

export async function createCredential(options: JsonObject): Promise<JsonObject> {
  if (!isWebAuthnAvailable()) throw new Error('webauthn_unavailable')
  const controller = startCeremony()
  try {
    const credential = await navigator.credentials.create({
      publicKey: creationOptionsFromJson(options),
      signal: controller.signal,
    })
    if (!(credential instanceof PublicKeyCredential)) throw new Error('webauthn_cancelled')
    return credentialToJson(credential)
  } finally {
    finishCeremony(controller)
  }
}
