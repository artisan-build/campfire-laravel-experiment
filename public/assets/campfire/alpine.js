document.addEventListener("livewire:init", () => {
const Alpine = window.Alpine

Alpine.data("appShell", () => ({
  sidebarOpen: false,
  lightboxSource: "",
  lightboxDownload: "",
  canShareFiles: typeof navigator.share === "function",

  toggleSidebar() {
    this.sidebarOpen = !this.sidebarOpen
  },

  closeSidebar() {
    this.sidebarOpen = false
  },

  openLightbox(link) {
    this.lightboxSource = link.href
    this.lightboxDownload = link.dataset.lightboxUrl
    this.$refs.lightbox.showModal()
  },

  openLightboxUrl({ detail }) {
    this.lightboxSource = detail.url
    this.lightboxDownload = detail.download
    this.$refs.lightbox.showModal()
  },

  resetLightbox() {
    this.lightboxSource = ""
    this.lightboxDownload = ""
  },

  async shareLightbox() {
    if (!this.lightboxDownload || typeof navigator.share !== "function") return

    const response = await fetch(this.lightboxDownload)
    const blob = await response.blob()
    const extension = blob.type.split("/").pop()
    const file = new File([ blob ], `Campfire_${Math.random().toString(36).slice(2)}.${extension}`, { type: blob.type })
    const data = { files: [ file ] }

    if (!navigator.canShare || navigator.canShare(data)) await navigator.share(data)
  }
}))

Alpine.data("clipboard", content => ({
  copied: false,

  async copy() {
    this.copied = false

    try {
      await navigator.clipboard.writeText(content)
      this.copied = true
    } catch {
      this.copied = false
    }
  }
}))

Alpine.data("dropTarget", () => ({
  dragover(event) {
    event.preventDefault()
    event.dataTransfer.dropEffect = "copy"
  },

  drop(event) {
    event.preventDefault()
    this.$dispatch("campfire:drop", { files: event.dataTransfer.files })
  }
}))

Alpine.data("messagePopup", () => ({
  opensUp: false,
  maxWidth: 0,

  orient() {
    if (!this.$refs.menu || !this.$refs.popup.open) return

    const bounds = this.$refs.menu.getBoundingClientRect()
    this.opensUp = window.innerHeight - bounds.bottom < 90
    this.maxWidth = window.innerWidth - bounds.left
  },

  close() {
    this.$refs.popup.open = false
  }
}))

Alpine.data("softKeyboard", () => ({
  openSoftKeyboard() {
    if (!(navigator.maxTouchPoints > 0 || matchMedia("(pointer: coarse)").matches)) return

    const input = document.createElement("input")
    input.type = "text"
    input.className = "fixed h-px w-px opacity-0"
    input.addEventListener("focusout", () => input.remove(), { once: true })
    this.$el.appendChild(input)
    input.focus()
  }
}))

Alpine.data("webShare", options => ({
  supported: typeof navigator.share === "function",

  async share() {
    if (!this.supported) return

    const data = { title: options.title || "", text: options.text || "" }
    if (options.url) data.url = options.url
    await navigator.share(data)
  }
}))

Alpine.data("pushSubscriptions", wire => ({
  error: "",

  async subscribe() {
    this.error = ""
    try {
      if (!("serviceWorker" in navigator) || !("Notification" in window)) throw new Error("Notifications are not supported on this device.")
      let registration = await navigator.serviceWorker.getRegistration(window.location.origin) || await navigator.serviceWorker.register("/service-worker")
      if (!registration.active) registration = await navigator.serviceWorker.ready
      const permission = await Notification.requestPermission()
      if (permission !== "granted") throw new Error("Notification permission was not granted.")
      const key = document.querySelector('meta[name="vapid-public-key"]')?.content
      const subscription = await registration.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: this.decodeKey(key) })
      const { endpoint, keys } = subscription.toJSON()
      await wire.register(endpoint, keys.p256dh, keys.auth)
    } catch (error) {
      this.error = error.message || "Notifications could not be enabled."
    }
  },

  decodeKey(value) {
    const padding = "=".repeat((4 - value.length % 4) % 4)
    const raw = atob((value + padding).replace(/-/g, "+").replace(/_/g, "/"))
    return Uint8Array.from(raw, character => character.charCodeAt(0))
  }
}))

Alpine.data("logoutButton", wire => ({
  async logout() {
    let endpoint = null
    try {
      if ("serviceWorker" in navigator) {
        const registration = await navigator.serviceWorker.getRegistration(window.location.origin)
        const subscription = await registration?.pushManager?.getSubscription()
        if (subscription) {
          endpoint = subscription.endpoint
          await subscription.unsubscribe()
        }
      }
    } catch {
      // Browser push cleanup is best-effort; server logout must still run.
    } finally {
      await wire.logout(endpoint)
    }
  }
}))

})
