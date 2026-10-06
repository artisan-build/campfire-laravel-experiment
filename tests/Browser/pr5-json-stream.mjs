#!/usr/bin/env node

import { createRequire } from "node:module"
import { mkdirSync, writeFileSync } from "node:fs"
import { dirname, resolve } from "node:path"
import { pathToFileURL } from "node:url"

const require = createRequire(import.meta.url)
const searchPaths = process.env.PR5_PLAYWRIGHT_NODE_MODULES ? [ process.env.PR5_PLAYWRIGHT_NODE_MODULES ] : undefined
const playwrightPath = require.resolve("playwright", searchPaths ? { paths: searchPaths } : undefined)
const playwrightModule = await import(pathToFileURL(playwrightPath))
const { chromium } = playwrightModule.default || playwrightModule

const requiredEnvironment = [
  "PR5_BASE_URL",
  "PR5_ROOM_URL",
  "PR5_SECOND_ROOM_URL",
  "PR5_USER_A_EMAIL",
  "PR5_USER_A_PASSWORD",
  "PR5_USER_B_EMAIL",
  "PR5_USER_B_PASSWORD",
  "PR5_IMAGE_PATH",
  "PR5_VIDEO_PATH",
]
const missing = requiredEnvironment.filter((name) => !process.env[name])
if (missing.length > 0) throw new Error(`Missing environment: ${missing.join(", ")}`)

const baseUrl = process.env.PR5_BASE_URL.replace(/\/$/, "")
const roomUrl = new URL(process.env.PR5_ROOM_URL, baseUrl).href
const secondRoomUrl = new URL(process.env.PR5_SECOND_ROOM_URL, baseUrl).href
const outputPath = resolve(process.env.PR5_OUTPUT || "tmp/pr5-live/summary.json")
const expectedEvents = new Set([ "message.posted", "message.updated", "message.deleted", "boost.added", "boost.removed" ])
const forbiddenFrameTerms = [ "turbo-stream", '"gz"', '"oversize"', "csrf", "authenticity_token", "cookie", "session", "password", "authorization" ]
const results = []
const frames = []
const observedEvents = new Set()
const frameViolations = []

function recordFrames(page, label) {
  page.on("websocket", (socket) => {
    socket.on("framereceived", ({ payload }) => inspectFrame(payload, label, "received"))
    socket.on("framesent", ({ payload }) => inspectFrame(payload, label, "sent"))
  })
}

function inspectFrame(payload, context, direction) {
  const text = Buffer.isBuffer(payload) ? payload.toString("utf8") : String(payload)
  let event = null
  try {
    const envelope = JSON.parse(text)
    event = envelope.event || null
    if (event && event.startsWith("App\\Events\\")) event = event.split("\\").pop()
  } catch {}

  if (!expectedEvents.has(event)) return
  observedEvents.add(event)
  frames.push({ context, direction, event, bytes: Buffer.byteLength(text) })
  const lower = text.toLowerCase()
  for (const term of forbiddenFrameTerms) {
    if (lower.includes(term.toLowerCase())) frameViolations.push({ context, direction, event, term })
  }
}

async function check(name, operation) {
  const startedAt = Date.now()
  try {
    const detail = await operation()
    results.push({ name, status: "passed", duration_ms: Date.now() - startedAt, detail: detail || null })
  } catch (error) {
    results.push({ name, status: "failed", duration_ms: Date.now() - startedAt, error: error.message })
  }
}

async function login(page, email, password) {
  await page.goto(`${baseUrl}/session/new`)
  await page.locator('input[name="email_address"]').fill(email)
  await page.locator('input[name="password"]').fill(password)
  await Promise.all([
    page.waitForURL((url) => !url.pathname.startsWith("/session")),
    page.locator('form[action="/session"]').getByRole("button", { name: "Sign in", exact: true }).click(),
  ])
}

function lexxyEditable(scope, editorSelector = "lexxy-editor") {
  return scope.locator(`${editorSelector} [contenteditable="true"]`)
}

async function setEditor(page, value) {
  await lexxyEditable(page, "#message_body").evaluate((editable, body) => {
    const editor = editable.closest("lexxy-editor")
    if (!editor) throw new Error("Lexxy editor host is missing")
    editor.value = body
    editor.dispatchEvent(new CustomEvent("lexxy:change", { bubbles: true }))
  }, value)
}

async function post(page, text, keyboard = false) {
  await setEditor(page, `<p>${text}</p>`)
  if (keyboard) await lexxyEditable(page, "#message_body").press(process.platform === "darwin" ? "Meta+Enter" : "Control+Enter")
  else await page.locator('[data-testid="room-json-composer"] button[type="submit"]').click()
  const optimistic = message(page, text)
  await optimistic.waitFor()
  const optimisticHandle = await optimistic.elementHandle()
  if (!optimisticHandle) throw new Error("Optimistic row disappeared before reconciliation")
  await page.waitForFunction((row) => row.isConnected && Number(row.dataset.messageId) > 0, optimisticHandle, { timeout: 15_000 })
  return messageById(page, await optimisticHandle.getAttribute("data-message-id"))
}

function message(page, text) {
  return page.locator("[data-message-id]", { hasText: text }).last()
}

function messageById(page, id) {
  return page.locator(`[data-message-id="${id}"]`).last()
}

async function edit(page, oldText, newText, keyboard = true) {
  const messageId = await message(page, oldText).getAttribute("data-message-id")
  if (!messageId || messageId === "0") throw new Error(`Cannot edit unstable message id ${messageId || "missing"}`)
  const row = messageById(page, messageId)
  await row.locator(".message__actions > details").evaluate((details) => { details.open = true })
  await row.locator('[data-stream-action="edit"]').click()
  await lexxyEditable(row).evaluate((editable, body) => {
    const editor = editable.closest("lexxy-editor")
    if (!editor) throw new Error("Lexxy editor host is missing")
    editor.value = `<p>${body}</p>`
  }, newText)
  if (keyboard) await lexxyEditable(row).press(process.platform === "darwin" ? "Meta+Enter" : "Control+Enter")
  else await row.locator('[data-stream-action="save-edit"]').click()
  await row.filter({ hasText: newText }).waitFor()
  const editAction = row.locator('[data-stream-action="edit"]')
  const editActionHandle = await editAction.elementHandle()
  if (!editActionHandle) throw new Error("Stable row lost its Edit action")
  await page.waitForFunction((button) => document.activeElement === button, editActionHandle, { timeout: 10_000 })
}

async function remove(page, text) {
  const messageId = await message(page, text).getAttribute("data-message-id")
  if (!messageId || messageId === "0") throw new Error(`Cannot remove unstable message id ${messageId || "missing"}`)
  const row = messageById(page, messageId)
  await row.locator(".message__actions > details").evaluate((details) => { details.open = true })
  page.once("dialog", (dialog) => dialog.accept())
  await row.locator('[data-stream-action="delete"]').click()
  await messageById(page, messageId).waitFor({ state: "detached" })
}

async function reconnect(page, mutate) {
  await page.context().setOffline(true)
  try {
    await mutate()
  } finally {
    await page.context().setOffline(false)
    await page.evaluate(() => window.dispatchEvent(new Event("online")))
  }
}

const browser = await chromium.launch({ headless: process.env.PR5_HEADED !== "1" })
const desktop = await browser.newContext({ viewport: { width: 1440, height: 900 } })
const phone = await browser.newContext({ viewport: { width: 390, height: 844 }, hasTouch: true, isMobile: true })
for (const context of [desktop, phone]) {
  await context.addInitScript(() => {
    globalThis.__campfireBadges = []
    navigator.setAppBadge = async (count) => { globalThis.__campfireBadges.push(count) }
    navigator.clearAppBadge = async () => { globalThis.__campfireBadges.push(0) }
  })
}
const userA = await desktop.newPage()
const userB = await phone.newPage()
recordFrames(userA, "user-a")
recordFrames(userB, "user-b")

try {
  await login(userA, process.env.PR5_USER_A_EMAIL, process.env.PR5_USER_A_PASSWORD)
  await login(userB, process.env.PR5_USER_B_EMAIL, process.env.PR5_USER_B_PASSWORD)
  await Promise.all([userA.goto(roomUrl), userB.goto(roomUrl)])

  const token = Date.now().toString(36)
  const posted = `pr5-post-${token}`
  const edited = `pr5-edit-${token}`

  await check("message surface fills desktop and phone viewport", async () => {
    for (const [label, page] of [["desktop", userA], ["phone", userB]]) {
      const layout = await page.evaluate(() => {
        const rect = (element) => {
          const { top, right, bottom, left, width, height } = element.getBoundingClientRect()
          return { top, right, bottom, left, width, height }
        }
        const main = rect(document.querySelector("#main-content"))
        const composer = rect(document.querySelector('[data-testid="room-composer"]'))
        const sidebar = rect(document.querySelector('[data-testid="app-sidebar"]'))
        return { viewport: { width: innerWidth, height: innerHeight }, main, composer, sidebar }
      })
      if (layout.main.height < layout.viewport.height / 2) throw new Error(`${label} message surface height is ${layout.main.height}`)
      if (layout.composer.top < layout.main.top || layout.composer.bottom > layout.viewport.height + 1) throw new Error(`${label} composer is outside the message surface`)
      if (layout.sidebar.width > layout.viewport.width * 0.9 + 1) throw new Error(`${label} sidebar exceeds responsive width`)
      if (label === "desktop" && (layout.sidebar.left < layout.main.right - 1 || layout.sidebar.right > layout.viewport.width + 1)) throw new Error("Desktop sidebar is not beside the message surface")
    }
  })

  await check("post and optimistic reconciliation", async () => {
    await post(userA, posted, true)
    await message(userB, posted).waitFor()
    const rows = await userA.locator("[data-message-id]", { hasText: posted }).count()
    if (rows !== 1) throw new Error(`Expected one reconciled row, found ${rows}`)
    if (await message(userA, posted).getAttribute("data-message-id") === "0") throw new Error("Optimistic row did not receive a server id")
  })

  await check("own-message and local day styling", async () => {
    if (!await message(userA, posted).evaluate((row) => row.classList.contains("message--me"))) throw new Error("Sender row lacks own-message class")
    if (await message(userB, posted).evaluate((row) => row.classList.contains("message--me"))) throw new Error("Remote row has own-message class")
    if (!await message(userA, posted).locator(".message__day-separator time").textContent()) throw new Error("Local day separator is empty")
  })

  await check("keyboard edit and focus restoration", async () => {
    await edit(userA, posted, edited)
    await message(userB, edited).waitFor()
    const focused = await userA.evaluate(() => document.activeElement?.dataset?.streamAction)
    if (focused !== "edit") throw new Error(`Focus restored to ${focused || "nothing"}`)
  })

  await check("escape cancels edit", async () => {
    const messageId = await message(userA, edited).getAttribute("data-message-id")
    if (!messageId) throw new Error("Cannot cancel edit without a stable message id")
    const row = messageById(userA, messageId)
    await row.locator(".message__actions > details").evaluate((details) => { details.open = true })
    await row.locator('[data-stream-action="edit"]').click()
    await lexxyEditable(row).press("Escape")
    if (await row.locator("lexxy-editor").count()) throw new Error("Editor remained after Escape")
  })

  await check("boost add remove and failed rollback", async () => {
    const messageId = await message(userA, edited).getAttribute("data-message-id")
    if (!messageId || messageId === "0") throw new Error(`Cannot boost unstable message id ${messageId || "missing"}`)
    const row = messageById(userA, messageId)
    await row.locator(".message__actions > details").evaluate((details) => { details.open = true })
    await row.locator('[data-stream-action="boost"]').first().click()
    const boost = row.locator('[data-boost-id]:not([data-boost-id^="pending-"])').last()
    await boost.waitFor()
    const boostId = await boost.getAttribute("data-boost-id")
    if (!boostId) throw new Error("Created boost has no stable id")
    const remoteBoost = messageById(userB, messageId).locator(`[data-boost-id="${boostId}"]`)
    await remoteBoost.waitFor()
    const content = boost.locator('[data-stream-action="reveal-boost"]')
    await content.click()
    if (!await boost.evaluate((element) => element.classList.contains("expanded"))) throw new Error("Owner boost did not expand")
    const removeBoost = boost.locator('[data-stream-action="remove-boost"]')
    await removeBoost.waitFor({ state: "visible" })
    if (!await removeBoost.evaluate((button) => button === document.activeElement)) throw new Error("Boost removal did not receive focus")
    await removeBoost.click()
    await remoteBoost.waitFor({ state: "detached" })
    await row.locator(`[data-boost-id="${boostId}"]`).waitFor({ state: "detached" })

    await userA.route("**/messages/*/boosts", async (route) => {
      await userA.unroute("**/messages/*/boosts")
      await route.abort()
    })
    await row.locator('[data-stream-action="boost"]').first().click()
    await userA.locator('[data-testid="room-stream-error"]').filter({ hasText: "boost was not saved" }).waitFor()
    if (await row.locator('[data-boost-id^="pending-"]').count()) throw new Error("Failed pending boost remained")
  })

  await check("typing start stop and expiry", async () => {
    await setEditor(userB, "typing-now")
    await userA.locator(".typing-indicator--active").waitFor()
    await setEditor(userB, "")
    await userA.locator(".typing-indicator--active").waitFor({ state: "detached" })
    await setEditor(userB, "typing-expiry")
    await userA.locator(".typing-indicator--active").waitFor()
    await userB.close()
    await userA.locator(".typing-indicator--active").waitFor({ state: "detached", timeout: 8_000 })
  })

  const userB2 = await phone.newPage()
  recordFrames(userB2, "user-b-reconnected")
  await userB2.goto(roomUrl)

  await check("unread sidebar membership and app badge", async () => {
    await userA.goto(secondRoomUrl)
    const unreadText = `pr5-unread-${token}`
    await post(userA, unreadText)
    const secondPath = new URL(secondRoomUrl).pathname
    const link = userB2.locator(`#user_sidebar a[href="${secondPath}"]`)
    await link.waitFor()
    await link.evaluate((element) => new Promise((resolve, reject) => {
      const deadline = Date.now() + 10_000
      const inspect = () => element.classList.contains("unread") ? resolve() : Date.now() > deadline ? reject(new Error("Unread class missing")) : setTimeout(inspect, 100)
      inspect()
    }))
    const badges = await userB2.evaluate(() => globalThis.__campfireBadges)
    if (!badges.some((count) => count > 0)) throw new Error("App badge was not set")
    await userA.goto(roomUrl)
  })

  await check("scroll latest and before/after pagination stability", async () => {
    const list = userA.locator('[data-testid="room-message-history"]')
    const before = await list.locator("[data-message-id]").evaluateAll((rows) => rows.map((row) => row.dataset.messageId))
    await list.evaluate((element) => { element.scrollTop = 0 })
    await userA.waitForTimeout(1_500)
    const after = await list.locator("[data-message-id]").evaluateAll((rows) => rows.map((row) => row.dataset.messageId))
    if (new Set(after).size !== after.length) throw new Error("Pagination introduced duplicate ids")
    if (before.length >= 40 && after[0] === before[0]) throw new Error("Before-page did not advance")
    if (after.length > before.length && await list.evaluate((element) => element.scrollTop) === 0) throw new Error("Before-page did not preserve scroll")

    const oldestPermalink = await list.locator("[data-message-id]").first().locator("[data-stream-part=permalink]").getAttribute("href")
    await userA.goto(new URL(oldestPermalink, baseUrl).href)
    const around = await userA.locator('[data-testid="room-message-history"] [data-message-id]').evaluateAll((rows) => rows.map((row) => row.dataset.messageId))
    await userA.locator('[data-testid="room-message-history"]').evaluate((element) => { element.scrollTop = element.scrollHeight })
    await userA.waitForTimeout(1_500)
    const afterPage = await userA.locator('[data-testid="room-message-history"] [data-message-id]').evaluateAll((rows) => rows.map((row) => row.dataset.messageId))
    if (new Set(afterPage).size !== afterPage.length) throw new Error("After-page introduced duplicate ids")
    if (around.length >= 81 && afterPage.at(-1) === around.at(-1)) throw new Error("After-page did not advance")

    await userA.goto(roomUrl)
    await list.evaluate((element) => { element.scrollTop = 0 })
    await post(userB2, `pr5-latest-${token}`)
    await userA.locator(".message-area__return-to-latest:not([hidden])").click()
    if (await list.evaluate((element) => element.scrollHeight - element.scrollTop - element.clientHeight > 110)) throw new Error("Return-to-latest did not reach bottom")
  })

  await check("image lightbox and video poster", async () => {
    const input = userA.locator('input[type="file"]')
    await input.setInputFiles(process.env.PR5_IMAGE_PATH)
    await userA.locator('[data-testid="room-json-composer"] button[type="submit"]').click()
    const image = userA.locator('[data-stream-action="lightbox"] img').last()
    await image.waitFor()
    await image.click()
    await userA.locator('[data-testid="app-lightbox"][open]').waitFor()
    await userA.locator('[data-testid="app-lightbox"] button[type="submit"]').click()

    await input.setInputFiles(process.env.PR5_VIDEO_PATH)
    await userA.locator('[data-testid="room-json-composer"] button[type="submit"]').click()
    const video = userA.locator("[data-message-id] video[poster]").last()
    await video.waitFor()
    if (!await video.getAttribute("poster")) throw new Error("Video poster is empty")
  })

  await check("first connection convergence", async () => {
    const delayed = await desktop.newPage()
    recordFrames(delayed, "first-connect")
    if (typeof delayed.routeWebSocket !== "function") throw new Error("Installed Playwright lacks WebSocket routing")
    let rejectedFirstSocket = false
    await delayed.routeWebSocket("**", (socket) => {
      if (!rejectedFirstSocket) {
        rejectedFirstSocket = true
        socket.close()
      } else {
        socket.connectToServer()
      }
    })
    await delayed.goto(roomUrl)
    await delayed.waitForTimeout(500)
    if (!rejectedFirstSocket) throw new Error("First WebSocket attempt was not intercepted")
    const missed = `pr5-first-connect-${token}`
    await post(userA, missed)
    await delayed.evaluate(() => window.dispatchEvent(new Event("online")))
    await message(delayed, missed).waitFor({ timeout: 15_000 })
    await delayed.close()
  })

  await check("reconnect around post edit and delete", async () => {
    const reconnectText = `pr5-reconnect-${token}`
    await reconnect(userB2, async () => {
      await post(userA, reconnectText)
      await edit(userA, reconnectText, `${reconnectText}-edited`, false)
    })
    await message(userB2, `${reconnectText}-edited`).waitFor({ timeout: 15_000 })
    await reconnect(userB2, async () => remove(userA, `${reconnectText}-edited`))
    await message(userB2, `${reconnectText}-edited`).waitFor({ state: "detached", timeout: 15_000 })
  })

  await check("touch menu dialog and responsive layouts", async () => {
    const row = message(userB2, edited)
    await row.locator(".message__actions > details > summary").tap()
    if (!await row.locator(".message__actions > details").evaluate((details) => details.open)) throw new Error("Touch did not open details menu")
    const desktopWidth = await userA.locator('[data-testid="app-content"]').evaluate((element) => element.getBoundingClientRect().width)
    const phoneWidth = await userB2.locator('[data-testid="app-content"]').evaluate((element) => element.getBoundingClientRect().width)
    if (desktopWidth <= phoneWidth) throw new Error("Desktop and phone layouts did not differ")
  })

  await check("delete propagation", async () => {
    await remove(userA, edited)
    await message(userB2, edited).waitFor({ state: "detached" })
  })

  await check("managed Reverb JSON frame contract", async () => {
    const missingEvents = [...expectedEvents].filter((event) => !observedEvents.has(event))
    if (missingEvents.length) throw new Error(`Missing event families: ${missingEvents.join(", ")}`)
    if (frameViolations.length) throw new Error(`Forbidden frame material: ${frameViolations.map(({ event, term }) => `${event}:${term}`).join(", ")}`)
  })
} finally {
  await browser.close()
  const summary = {
    generated_at: new Date().toISOString(),
    base_url: baseUrl,
    room_paths: [new URL(roomUrl).pathname, new URL(secondRoomUrl).pathname],
    results,
    frames,
    observed_event_families: [...observedEvents].sort(),
    frame_violations: frameViolations,
  }
  mkdirSync(dirname(outputPath), { recursive: true })
  writeFileSync(outputPath, `${JSON.stringify(summary, null, 2)}\n`)
}

if (results.some(({ status }) => status !== "passed")) process.exitCode = 1
