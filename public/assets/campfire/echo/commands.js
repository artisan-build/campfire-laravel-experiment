import { csrfToken } from "campfire/echo/config"

// Client to server. Cloud's managed Reverb is a relay with no webhook or client-event configuration
// the CLI can reach, so what used to travel up the Action Cable socket travels over HTTP.
export async function command(path, body) {
  try {
    await fetch(path, {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
        "Accept": "application/json",
        "X-CSRF-Token": csrfToken()
      },
      body: JSON.stringify(body),
      keepalive: true
    })
  } catch {
    // A dropped presence or typing report self-heals: presence expires after a minute and the next
    // keystroke sends another typing event.
  }
}
