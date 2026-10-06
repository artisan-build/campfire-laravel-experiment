#!/usr/bin/env node

import { createServer } from "node:http"
import { readFileSync } from "node:fs"
import { createRequire } from "node:module"
import { dirname, resolve } from "node:path"
import { fileURLToPath, pathToFileURL } from "node:url"

const require = createRequire(import.meta.url)
const searchPaths = process.env.PR5_PLAYWRIGHT_NODE_MODULES ? [ process.env.PR5_PLAYWRIGHT_NODE_MODULES ] : undefined
const playwrightPath = require.resolve("playwright", searchPaths ? { paths: searchPaths } : undefined)
const playwrightModule = await import(pathToFileURL(playwrightPath))
const { chromium } = playwrightModule.default || playwrightModule
const root = resolve(dirname(fileURLToPath(import.meta.url)), "../..")

const fixture = `<!doctype html>
<html><head><meta name="csrf-token" content="test-token">
<script type="importmap">{"imports":{"alpinejs":"/assets/alpine.esm-f00594ce.js","campfire/echo/config":"/stub-echo.js"}}</script>
<script>
globalThis.IntersectionObserver = class { observe() {} disconnect() {} }
globalThis.__channels = new Map()
const channel = (name) => ({
  listen(event, callback) {
    const key = name + ":" + event
    const listeners = globalThis.__channels.get(key) || []
    listeners.push(callback)
    globalThis.__channels.set(key, listeners)
    return this
  }
})
globalThis.__echo = { private: channel, join: channel, leave() {} }
globalThis.__emit = (name, event, payload) => {
  for (const callback of globalThis.__channels.get(name + ":" + event) || []) callback(payload)
}
</script>
<script type="module">
import "/assets/lexxy-a21f41d4.js"
import "/assets/campfire/message_stream.js"
import Alpine from "alpinejs"
globalThis.Alpine = Alpine
Alpine.start()
globalThis.fixtureReady = true
</script></head><body>
<main x-data="messageStream({ roomId: 1, roomName: 'Room', roomType: 'Room', userId: Number(new URLSearchParams(location.search).get('user')), userName: 'Fixture User', userAvatarUrl: '', isAdmin: false })">
  <template x-ref="messageTemplate"><article data-message-id=""></article></template>
  <div x-ref="messages">
    <article data-message-id="10" data-client-message-id="message-10" data-user-id="2" data-message-timestamp="1700000000000" data-message-updated-at="1700000000000" data-message-url="/rooms/1/messages/10" data-mention-ids="">
      <button id="edit-trigger" type="button" data-stream-action="edit">Edit</button>
      <div data-stream-part="presentation"><p>Rendered body</p></div>
      <time data-stream-time="date"></time><time data-stream-time="time"></time>
    </article>
  </div>
  <button x-ref="latest" hidden></button>
  <form><fieldset x-ref="fields"><lexxy-editor id="message_body" x-ref="editor" @lexxy:change="editorChanged()"></lexxy-editor></fieldset></form>
  <div class="typing-indicator" :class="{ 'typing-indicator--active': typingNames }"><span x-text="typingNames"></span></div>
</main></body></html>`

const stubEcho = `
export const csrfToken = () => "test-token"
export const getEcho = () => globalThis.__echo
export const onConnectionChange = () => () => {}
export const reconnect = () => {}
`

const server = createServer((request, response) => {
  const url = new URL(request.url, "http://fixture.test")
  if (url.pathname === "/") {
    response.setHeader("Content-Type", "text/html")
    response.end(fixture)
    return
  }
  if (url.pathname === "/stub-echo.js") {
    response.setHeader("Content-Type", "text/javascript")
    response.end(stubEcho)
    return
  }
  if (url.pathname.startsWith("/assets/")) {
    try {
      response.setHeader("Content-Type", "text/javascript")
      response.end(readFileSync(resolve(root, "public", url.pathname.slice(1))))
    } catch {
      response.statusCode = 404
      response.end()
    }
    return
  }
  response.statusCode = 404
  response.end()
})

await new Promise((resolve) => server.listen(0, "127.0.0.1", resolve))
const address = server.address()
const origin = `http://127.0.0.1:${address.port}`
const browser = await chromium.launch({
  headless: true,
  executablePath: process.env.PR5_BROWSER_EXECUTABLE || undefined,
})
const context = await browser.newContext()
const userA = await context.newPage()
const userB = await context.newPage()

try {
  let markEditRequestStarted
  let releaseEditRequest
  const editRequestStarted = new Promise((resolve) => { markEditRequestStarted = resolve })
  const editRequestReleased = new Promise((resolve) => { releaseEditRequest = resolve })
  let editRequests = 0
  await userA.route("**/rooms/1/messages/10", async (route) => {
    editRequests++
    if (editRequests === 1) {
      markEditRequestStarted()
      await editRequestReleased
    }
    await route.fulfill({
      status: 200,
      contentType: "application/json",
      body: JSON.stringify({ body: { editable_html: "<p>Canonical edit body</p>" } }),
    })
  })
  await Promise.all([ userA.goto(`${origin}/?user=1`), userB.goto(`${origin}/?user=2`) ])
  await Promise.all([ userA.waitForFunction(() => globalThis.fixtureReady), userB.waitForFunction(() => globalThis.fixtureReady) ])

  await userA.locator("#edit-trigger").focus()
  await userA.evaluate(() => {
    const stream = Alpine.$data(document.querySelector("main"))
    globalThis.pendingEdit = stream.startEdit(document.querySelector("[data-message-id]"))
  })
  await editRequestStarted
  await userA.evaluate(() => {
    const stream = Alpine.$data(document.querySelector("main"))
    const original = document.querySelector("[data-message-id]")
    const replacement = original.cloneNode(true)
    stream.unindexMessage(original)
    original.replaceWith(replacement)
    stream.indexMessage(replacement)
  })
  releaseEditRequest()
  await userA.evaluate(() => globalThis.pendingEdit)
  const editEditor = userA.locator('[data-message-id] lexxy-editor[connected]')
  await editEditor.locator('[contenteditable="true"]').waitFor()
  const editableValue = await editEditor.evaluate((editor) => editor.value)
  if (!editableValue.includes("Canonical edit body")) throw new Error("Connected edit editor did not receive the canonical body")
  await editEditor.locator('[contenteditable="true"]').press("Escape")
  if (await userA.locator("[data-message-id] lexxy-editor").count()) throw new Error("Escape did not cancel editing")
  if (!await userA.locator("#edit-trigger").evaluate((button) => button === document.activeElement)) throw new Error("Escape did not restore edit focus")

  await userA.evaluate(async () => {
    const stream = Alpine.$data(document.querySelector("main"))
    await stream.startEdit(document.querySelector("[data-message-id]"))
  })
  const keyboardEditor = userA.locator('[data-message-id] lexxy-editor[connected]')
  await keyboardEditor.locator('[contenteditable="true"]').waitFor()
  await userA.evaluate(() => {
    globalThis.saveClicks = 0
    document.querySelector('[data-stream-action="save-edit"]').addEventListener("click", () => globalThis.saveClicks++)
  })
  await keyboardEditor.locator('[contenteditable="true"]').press("Control+Enter")
  if (await userA.evaluate(() => globalThis.saveClicks) !== 1) throw new Error("Ctrl+Enter did not activate edit save")

  await userB.route("**/rooms/1/typing", async (route) => {
    const { action } = route.request().postDataJSON()
    const payload = { action, user: { id: 2, name: "User B" } }
    await Promise.all([
      userA.evaluate((event) => globalThis.__emit("rooms.1.typing", ".typing", event), payload),
      userB.evaluate((event) => globalThis.__emit("rooms.1.typing", ".typing", event), payload),
    ])
    await route.fulfill({ status: 204 })
  })
  const setEditor = (value) => userB.locator("#message_body").evaluate((editor, body) => {
    editor.value = body
    editor.dispatchEvent(new CustomEvent("lexxy:change", { bubbles: true }))
  }, value)
  await setEditor("<p>typing</p>")
  await userA.locator(".typing-indicator--active", { hasText: "User B" }).waitFor()
  await setEditor("")
  await userA.locator(".typing-indicator--active").waitFor({ state: "detached" })
  await setEditor("<p>typing again</p>")
  await userA.locator(".typing-indicator--active", { hasText: "User B" }).waitFor()
  await userA.locator(".typing-indicator--active").waitFor({ state: "detached", timeout: 7_000 })

  let pageRequests = 0
  await userA.route("**/rooms/1/messages?before=*", async (route) => {
    pageRequests++
    await route.fulfill({ status: 204 })
  })
  await userA.evaluate(async () => {
    const stream = Alpine.$data(document.querySelector("main"))
    await stream.loadPage("before", "10")
    await stream.loadPage("before", "10")
  })
  if (pageRequests !== 1) throw new Error(`Exhausted edge requested ${pageRequests} times`)
  await userA.evaluate(async () => {
    document.querySelector("[data-message-id]").dataset.messageId = "9"
    await Alpine.$data(document.querySelector("main")).loadPage("before", "9")
  })
  if (pageRequests !== 2) throw new Error("Changed edge did not permit another pagination request")
} finally {
  await browser.close()
  await new Promise((resolve) => server.close(resolve))
}
