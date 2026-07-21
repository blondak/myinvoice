import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import test from 'node:test'
import vm from 'node:vm'

const source = await readFile(new URL('../public/service-worker.js', import.meta.url), 'utf8')

function createWorker() {
  const listeners = new Map()
  const fetchCalls = []
  const cachePutCalls = []
  let cacheOpenCount = 0

  const response = {
    ok: true,
    type: 'basic',
    clone: () => response,
  }

  const context = {
    URL,
    console,
    fetch: async (...args) => {
      fetchCalls.push(args)
      return response
    },
    caches: {
      keys: async () => [],
      delete: async () => true,
      open: async () => {
        cacheOpenCount += 1
        return {
          match: async () => undefined,
          put: async (...args) => cachePutCalls.push(args),
        }
      },
    },
    self: {
      location: { origin: 'https://invoice.test' },
      clients: { claim: async () => undefined },
      skipWaiting: async () => undefined,
      addEventListener: (type, listener) => listeners.set(type, listener),
    },
  }

  vm.runInNewContext(source, context)

  return {
    dispatchFetch(request) {
      let responsePromise
      listeners.get('fetch')({
        request,
        respondWith: (promise) => { responsePromise = promise },
      })
      return responsePromise
    },
    fetchCalls,
    cachePutCalls,
    get cacheOpenCount() { return cacheOpenCount },
  }
}

test('API requests always bypass caches', async () => {
  for (const pathname of ['/api', '/api/v1/invoices']) {
    const worker = createWorker()
    const result = worker.dispatchFetch({
      url: `https://invoice.test${pathname}`,
      method: 'GET',
      destination: '',
    })

    assert.ok(result)
    await result
    assert.equal(worker.cacheOpenCount, 0)
    assert.equal(worker.fetchCalls.length, 1)
    assert.equal(worker.fetchCalls[0][1].cache, 'no-store')
  }
})

test('same-origin Vite assets use the static cache', async () => {
  const worker = createWorker()
  const request = {
    url: 'https://invoice.test/assets/app-deadbeef.js',
    method: 'GET',
    destination: 'script',
  }

  await worker.dispatchFetch(request)

  assert.equal(worker.cacheOpenCount, 1)
  assert.equal(worker.fetchCalls.length, 1)
  assert.equal(worker.cachePutCalls.length, 1)
  assert.equal(worker.cachePutCalls[0][0], request)
})

test('HTML navigation stays on the network without service-worker caching', () => {
  const worker = createWorker()
  const result = worker.dispatchFetch({
    url: 'https://invoice.test/invoices',
    method: 'GET',
    destination: 'document',
  })

  assert.equal(result, undefined)
  assert.equal(worker.cacheOpenCount, 0)
  assert.equal(worker.fetchCalls.length, 0)
})
