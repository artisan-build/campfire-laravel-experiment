import { Turbo } from "@hotwired/turbo-rails"
import { getEcho } from "campfire/echo/config"

// The replacement for <turbo-cable-stream-source>. Upstream's element carried a signed stream name
// because Action Cable let the client name a channel class; this one names a Laravel channel and
// routes/channels.php decides whether it may have it.
class TurboEchoStreamSourceElement extends HTMLElement {
  connectedCallback() {
    this.channelName = this.getAttribute("channel")
    const echo = getEcho()
    if (!this.channelName || !echo) return

    echo.private(this.channelName).listen(".turbo-stream", (payload) => this.#received(payload))
  }

  disconnectedCallback() {
    if (this.channelName) getEcho()?.leave(this.channelName)
  }

  #received(payload) {
    if (payload?.html) {
      Turbo.renderStreamMessage(payload.html)
      return
    }

    // Over Reverb's per-message size limit. Ask for what we missed instead.
    if (payload?.oversize) this.#recover()
  }

  #recover() {
    const url = this.getAttribute("data-refresh-url")
    if (url) {
      fetch(url, { headers: { Accept: "text/vnd.turbo-stream.html" } })
        .then((response) => (response.ok ? response.text() : ""))
        .then((html) => html && Turbo.renderStreamMessage(html))
        .catch(() => {})
      return
    }

    this.closest("turbo-frame")?.reload()
  }
}

if (!customElements.get("turbo-echo-stream-source")) {
  customElements.define("turbo-echo-stream-source", TurboEchoStreamSourceElement)
}
