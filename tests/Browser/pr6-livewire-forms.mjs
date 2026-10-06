#!/usr/bin/env node

import { createRequire } from "node:module"
import { mkdirSync, writeFileSync } from "node:fs"
import { dirname, resolve } from "node:path"
import { pathToFileURL } from "node:url"

const require = createRequire(import.meta.url)
const searchPaths = process.env.PR6_PLAYWRIGHT_NODE_MODULES ? [ process.env.PR6_PLAYWRIGHT_NODE_MODULES ] : undefined
const playwrightPath = require.resolve("playwright", searchPaths ? { paths: searchPaths } : undefined)
const playwrightModule = await import(pathToFileURL(playwrightPath))
const { chromium } = playwrightModule.default || playwrightModule

if (!process.env.PR6_BASE_URL) throw new Error("Missing environment: PR6_BASE_URL")

const baseUrl = process.env.PR6_BASE_URL.replace(/\/$/, "")
const outputPath = resolve(process.env.PR6_OUTPUT || "tmp/pr6-live/summary.json")
const avatarPath = resolve("tmp/pr6-live/avatar.png")
const suffix = Date.now().toString(36)
const profileName = `Livewire Person ${suffix}`
const roomName = `Livewire Room ${suffix}`
const renamedRoom = `Renamed Room ${suffix}`
const accountName = `Campfire ${suffix}`
const email = `livewire-${suffix}@example.test`
const password = `Livewire-${suffix}-password`
const consoleProblems = []
const results = []

mkdirSync(dirname(outputPath), { recursive: true })
writeFileSync(avatarPath, Buffer.from("iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAIAAACQd1PeAAAACXBIWXMAAA7EAAAOxAGVKw4bAAAADElEQVQImWP4z8AAAAMBAQCc479ZAAAAAElFTkSuQmCC", "base64"))

async function check(name, operation) {
  const startedAt = Date.now()
  try {
    await operation()
    results.push({ name, status: "passed", duration_ms: Date.now() - startedAt })
  } catch (error) {
    results.push({ name, status: "failed", duration_ms: Date.now() - startedAt, error: error.message })
  }
}

async function waitForLivewireResponse(operation, endpoint = "update") {
  const [ response ] = await Promise.all([
    page.waitForResponse((candidate) => candidate.request().method() === "POST" && new URL(candidate.url()).pathname.endsWith(`/${endpoint}`)),
    operation(),
  ])
  if (!response.ok()) throw new Error(`Livewire ${endpoint} failed (${response.status()})`)
}

const browser = await chromium.launch({ headless: true, executablePath: process.env.PR6_BROWSER_EXECUTABLE || undefined })
const context = await browser.newContext()
await context.addInitScript(() => {
  Object.defineProperty(navigator, "clipboard", { configurable: true, value: { writeText: async () => {} } })
})
const page = await context.newPage()
page.on("console", (message) => {
  if ([ "warning", "error" ].includes(message.type())) consoleProblems.push(`${message.type()}: ${message.text()}`)
})
page.on("pageerror", (error) => consoleProblems.push(`pageerror: ${error.message}`))

try {
  await check("first-run creates browser-owned account", async () => {
    await page.goto(`${baseUrl}/first_run`)
    await page.locator('input[name="user[name]"]').fill(profileName)
    await page.locator('input[name="user[email_address]"]').fill(email)
    await page.locator('input[name="user[password]"]').fill(password)
    await Promise.all([
      page.waitForURL((url) => !url.pathname.startsWith("/first_run")),
      page.getByRole("button", { name: "Create account" }).click(),
    ])
  })

  let roomId
  await check("Livewire creates renames and converts room", async () => {
    await page.goto(`${baseUrl}/rooms/opens/new`)
    await page.locator('[data-testid="room-form-fields"] input[wire\\:model="name"]').fill(roomName)
    await Promise.all([
      page.waitForURL(/\/rooms\/\d+$/),
      page.getByRole("button", { name: "Save room" }).click(),
    ])
    roomId = new URL(page.url()).pathname.split("/").pop()
    const streamInitialized = await page.locator('[data-testid="app-content"]').evaluate((element) =>
      element._x_dataStack?.some((data) => data.messagesById instanceof Map) === true
    )
    if (!streamInitialized) throw new Error("JSON message stream did not initialize")
    await page.goto(`${baseUrl}/rooms/opens/${roomId}/edit`)
    await page.locator('#room_name').fill(renamedRoom)
    await page.getByRole("button", { name: "Make private" }).click()
    await Promise.all([
      page.waitForURL(new RegExp(`/rooms/${roomId}$`)),
      page.getByRole("button", { name: "Save room" }).click(),
    ])
    await page.goto(`${baseUrl}/rooms/closeds/${roomId}/edit`)
    const value = await page.locator("#room_name").inputValue()
    if (value !== renamedRoom) throw new Error("Room rename did not persist after reload")
  })

  await check("Livewire involvement persists", async () => {
    await page.goto(`${baseUrl}/rooms/${roomId}/involvement`)
    await page.locator('input[type="radio"][value="everything"]').check()
    await waitForLivewireResponse(() => page.getByRole("button", { name: "Save notifications" }).click())
    await page.reload()
    if (!await page.locator('input[type="radio"][value="everything"]').isChecked()) throw new Error("Involvement did not persist")
  })

  await check("Livewire profile name and avatar persist", async () => {
    await page.goto(`${baseUrl}/users/me/profile`)
    const uploadFinished = page.waitForResponse((response) => response.request().method() === "POST" && new URL(response.url()).pathname.endsWith("/update"))
    await waitForLivewireResponse(
      () => page.locator('[data-testid="profile-form"] input[type="file"]').setInputFiles(avatarPath),
      "upload-file",
    )
    if (!(await uploadFinished).ok()) throw new Error("Livewire upload registration failed")
    await page.locator('[data-testid="profile-form"] input[wire\\:model="name"]').fill(`${profileName} Updated`)
    await waitForLivewireResponse(() => page.getByRole("button", { name: "Save changes" }).click())
    const validationErrors = await page.locator('[data-testid="profile-form"] .text-red-600').allTextContents()
    if (validationErrors.length > 0) throw new Error(`Profile validation failed: ${validationErrors.join(" | ")}`)
    await page.reload()
    const value = await page.locator('[data-testid="profile-form"] input[wire\\:model="name"]').inputValue()
    if (value !== `${profileName} Updated`) throw new Error("Profile name did not persist")
    const avatarResponse = await page.request.get(new URL(await page.getByAltText("Your avatar").getAttribute("src"), baseUrl).href)
    if (!avatarResponse.ok()) throw new Error("Uploaded avatar is not readable")
  })

  await check("Livewire account name and existing Alpine clipboard persist", async () => {
    await page.goto(`${baseUrl}/account/edit`)
    await page.locator('[data-testid="account-form"] input[wire\\:model="name"]').fill(accountName)
    await waitForLivewireResponse(() => page.getByRole("button", { name: "Save changes" }).click())
    await page.reload()
    const value = await page.locator('[data-testid="account-form"] input[wire\\:model="name"]').inputValue()
    if (value !== accountName) throw new Error("Account name did not persist")
    await page.getByRole("button", { name: "Copy invitation link" }).click()
    await page.getByText("Copied", { exact: true }).waitFor()
  })

  await check("single Alpine runtime has a clean console", async () => {
    const runtimes = await page.evaluate(() => ({ alpine: Boolean(window.Alpine), livewire: Boolean(window.Livewire) }))
    if (!runtimes.alpine || !runtimes.livewire) throw new Error("Livewire or Alpine runtime is missing")
    const duplicate = consoleProblems.filter((problem) => /alpine|multiple instances|already initialized/i.test(problem))
    if (duplicate.length > 0) throw new Error(`Duplicate Alpine warning: ${duplicate.join(" | ")}`)
    if (consoleProblems.length > 0) throw new Error(`Console was not clean: ${consoleProblems.join(" | ")}`)
  })
} finally {
  await browser.close()
}

const payload = { generated_at: new Date().toISOString(), results, console_problem_count: consoleProblems.length }
writeFileSync(outputPath, JSON.stringify(payload, null, 2))
if (results.some(({ status }) => status !== "passed")) process.exitCode = 1
