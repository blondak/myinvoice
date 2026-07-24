import assert from 'node:assert/strict'
import { fileURLToPath } from 'node:url'
import test from 'node:test'
import { transform as parseCss } from 'lightningcss'
import { createServer } from 'vite'

const webRoot = fileURLToPath(new URL('../', import.meta.url))
const configFile = fileURLToPath(new URL('../vite.config.ts', import.meta.url))

function containsNode(value, predicate) {
  if (predicate(value)) {
    return true
  }

  if (Array.isArray(value)) {
    return value.some((item) => containsNode(item, predicate))
  }

  if (value && typeof value === 'object') {
    return Object.values(value).some((item) => containsNode(item, predicate))
  }

  return false
}

function containsLgBreakpoint(query) {
  return containsNode(query, (node) => (
    node?.type === 'range'
    && node.name === 'width'
    && node.operator === 'greater-than-equal'
    && node.value?.type === 'length'
    && node.value.value?.type === 'value'
    && node.value.value.value?.unit === 'rem'
    && node.value.value.value.value === 64
  ))
}

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
  const mediaStack = []
  let hasResponsiveLgHidden = false

  parseCss({
    code: Buffer.from(css),
    visitor: {
      Rule: {
        media(rule) {
          mediaStack.push(containsLgBreakpoint(rule.value.query))
        },
        style(rule) {
          const isLgHidden = containsNode(
            rule.value.selectors,
            (node) => node?.type === 'class' && node.name === 'lg:hidden',
          )
          const hidesElement = containsNode(
            rule.value.declarations,
            (node) => (
              node?.property === 'display'
              && node.value?.type === 'keyword'
              && node.value.value === 'none'
            ),
          )

          if (isLgHidden && hidesElement && mediaStack.some(Boolean)) {
            hasResponsiveLgHidden = true
          }
        },
      },
      RuleExit: {
        media() {
          mediaStack.pop()
        },
      },
    },
  })

  assert.equal(hasResponsiveLgHidden, true)
  assert.doesNotMatch(css, /\.lg\\:hidden\s*\{\s*@media/)
})
