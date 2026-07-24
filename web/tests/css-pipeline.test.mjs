import assert from 'node:assert/strict'
import { fileURLToPath } from 'node:url'
import test from 'node:test'
import { createServer } from 'vite'

const webRoot = fileURLToPath(new URL('../', import.meta.url))
const configFile = fileURLToPath(new URL('../vite.config.ts', import.meta.url))

test('development CSS flattens Tailwind responsive nesting', { timeout: 30_000 }, async (t) => {
  const server = await createServer({
    root: webRoot,
    configFile,
    logLevel: 'silent',
    server: {
      middlewareMode: true,
      hmr: false,
    },
  })
  t.after(() => server.close())

  const transformed = await server.transformRequest('/src/styles/main.css')
  assert.ok(transformed)

  const encodedCss = transformed.code.match(
    /const __vite__css = (".*")\n__vite__updateStyle/,
  )?.[1]
  assert.ok(encodedCss, 'Vite CSS module did not contain the injected stylesheet')

  const css = JSON.parse(encodedCss)
  assert.match(css, /@media \(width >= 64rem\)\s*\{\s*\.lg\\:hidden\s*\{/)
  assert.doesNotMatch(css, /\.lg\\:hidden\s*\{\s*@media/)
})
