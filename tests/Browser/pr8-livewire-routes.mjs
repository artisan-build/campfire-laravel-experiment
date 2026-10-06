#!/usr/bin/env node

import { createRequire } from "node:module"
import { mkdirSync, writeFileSync } from "node:fs"
import { dirname, resolve } from "node:path"

const require = createRequire(import.meta.url)
const { chromium } = require(require.resolve("playwright", { paths: [ process.cwd() ] }))
const baseUrl = (process.env.PR8_BASE_URL || "http://127.0.0.1:8000").replace(/\/$/, "")
const outputPath = resolve(process.env.PR8_OUTPUT || "tmp/pr8-livewire-routes/result.json")
const required = [ "PR8_ADMIN_EMAIL", "PR8_ADMIN_PASSWORD", "PR8_JOIN_CODE" ]
for (const name of required) if (!process.env[name]) throw new Error(`Missing ${name}`)

const runId = `${Date.now()}-${Math.random().toString(36).slice(2, 8)}`
const memberEmail = process.env.PR8_MEMBER_EMAIL || `pr8-${runId}@example.test`
const memberPassword = process.env.PR8_MEMBER_PASSWORD || `Pr8-${runId}-password`
const states = []
const consoleProblems = []
const expectedNegativeEvidence = []
const activeNegativeControls = new Map()

function watchConsole(page, label) {
  page.on("console", message => {
    if (message.type() !== "error") return

    const detail = { label, type: message.type(), text: message.text(), url: page.url(), source: message.location().url }
    const expectation = activeNegativeControls.get(page)
    const status = Number(message.text().match(/status of (\d{3})/)?.[1])
    const sourcePath = detail.source ? new URL(detail.source).pathname : ""
    if (expectation && status === expectation.status && (!sourcePath || expectation.matchesPath(sourcePath))) {
      expectedNegativeEvidence.push({ ...detail, control: expectation.name, status })
    } else {
      consoleProblems.push(detail)
    }
  })
  page.on("pageerror", error => consoleProblems.push({ label, type: "pageerror", text: error.message, url: page.url() }))
}

async function step(name, operation) {
  await operation()
  states.push({ name, at: new Date().toISOString() })
}

async function expectNegativeControl(page, expectation, operation) {
  activeNegativeControls.set(page, expectation)
  try {
    const responsePromise = page.waitForResponse(response => {
      const pathname = new URL(response.url()).pathname
      return response.request().method() === expectation.method && expectation.matchesPath(pathname)
    })
    const [ result, response ] = await Promise.all([ operation(), responsePromise ])
    if (response.status() !== expectation.status) {
      throw new Error(`${expectation.name} returned ${response.status()}, expected ${expectation.status}`)
    }
    expectedNegativeEvidence.push({
      control: expectation.name,
      type: "response",
      method: expectation.method,
      status: response.status(),
      path: new URL(response.url()).pathname,
    })
    return result
  } finally {
    activeNegativeControls.delete(page)
  }
}

async function login(page, email, password) {
  await page.goto(`${baseUrl}/session/new`)
  const form = page.getByTestId("sign-in-form")
  await form.locator('input[type="email"]').fill(email)
  await form.locator('input[type="password"]').fill(password)
  await Promise.all([ page.waitForURL(url => url.pathname === "/" || /^\/rooms\/\d+$/.test(url.pathname)), form.getByRole("button", { name: "Sign in" }).click() ])
}

async function waitForNotification(page) {
  const deadline = Date.now() + 10_000
  while (Date.now() < deadline) {
    const notifications = await page.evaluate(async () => {
      const registration = await navigator.serviceWorker.getRegistration(window.location.origin)
      return (await registration?.getNotifications() || []).map(notification => notification.title)
    })
    if (notifications.includes("Campfire")) return
    await page.waitForTimeout(200)
  }
  throw new Error("Test notification was not delivered through the service worker")
}

function decodeTransferUrl(qrHref) {
  const encoded = qrHref.split("/qr_code/")[1]
  return Buffer.from(encoded.replace(/-/g, "+").replace(/_/g, "/"), "base64").toString("utf8")
}

const browser = await chromium.launch({ headless: true, executablePath: process.env.PR8_BROWSER_EXECUTABLE || undefined })
const adminContext = await browser.newContext({ permissions: [ "notifications" ] })
const memberContext = await browser.newContext({ permissions: [ "notifications" ] })
const transferContext = await browser.newContext()
const admin = await adminContext.newPage()
const member = await memberContext.newPage()
const transfer = await transferContext.newPage()
for (const [ page, label ] of [[admin, "admin"], [member, "member"], [transfer, "transfer"]]) watchConsole(page, label)
admin.on("dialog", dialog => dialog.accept())

let botApiUrl
let memberSubscriptionId

try {
  await step("admin login", () => login(admin, process.env.PR8_ADMIN_EMAIL, process.env.PR8_ADMIN_PASSWORD))

  await step("bot create", async () => {
    await admin.goto(`${baseUrl}/account/bots/new`)
    const form = admin.getByTestId("bot-form")
    await form.getByLabel("Bot name").fill(`PR8 Bot ${runId}`)
    await form.getByLabel("Webhook URL").fill("https://example.test/pr8-hook")
    await form.locator('input[type="file"]').setInputFiles({ name: "pr8-avatar.png", mimeType: "image/png", buffer: Buffer.from("iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=", "base64") })
    await Promise.all([ admin.waitForURL(`${baseUrl}/account/bots`), form.getByRole("button", { name: "Save changes" }).click() ])
    const row = admin.getByTestId("bot-row").filter({ hasText: `PR8 Bot ${runId}` })
    if (!(await row.locator("img").getAttribute("src")).includes("/users/")) throw new Error("Bot avatar upload did not persist")
    botApiUrl = await row.locator('input[aria-label="curl command for posting messages"]').inputValue().then(command => command.replace(/^curl -d 'Hello!' /, ""))
    if (!botApiUrl.includes("/messages")) throw new Error("Preserved bot API URL was not displayed")
  })

  await step("bot api post update boost delete", async () => {
    const create = await admin.request.post(botApiUrl, { data: `PR8 searchable ${runId}`, headers: { "Content-Type": "text/plain" } })
    if (create.status() !== 201) throw new Error(`Bot post failed: ${create.status()}`)
    const messageId = create.headers().location?.split("/").pop()
    const update = await admin.request.patch(`${botApiUrl}/${messageId}`, { data: `PR8 edited ${runId}`, headers: { "Content-Type": "text/plain" } })
    if (update.status() !== 200) throw new Error(`Bot update failed: ${update.status()}`)
    const boost = await admin.request.post(`${botApiUrl}/${messageId}/boosts`, { data: "ship", headers: { "Content-Type": "text/plain" } })
    if (boost.status() !== 201) throw new Error(`Bot boost failed: ${boost.status()}`)
    const boostId = (await boost.json()).id
    const unboost = await admin.request.delete(`${botApiUrl}/${messageId}/boosts/${boostId}`)
    if (unboost.status() !== 204) throw new Error(`Bot unboost failed: ${unboost.status()}`)
    const remove = await admin.request.delete(`${botApiUrl}/${messageId}`)
    if (remove.status() !== 204) throw new Error(`Bot delete failed: ${remove.status()}`)
  })

  await step("search and personal history", async () => {
    const searchText = `PR8 search ${runId}`
    const normalizedSearchText = searchText.replaceAll("-", " ")
    const create = await admin.request.post(botApiUrl, { data: searchText, headers: { "Content-Type": "text/plain" } })
    if (create.status() !== 201) throw new Error("Search fixture post failed")
    await admin.goto(`${baseUrl}/searches`)
    const form = admin.getByTestId("search-form")
    await form.locator('input[type="search"]').fill(searchText)
    await form.getByRole("button", { name: "Search" }).click()
    await admin.getByTestId("search-result-list").getByText(searchText).waitFor()
    await admin.getByTestId("search-history").getByText(normalizedSearchText).waitFor()
  })

  await step("bot edit and key rotation invalidates old URL", async () => {
    await admin.goto(`${baseUrl}/account/bots`)
    const row = admin.getByTestId("bot-row").filter({ hasText: `PR8 Bot ${runId}` })
    await Promise.all([
      admin.waitForURL(url => /^\/account\/bots\/\d+\/edit$/.test(url.pathname)),
      row.getByRole("link", { name: "Edit" }).click(),
    ])
    const form = admin.getByTestId("bot-form")
    await form.getByLabel("Bot name").fill(`PR8 Bot Edited ${runId}`)
    await Promise.all([ admin.waitForURL(`${baseUrl}/account/bots`), form.getByRole("button", { name: "Save changes" }).click() ])
    const editedRow = admin.getByTestId("bot-row").filter({ hasText: `PR8 Bot Edited ${runId}` })
    await editedRow.waitFor()
    await Promise.all([
      admin.waitForResponse(response => response.request().method() === "POST" && new URL(response.url()).pathname.endsWith("/update")),
      editedRow.getByRole("button", { name: "Generate new key" }).click(),
    ])
    const rejected = await admin.request.get(botApiUrl)
    if (rejected.status() !== 401) throw new Error(`Old bot key remained valid: ${rejected.status()}`)
  })

  await step("join signup with locked credential negative", async () => {
    await member.goto(`${baseUrl}/join/${process.env.PR8_JOIN_CODE}`)
    const rejected = await expectNegativeControl(member, {
      name: "join-code locked-property substitution",
      method: "POST",
      status: 500,
      matchesPath: pathname => pathname.endsWith("/update"),
    }, () => member.getByTestId("auth-sign-up").evaluate(async element => {
      const wire = window.Livewire.find(element.closest("[wire\\:id]").getAttribute("wire:id"))
      try { await wire.$set("joinCode", "substituted-code"); return false } catch { return true }
    }))
    if (!rejected) throw new Error("Join-code substitution did not fail loudly")
    await member.goto(`${baseUrl}/join/${process.env.PR8_JOIN_CODE}`)
    const form = member.getByTestId("sign-up-form")
    await form.getByLabel("Your name").fill(`PR8 Member ${runId}`)
    await form.getByLabel("Email address").fill(memberEmail)
    await form.getByLabel("Password").fill(memberPassword)
    await Promise.all([ member.waitForURL(url => url.pathname === "/" || /^\/rooms\/\d+$/.test(url.pathname)), form.getByRole("button", { name: "Create account" }).click() ])
  })

  await step("non admin bot access denied", async () => {
    await expectNegativeControl(member, {
      name: "non-admin bot access",
      method: "GET",
      status: 403,
      matchesPath: pathname => pathname === "/account/bots",
    }, () => member.goto(`${baseUrl}/account/bots`))
  })

  await step("push registration test and cross-user protection", async () => {
    await member.goto(`${baseUrl}/users/me/push_subscriptions`)
    await member.getByRole("button", { name: "Enable notifications on this device" }).click()
    const memberRow = member.getByTestId("push-subscription-row")
    await memberRow.waitFor()
    memberSubscriptionId = Number((await memberRow.getByRole("button", { name: "Remove" }).getAttribute("wire:click")).match(/\d+/)[0])
    await memberRow.getByRole("button", { name: "Send test" }).click()
    await waitForNotification(member)

    await admin.goto(`${baseUrl}/users/me/push_subscriptions`)
    await admin.getByRole("button", { name: "Enable notifications on this device" }).click()
    await admin.getByTestId("push-subscription-row").first().waitFor()
    const rejected = await expectNegativeControl(admin, {
      name: "cross-user push removal",
      method: "POST",
      status: 404,
      matchesPath: pathname => pathname.endsWith("/update"),
    }, () => admin.getByTestId("settings-notifications").evaluate(async (element, id) => {
      const wire = window.Livewire.find(element.closest("[wire\\:id]").getAttribute("wire:id"))
      try { await wire.remove(id); return false } catch { return true }
    }, memberSubscriptionId))
    if (!rejected) throw new Error("Cross-user push removal did not fail loudly")
    await member.reload()
    await member.getByTestId("push-subscription-row").waitFor()
    await member.getByTestId("push-subscription-row").getByRole("button", { name: "Remove" }).click()
    await member.getByText("No devices are subscribed yet.").waitFor()
  })

  await step("session transfer locked credential and confirmation", async () => {
    await admin.goto(`${baseUrl}/users/me/profile`)
    const transferUrl = decodeTransferUrl(await admin.getByRole("link", { name: "Show QR code" }).getAttribute("href"))
    await transfer.goto(transferUrl)
    const rejected = await expectNegativeControl(transfer, {
      name: "transfer-id locked-property substitution",
      method: "POST",
      status: 500,
      matchesPath: pathname => pathname.endsWith("/update"),
    }, () => transfer.getByTestId("auth-transfer").evaluate(async element => {
      const wire = window.Livewire.find(element.closest("[wire\\:id]").getAttribute("wire:id"))
      try { await wire.$set("transferId", "substituted-transfer"); return false } catch { return true }
    }))
    if (!rejected) throw new Error("Transfer-id substitution did not fail loudly")
    await transfer.goto(transferUrl)
    await Promise.all([ transfer.waitForURL(url => url.pathname === "/" || /^\/rooms\/\d+$/.test(url.pathname)), transfer.getByTestId("transfer-form").getByRole("button", { name: "Sign in" }).click() ])
  })

  await step("logout unsubscribe and login again", async () => {
    await admin.goto(`${baseUrl}/users/me/profile`)
    await Promise.all([ admin.waitForURL(`${baseUrl}/session/new`), admin.getByRole("button", { name: "Log out" }).click() ])
    await login(admin, process.env.PR8_ADMIN_EMAIL, process.env.PR8_ADMIN_PASSWORD)
  })

  await step("bot delete", async () => {
    await admin.goto(`${baseUrl}/account/bots`)
    const row = admin.getByTestId("bot-row").filter({ hasText: `PR8 Bot Edited ${runId}` })
    await row.getByRole("button", { name: "Delete bot" }).click()
    await row.waitFor({ state: "detached" })
  })

  if (consoleProblems.length) throw new Error(`Console problems: ${JSON.stringify(consoleProblems)}`)
  mkdirSync(dirname(outputPath), { recursive: true })
  writeFileSync(outputPath, JSON.stringify({ ok: true, run_id: runId, states, expected_negative_evidence: expectedNegativeEvidence, console_problems: consoleProblems }, null, 2))
} catch (error) {
  mkdirSync(dirname(outputPath), { recursive: true })
  writeFileSync(outputPath, JSON.stringify({ ok: false, run_id: runId, states, expected_negative_evidence: expectedNegativeEvidence, console_problems: consoleProblems, error: error.message }, null, 2))
  process.exitCode = 1
} finally {
  await browser.close()
}
