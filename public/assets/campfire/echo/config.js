import Echo from "campfire/vendor/laravel-echo"
import Pusher from "campfire/vendor/pusher-js"

// One Echo instance for the whole page, exactly as there was one Action Cable consumer.
let echo = null
let resolved = false

export function metaContent(name) {
  return document.head.querySelector(`meta[name="${name}"]`)?.getAttribute("content")
}

export function currentUserId() {
  const id = metaContent("current-user-id")
  return id ? parseInt(id, 10) : null
}

export function csrfToken() {
  return metaContent("csrf-token") || ""
}

export function getEcho() {
  if (resolved) return echo
  resolved = true

  const raw = metaContent("echo-config")
  if (!raw) return echo

  const config = JSON.parse(raw)
  if (!config.key) return echo

  echo = new Echo({
    broadcaster: "reverb",
    Pusher: Pusher,
    key: config.key,
    wsHost: config.host,
    wsPort: config.port,
    wssPort: config.port,
    forceTLS: config.scheme === "https",
    enabledTransports: ["ws", "wss"],
    authEndpoint: "/broadcasting/auth"
  })

  return echo
}

// A connection-state fan-out, so every subscription learns about the one socket.
const listeners = new Set()
let connectionBound = false
let connected = false

export function onConnectionChange(listener) {
  listeners.add(listener)
  bindConnection()
  if (connected) listener(true)
  return () => listeners.delete(listener)
}

export function isConnected() {
  return connected
}

export function reconnect() {
  getEcho()?.connector?.pusher?.connect()
}

function bindConnection() {
  if (connectionBound) return
  const instance = getEcho()
  if (!instance?.connector?.pusher) return
  connectionBound = true

  const connection = instance.connector.pusher.connection
  connection.bind("connected", () => notify(true))
  connection.bind("disconnected", () => notify(false))
  connection.bind("unavailable", () => notify(false))
  connection.bind("failed", () => notify(false))
  if (connection.state === "connected") notify(true)
}

function notify(state) {
  connected = state
  listeners.forEach((listener) => listener(state))
}
