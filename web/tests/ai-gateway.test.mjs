/**
 * Voice's AI runs through AI Pulse, server to server, and nowhere else.
 *
 * The browser never talks to a model provider and never holds a model key: it
 * asks Voice's own API, which asks AI Pulse with the user's session. Pulse's
 * gateway is not callable from a page at all (its product headers are not
 * CORS-allowed), so a call to it from here would be a bug as well as a leak.
 *
 * Run with: npm run test:ui
 */

import { strict as assert } from 'node:assert'
import { readFileSync, readdirSync, statSync } from 'node:fs'
import { dirname, join, relative } from 'node:path'
import { test } from 'node:test'
import { fileURLToPath } from 'node:url'

const web = join(dirname(fileURLToPath(import.meta.url)), '..')

function sources(dir) {
  return readdirSync(dir).flatMap((name) => {
    const path = join(dir, name)
    if (statSync(path).isDirectory()) return sources(path)
    return /\.(ts|tsx|js|mjs|css)$/.test(name) ? [path] : []
  })
}

const files = [
  ...sources(join(web, 'src')),
  join(web, 'index.html'),
  join(web, 'package.json'),
  join(web, '..', '.env.example'),
]

const forbidden = [
  'generativelanguage.googleapis.com', 'api.openai.com', 'api.anthropic.com', 'x-goog-api-key',
  '@google/generative-ai', '@google/genai', '@anthropic-ai/', 'openai', 'anthropic', 'gemini',
  'gemini_api_key', 'openai_api_key', 'anthropic_api_key', '_ai_api_key', '_ai_model',
  // Pulse's gateway is server to server only.
  '/api/ai/v1/', 'x-pulse-product', 'x-pulse-service-key',
]

test('no model provider, model key or direct gateway call is in the app', () => {
  assert.ok(files.length > 40, `expected to read the whole app, read ${files.length} files`)

  const found = []
  for (const file of files) {
    const text = readFileSync(file, 'utf8').toLowerCase()
    for (const needle of forbidden) {
      if (text.includes(needle)) found.push(`${relative(web, file)} contains ${needle}`)
    }
  }

  assert.deepEqual(found, [])
})
