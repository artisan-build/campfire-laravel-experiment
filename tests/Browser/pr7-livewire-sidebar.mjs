#!/usr/bin/env node

import { createRequire } from "node:module"
import { mkdirSync, writeFileSync } from "node:fs"
import { dirname, resolve } from "node:path"
import { pathToFileURL } from "node:url"

const require = createRequire(import.meta.url)
const searchPaths = process.env.PR7_PLAYWRIGHT_NODE_MODULES ? [ process.env.PR7_PLAYWRIGHT_NODE_MODULES ] : undefined
const playwrightPath = require.resolve("playwright", searchPaths ? { paths: searchPaths } : undefined)
const playwrightModule = await import(pathToFileURL(playwrightPath))
const { chromium } = playwrightModule.default || playwrightModule

for (const name of [ "PR7_BASE_URL", "PR7_USER_A_EMAIL", "PR7_USER_A_PASSWORD", "PR7_USER_B_EMAIL", "PR7_USER_B_PASSWORD" ]) {
  if (!process.env[name]) throw new Error(`Missing environment: ${name}`)
}

const baseUrl = process.env.PR7_BASE_URL.replace(/\/$/, "")
const outputPath = resolve(process.env.PR7_OUTPUT || "tmp/pr7-live/summary.json")
const suffix = Date.now().toString(36)
const anchorName = `Sidebar Anchor ${suffix}`
const targetName = `Sidebar Target ${suffix}`
const messageText = `Sidebar unread ${suffix}`
const results = []
const states = []
const consoleProblems = []
let failure = null

mkdirSync(dirname(outputPath), { recursive: true })

async function step(name, operation) {
  const startedAt = Date.now()
  try {
    await operation()
    results.push({ name, status: "passed", duration_ms: Date.now() - startedAt })
  } catch (error) {
    results.push({ name, status: "failed", duration_ms: Date.now() - startedAt, error: error.message })
    throw error
  }
}

async function signIn(page, email, password) {
  await page.goto(`${baseUrl}/session/new`)
  await page.locator('input[name="email_address"]').fill(email)
  await page.locator('input[name="password"]').fill(password)
  await Promise.all([
    page.waitForURL((url) => url.pathname !== "/session/new"),
    page.locator('form[action="/session"]').getByRole("button", { name: "Sign in", exact: true }).click(),
  ])
}

async function createClosedRoom(page, name, memberName) {
  await page.goto(`${baseUrl}/rooms/closeds/new`)
  await page.locator("#room_name").fill(name)
  await page.locator("label", { hasText: memberName }).locator('input[type="checkbox"]').check()
  await Promise.all([
    page.waitForURL(/\/rooms\/\d+$/),
    page.getByRole("button", { name: "Save room" }).click(),
  ])
  return Number(new URL(page.url()).pathname.split("/").pop())
}

function roomLink(page, roomId) {
  return page.locator(`[data-testid="sidebar-rooms"] a[data-room-id="${roomId}"]`)
}

async function recordState(name, page, roomId) {
  const link = roomLink(page, roomId)
  const state = await link.evaluate((element) => ({
    text: element.textContent.trim(),
    href: element.getAttribute("href"),
    classes: Array.from(element.classList),
  }))
  states.push({ name, url: page.url(), room_id: roomId, link: state })
  return state
}

function lexxyEditable(page) {
  return page.locator("#message_body").locator('[contenteditable="true"]')
}

const browser = await chromium.launch({ headless: true, executablePath: process.env.PR7_BROWSER_EXECUTABLE || undefined })
const contextA = await browser.newContext()
const contextB = await browser.newContext()
await contextB.addInitScript(() => {
  globalThis.__pr7Badge = []
  Object.defineProperty(navigator, "setAppBadge", { configurable: true, value: async (count) => globalThis.__pr7Badge.push(Number(count)) })
  Object.defineProperty(navigator, "clearAppBadge", { configurable: true, value: async () => globalThis.__pr7Badge.push(0) })
})
const pageA = await contextA.newPage()
const pageB1 = await contextB.newPage()
const pageB2 = await contextB.newPage()
for (const page of [ pageA, pageB1, pageB2 ]) {
  page.on("console", (message) => {
    if ([ "warning", "error" ].includes(message.type())) consoleProblems.push(`${message.type()}: ${message.text()}`)
  })
  page.on("pageerror", (error) => consoleProblems.push(`pageerror: ${error.message}`))
}

try {
  await step("two users sign in", async () => {
    await signIn(pageA, process.env.PR7_USER_A_EMAIL, process.env.PR7_USER_A_PASSWORD)
    await signIn(pageB1, process.env.PR7_USER_B_EMAIL, process.env.PR7_USER_B_PASSWORD)
  })
  const memberName = await pageB1.locator('meta[name="current-user-name"]').getAttribute("content")
  if (!memberName) throw new Error("User B name was not rendered")

  let anchorId
  await step("prepare two-tab anchor room", async () => {
    anchorId = await createClosedRoom(pageA, anchorName, memberName)
    await Promise.all([ pageB1.goto(`${baseUrl}/rooms/${anchorId}`), pageB2.goto(`${baseUrl}/rooms/${anchorId}`) ])
    await Promise.all([ pageB1.locator('[data-testid="sidebar-rooms"]').waitFor(), pageB2.locator('[data-testid="sidebar-rooms"]').waitFor() ])
  })

  let targetId
  await step("room create reaches both B tabs without navigation", async () => {
    const before = [ pageB1.url(), pageB2.url() ]
    targetId = await createClosedRoom(pageA, targetName, memberName)
    await Promise.all([ roomLink(pageB1, targetId).waitFor(), roomLink(pageB2, targetId).waitFor() ])
    if (pageB1.url() !== before[0] || pageB2.url() !== before[1]) throw new Error("B navigated while receiving the created room")
    const statesNow = await Promise.all([ recordState("created-tab-1", pageB1, targetId), recordState("created-tab-2", pageB2, targetId) ])
    if (statesNow.some((state) => state.text !== targetName || state.href !== `/rooms/${targetId}`)) throw new Error("Created room link state was not exact")
  })

  await step("unread reaches both B tabs without navigation", async () => {
    const before = [ pageB1.url(), pageB2.url() ]
    const editable = lexxyEditable(pageA)
    await editable.fill(messageText)
    await editable.press("Enter")
    await pageA.getByText(messageText, { exact: true }).waitFor()
    await Promise.all([
      roomLink(pageB1, targetId).evaluate((element) => new Promise((resolve, reject) => {
        const timeout = setTimeout(() => reject(new Error("Tab 1 missed unread class")), 15_000)
        const observer = new MutationObserver(() => {
          if (element.classList.contains("unread")) { clearTimeout(timeout); observer.disconnect(); resolve() }
        })
        observer.observe(element, { attributes: true, attributeFilter: [ "class" ] })
        if (element.classList.contains("unread")) { clearTimeout(timeout); observer.disconnect(); resolve() }
      })),
      roomLink(pageB2, targetId).evaluate((element) => new Promise((resolve, reject) => {
        const timeout = setTimeout(() => reject(new Error("Tab 2 missed unread class")), 15_000)
        const observer = new MutationObserver(() => {
          if (element.classList.contains("unread")) { clearTimeout(timeout); observer.disconnect(); resolve() }
        })
        observer.observe(element, { attributes: true, attributeFilter: [ "class" ] })
        if (element.classList.contains("unread")) { clearTimeout(timeout); observer.disconnect(); resolve() }
      })),
    ])
    if (pageB1.url() !== before[0] || pageB2.url() !== before[1]) throw new Error("B navigated while receiving unread state")
    await recordState("unread-tab-1", pageB1, targetId)
    await recordState("unread-tab-2", pageB2, targetId)
    const badges = await pageB2.evaluate(() => globalThis.__pr7Badge)
    if (!badges.some((count) => count > 0)) throw new Error("Unread transition did not set the app badge")
  })

  await step("opening in one B tab clears the other tab", async () => {
    const stationaryUrl = pageB2.url()
    await Promise.all([
      pageB1.waitForURL(new RegExp(`/rooms/${targetId}$`)),
      roomLink(pageB1, targetId).click(),
    ])
    await roomLink(pageB2, targetId).evaluate((element) => new Promise((resolve, reject) => {
      const timeout = setTimeout(() => reject(new Error("Other tab missed read clearing")), 15_000)
      const observer = new MutationObserver(() => {
        if (!element.classList.contains("unread")) { clearTimeout(timeout); observer.disconnect(); resolve() }
      })
      observer.observe(element, { attributes: true, attributeFilter: [ "class" ] })
      if (!element.classList.contains("unread")) { clearTimeout(timeout); observer.disconnect(); resolve() }
    }))
    if (pageB2.url() !== stationaryUrl) throw new Error("Other B tab navigated while receiving read state")
    const state = await recordState("read-cleared-tab-2", pageB2, targetId)
    if (state.classes.includes("unread")) throw new Error("Other B tab retained unread class")
    const badges = await pageB2.evaluate(() => globalThis.__pr7Badge)
    if (badges.at(-1) !== 0) throw new Error("Read transition did not clear the app badge")
  })

  await step("browser console remains clean", async () => {
    if (consoleProblems.length > 0) throw new Error(`Console was not clean: ${consoleProblems.join(" | ")}`)
  })
} catch (error) {
  failure = error
} finally {
  await browser.close()
  writeFileSync(outputPath, JSON.stringify({ generated_at: new Date().toISOString(), results, states, console_problems: consoleProblems }, null, 2))
}

if (failure) throw failure
