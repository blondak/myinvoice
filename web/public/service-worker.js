const STATIC_CACHE_PREFIX = 'myinvoice-static-'
const STATIC_CACHE = `${STATIC_CACHE_PREFIX}v1`
const STATIC_DESTINATIONS = new Set(['font', 'image', 'script', 'style'])

function isApiRequest(url) {
  return url.pathname === '/api' || url.pathname.startsWith('/api/')
}

function isCacheableAsset(request, url) {
  if (!STATIC_DESTINATIONS.has(request.destination)) return false

  return url.pathname.startsWith('/assets/')
    || url.pathname.startsWith('/pwa/')
    || url.pathname.startsWith('/styles/')
}

async function cacheFirst(request) {
  const cache = await caches.open(STATIC_CACHE)
  const cached = await cache.match(request)
  if (cached) return cached

  const response = await fetch(request)
  if (response.ok && response.type === 'basic') {
    await cache.put(request, response.clone())
  }
  return response
}

self.addEventListener('install', (event) => {
  event.waitUntil(self.skipWaiting())
})

self.addEventListener('activate', (event) => {
  event.waitUntil((async () => {
    const cacheNames = await caches.keys()
    await Promise.all(
      cacheNames
        .filter((name) => name.startsWith(STATIC_CACHE_PREFIX) && name !== STATIC_CACHE)
        .map((name) => caches.delete(name)),
    )
    await self.clients.claim()
  })())
})

self.addEventListener('fetch', (event) => {
  const { request } = event
  const url = new URL(request.url)

  if (url.origin !== self.location.origin) return

  if (isApiRequest(url)) {
    event.respondWith(fetch(request, { cache: 'no-store' }))
    return
  }

  if (request.method === 'GET' && isCacheableAsset(request, url)) {
    event.respondWith(cacheFirst(request))
  }
})
