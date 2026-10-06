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

const messagePayload = ({ body = "Server body", editableBody = "<p>Canonical edit body</p>", boosts = [], updatedAt = "2023-11-14T22:13:21.000Z" } = {}) => ({
  id: 10,
  client_message_id: "message-10",
  created_at: "2023-11-14T22:13:20.000Z",
  updated_at: updatedAt,
  body: { html: `<p>${body}</p>`, plain_text: body, editable_html: editableBody, truncated: false },
  creator: { id: 2, name: "Fixture User", avatar_url: "" },
  room: { id: 1, name: "Room", type: "Room" },
  url: "/rooms/1/messages/10",
  attachment: null,
  boosts,
  mentions: [],
})

const fixture = `<!doctype html>
<html><head><meta name="csrf-token" content="test-token">
<link rel="stylesheet" href="/assets/boosts-da4032a8.css">
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
  <template x-ref="messageTemplate"><article data-message-id="">
    <a data-stream-part="permalink"></a>
    <a data-stream-part="author-link"><img data-stream-part="avatar"></a>
    <span data-stream-part="author"></span><span data-stream-part="room"></span>
    <button type="button" data-stream-action="edit" data-owner-action>Edit</button>
    <button type="button" data-stream-action="boost" data-boost-content="ship">Boost</button>
    <div data-stream-part="presentation"></div><div data-stream-part="boosts"></div>
    <time data-stream-time="date"></time><time data-stream-time="time"></time>
  </article></template>
  <div x-ref="messages" @click="handleMessageAction($event); handleEditAction($event)" @keydown.enter="handleBoostReveal($event)">
    <article data-message-id="10" data-client-message-id="message-10" data-user-id="2" data-message-timestamp="1700000000000" data-message-updated-at="1700000000000" data-message-url="/rooms/1/messages/10" data-mention-ids="">
      <a data-stream-part="permalink"></a>
      <a data-stream-part="author-link"><img data-stream-part="avatar"></a>
      <span data-stream-part="author">Fixture User</span><span data-stream-part="room">Room</span>
      <button id="edit-trigger" type="button" data-stream-action="edit">Edit</button>
      <button id="boost-trigger" type="button" data-stream-action="boost" data-boost-content="ship">Boost</button>
      <div data-stream-part="presentation"><p>Rendered body</p></div>
      <div data-stream-part="boosts"></div>
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
      response.setHeader("Content-Type", url.pathname.endsWith(".css") ? "text/css" : "text/javascript")
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
  let editSaves = 0
  await userA.route("**/rooms/1/messages/10", async (route) => {
    if (route.request().method() === "POST") {
      editSaves++
      await route.fulfill({
        status: 200,
        contentType: "application/json",
        body: JSON.stringify(messagePayload({ body: "Saved draft", editableBody: "<p>Saved draft</p>", updatedAt: "2023-11-14T22:13:24.000Z" })),
      })
      return
    }
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

  await userA.evaluate(() => {
    const stream = Alpine.$data(document.querySelector("main"))
    const optimistic = document.querySelector("[data-message-id]")
    stream.unindexMessage(optimistic)
    optimistic.dataset.messageId = "0"
    optimistic.dataset.messageUrl = ""
    optimistic.dataset.messageEditableBody = "<p>Optimistic edit body</p>"
    stream.indexMessage(optimistic)
  })
  if (await userA.locator('[data-message-id="0"][data-client-message-id="message-10"]').count() !== 1) {
    throw new Error("Race fixture did not start from the indexed optimistic row")
  }
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
  await keyboardEditor.evaluate((editor) => { editor.value = "<p>Unsaved race draft</p>" })
  await userA.evaluate(async (message) => {
    const stream = Alpine.$data(document.querySelector("main"))
    globalThis.raceEditor = document.querySelector("[data-message-id] lexxy-editor")
    globalThis.raceEditable = globalThis.raceEditor.querySelector('[contenteditable="true"]')
    await stream.receiveMessage(message)
  }, messagePayload({ body: "Reconciled server body", updatedAt: "2023-11-14T22:13:22.000Z" }))
  const reconciliationState = await userA.evaluate(() => ({
    sameEditor: document.querySelector("[data-message-id] lexxy-editor") === globalThis.raceEditor,
    sameEditable: globalThis.raceEditor.querySelector('[contenteditable="true"]') === globalThis.raceEditable,
    editorConnected: globalThis.raceEditor.isConnected,
    editable: globalThis.raceEditable.isContentEditable,
    draft: globalThis.raceEditor.value,
  }))
  if (!reconciliationState.sameEditor || !reconciliationState.sameEditable || !reconciliationState.editorConnected || !reconciliationState.editable) {
    throw new Error("Optimistic reconciliation detached the active Lexxy editor")
  }
  if (!reconciliationState.draft.includes("Unsaved race draft")) throw new Error("Optimistic reconciliation destroyed the edit draft")

  await userA.evaluate((boost) => {
    const stream = Alpine.$data(document.querySelector("main"))
    stream.addBoost(10, boost)
    stream.removeBoost(10, boost.id)
  }, { id: 21, content: "safe", booster: { id: 2, name: "Fixture User", avatar_url: "" } })
  if (!await keyboardEditor.evaluate((editor) => editor.isConnected && editor.value.includes("Unsaved race draft"))) {
    throw new Error("Boost updates disturbed the active Lexxy editor")
  }
  await keyboardEditor.locator('[contenteditable="true"]').press("Control+Enter")
  if (await userA.evaluate(() => globalThis.saveClicks) !== 1) throw new Error("Ctrl+Enter did not activate edit save")
  await userA.locator('[data-message-id] lexxy-editor').waitFor({ state: "detached" })
  await userA.locator('[data-message-id] [data-stream-part="presentation"]', { hasText: "Saved draft" }).waitFor()
  if (editSaves !== 1) throw new Error(`Edit save issued ${editSaves} requests`)

  await userA.evaluate(async () => {
    await Alpine.$data(document.querySelector("main")).startEdit(document.querySelector("[data-message-id]"))
  })
  const convergenceEditor = userA.locator('[data-message-id] lexxy-editor[connected]')
  await convergenceEditor.locator('[contenteditable="true"]').waitFor()
  await convergenceEditor.evaluate((editor) => { editor.value = "<p>Reconnect draft</p>" })
  await userA.evaluate(async (message) => {
    const stream = Alpine.$data(document.querySelector("main"))
    globalThis.convergenceEditor = document.querySelector("[data-message-id] lexxy-editor")
    await stream.replaceCurrentWindow([message])
  }, messagePayload({ body: "Reconnect server body", updatedAt: "2023-11-14T22:13:25.000Z" }))
  if (!await userA.evaluate(() => globalThis.convergenceEditor.isConnected && globalThis.convergenceEditor.value.includes("Reconnect draft"))) {
    throw new Error("Reconnect convergence detached the active Lexxy editor")
  }
  await userA.locator('[data-stream-action="cancel-edit"]').click()
  await userA.locator('[data-message-id] [data-stream-part="presentation"]', { hasText: "Reconnect server body" }).waitFor()

  await userA.evaluate(async () => {
    await Alpine.$data(document.querySelector("main")).startEdit(document.querySelector("[data-message-id]"))
  })
  const deletingEditor = userA.locator('[data-message-id] lexxy-editor[connected]')
  await deletingEditor.locator('[contenteditable="true"]').waitFor()
  await userA.evaluate(() => {
    const stream = Alpine.$data(document.querySelector("main"))
    globalThis.deletingEditor = document.querySelector("[data-message-id] lexxy-editor")
    stream.removeMessage({ id: 10, client_message_id: "message-10" })
  })
  if (!await userA.evaluate(() => globalThis.deletingEditor.isConnected && globalThis.deletingEditor.querySelector('[contenteditable="true"]')?.isContentEditable)) {
    throw new Error("Delete update detached the active Lexxy editor before edit closed")
  }
  await userA.locator('[data-stream-action="cancel-edit"]').click()
  await userA.locator('[data-message-id="10"]').waitFor({ state: "detached" })

  await userA.evaluate((message) => Alpine.$data(document.querySelector("main")).upsertMessage(message), messagePayload())

  const createdBoost = { id: 20, content: "ship", booster: { id: 1, name: "Fixture User", avatar_url: "" } }
  await userA.route("**/messages/10/boosts**", async (route) => {
    const event = route.request().method() === "DELETE" ? ".boost.removed" : ".boost.added"
    const payload = { message_id: 10, boost: createdBoost }
    await Promise.all([
      userA.evaluate(({ event, payload }) => globalThis.__emit("rooms.1", event, payload), { event, payload }),
      userB.evaluate(({ event, payload }) => globalThis.__emit("rooms.1", event, payload), { event, payload }),
    ])
    if (event === ".boost.removed") await route.fulfill({ status: 204 })
    else await route.fulfill({ status: 201, contentType: "application/json", body: JSON.stringify(payload) })
  })
  await userA.locator('[data-stream-action="boost"]').click()
  const localBoost = userA.locator('[data-boost-id="20"]')
  const remoteBoost = userB.locator('[data-boost-id="20"]')
  await Promise.all([localBoost.waitFor(), remoteBoost.waitFor()])
  if (await userA.locator('[data-boost-id="20"]').count() !== 1) throw new Error("Boost add did not reconcile to one local row")
  const boostContent = localBoost.locator('[data-stream-action="reveal-boost"]')
  if (await boostContent.getAttribute("tabindex") !== "0") throw new Error("Owner boost content is not keyboard-focusable")
  if (await boostContent.getAttribute("aria-describedby") !== "delete_boost_accessible_label") throw new Error("Owner boost content lost its accessible description")
  const removeBoost = localBoost.locator('[data-stream-action="remove-boost"]')
  if (await removeBoost.isVisible()) throw new Error("Boost removal was visible before reveal")
  await boostContent.click()
  if (!await localBoost.evaluate((boost) => boost.classList.contains("expanded"))) throw new Error("Click did not reveal boost removal")
  if (!await removeBoost.evaluate((button) => button === document.activeElement)) throw new Error("Click reveal did not focus boost removal")
  await boostContent.click()
  if (await removeBoost.isVisible()) throw new Error("Second click did not hide boost removal")
  await boostContent.focus()
  await boostContent.press("Enter")
  if (!await localBoost.evaluate((boost) => boost.classList.contains("expanded"))) throw new Error("Enter did not reveal boost removal")
  await removeBoost.waitFor({ state: "visible" })
  if (!await removeBoost.evaluate((button) => button === document.activeElement)) throw new Error("Boost removal did not receive focus")
  await removeBoost.click()
  await Promise.all([
    localBoost.waitFor({ state: "detached" }),
    remoteBoost.waitFor({ state: "detached" }),
  ])

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
