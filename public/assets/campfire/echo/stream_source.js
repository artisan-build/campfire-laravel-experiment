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

    // Gzipped, because one rendered message does not fit in a Reverb frame uncompressed.
    if (payload?.gz) {
      this.#inflate(payload.gz)
        .then((html) => Turbo.renderStreamMessage(html))
        .catch(() => this.#recover())
      return
    }

    // Too large even compressed. Ask for what we missed instead.
    if (payload?.oversize) this.#recover()
  }

  async #inflate(encoded) {
    const bytes = Uint8Array.from(atob(encoded), (c) => c.charCodeAt(0))
    const stream = new Blob([bytes]).stream().pipeThrough(new DecompressionStream("gzip"))
    return await new Response(stream).text()
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
