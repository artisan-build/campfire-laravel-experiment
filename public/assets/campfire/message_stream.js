import { csrfToken, getEcho, onConnectionChange, reconnect } from "campfire/echo/config"

const MESSAGE_KEYS = [ "id", "client_message_id", "created_at", "updated_at", "body", "creator", "room", "url", "attachment", "boosts", "mentions" ]
const MAX_MESSAGES = 300
const TYPING_TIMEOUT = 5_000
const OFFLINE_DELAY = 5_000

function completeMessage(message) {
  return message && MESSAGE_KEYS.every((key) => Object.hasOwn(message, key)) &&
    message.body && typeof message.body.html === "string" && typeof message.body.plain_text === "string" &&
    (typeof message.body.editable_html === "string" || message.body.editable_html === null) &&
    message.creator && Number.isInteger(message.creator.id) && Array.isArray(message.boosts)
}

function fetchRequired(message) {
  return message?.body?.truncated === true || message?.truncation?.fetch_required === true
}

function requestHeaders(json = false) {
  const headers = { "Accept": "application/json", "X-CSRF-Token": csrfToken() }
  if (json) headers["Content-Type"] = "application/json"
  return headers
}

async function jsonResponse(response) {
  if (!response.ok) throw new Error(`Request failed (${response.status})`)
  if (response.status === 204) return null
  return await response.json()
}

function safeId() {
  return globalThis.crypto?.randomUUID?.() || Math.random().toString(36).slice(2)
}

function plainText(value) {
  const template = document.createElement("template")
  template.innerHTML = value
  return template.content.textContent?.trim() || ""
}

document.addEventListener("livewire:init", () => {
const Alpine = window.Alpine

Alpine.data("messageStream", (options) => ({
  files: [],
  toolbarOpen: false,
  typingNames: "",
  streamError: "",
  upToDate: !location.pathname.includes("@"),
  lastUpdatedAt: 0,
  connectedOnce: false,
  disconnectedAt: null,

  init() {
    this.messagesById = new Map()
    this.messagesByClientId = new Map()
    this.typingUsers = new Map()
    this.editing = new Map()
    this.mutationVersion = 0
    this.pageLoading = false
    this.exhaustedPageAnchors = new Map()
    this.hiddenAt = null

    this.$refs.messages.querySelectorAll("[data-message-id]").forEach((message) => this.indexMessage(message))
    this.formatMessages()
    this.restoreDraft()
    this.observeEdges()
    this.subscribe()

    this.releaseConnection = onConnectionChange((connected) => this.connectionChanged(connected))
    this.typingTimer = setInterval(() => this.refreshTyping(), 1_000)
    this.visibilityHandler = () => this.visibilityChanged()
    this.onlineHandler = () => reconnect()
    document.addEventListener("visibilitychange", this.visibilityHandler)
    window.addEventListener("online", this.onlineHandler)

    this.$nextTick(() => {
      if (this.upToDate) this.scrollToLatest(true)
      if (!this.usingTouchDevice) this.$refs.editor?.focus()
    })
  },

  destroy() {
    this.releaseConnection?.()
    clearInterval(this.typingTimer)
    clearTimeout(this.offlineTimer)
    this.edgeObserver?.disconnect()
    document.removeEventListener("visibilitychange", this.visibilityHandler)
    window.removeEventListener("online", this.onlineHandler)

    const echo = getEcho()
    echo?.leave(`rooms.${options.roomId}`)
    echo?.leave(`rooms.${options.roomId}.typing`)
    echo?.leave(`rooms.${options.roomId}.presence`)
    echo?.leave(`users.${options.userId}.rooms`)
    echo?.leave(`users.${options.userId}.unreads`)
    echo?.leave(`users.${options.userId}.reads`)
  },

  subscribe() {
    const echo = getEcho()
    if (!echo) return

    this.roomChannel = echo.private(`rooms.${options.roomId}`)
      .listen(".message.posted", ({ message }) => this.receiveMutation(() => this.receiveMessage(message)))
      .listen(".message.updated", ({ message }) => this.receiveMutation(() => this.receiveMessage(message)))
      .listen(".message.deleted", ({ message }) => this.receiveMutation(() => this.removeMessage(message)))
      .listen(".boost.added", (payload) => this.receiveMutation(() => this.addBoost(payload.message_id, payload.boost)))
      .listen(".boost.removed", (payload) => this.receiveMutation(() => this.removeBoost(payload.message_id, payload.boost.id)))

    this.typingChannel = echo.private(`rooms.${options.roomId}.typing`)
      .listen(".typing", (payload) => this.receiveTyping(payload))
    this.presenceChannel = echo.join(`rooms.${options.roomId}.presence`)
    this.sidebarChannel = echo.private(`users.${options.userId}.rooms`)
      .listen(".sidebar.changed", () => this.refreshSidebar())
    this.unreadChannel = echo.private(`users.${options.userId}.unreads`)
      .listen(".unread", ({ roomId }) => this.setRoomUnread(roomId, true))
    this.readChannel = echo.private(`users.${options.userId}.reads`)
      .listen(".read", ({ room_id: roomId }) => this.setRoomUnread(roomId, false))
  },

  receiveMutation(apply) {
    this.mutationVersion++
    apply()
  },

  async receiveMessage(message) {
    const existing = this.messagesByClientId.get(String(message?.client_message_id || ""))
    if (!this.upToDate && !existing) {
      this.$refs.latest.hidden = false
      return
    }

    const wasNearLatest = this.nearLatest()
    const resolved = await this.resolveMessage(message)
    if (!resolved) return
    this.upsertMessage(resolved)
    if (wasNearLatest) this.scrollToLatest()
    else this.$refs.latest.hidden = false
  },

  async resolveMessage(message) {
    if (fetchRequired(message) && typeof message?.url === "string") {
      try {
        message = await jsonResponse(await fetch(message.url, { headers: requestHeaders() }))
      } catch {
        this.streamError = "A message could not be loaded. Reconnect to try again."
        return null
      }
    }

    if (!completeMessage(message)) {
      this.streamError = "A realtime message had an invalid shape and was ignored."
      return null
    }

    return message
  },

  upsertMessage(message) {
    let element = this.messagesById.get(Number(message.id)) || this.messagesByClientId.get(String(message.client_message_id))
    if (!element) {
      element = this.$refs.messageTemplate.content.firstElementChild.cloneNode(true)
      this.$refs.messages.append(element)
    }

    this.fillMessage(element, message)
    this.indexMessage(element)
    if (!this.editing.has(element)) this.insertInOrder(element)
    this.trimMessages()
    this.formatMessages()
    this.observeEdges()
    return element
  },

  fillMessage(element, message) {
    const editState = this.editing.get(element)
    element.id = `message_${message.client_message_id}`
    element.dataset.messageId = message.id
    element.dataset.clientMessageId = message.client_message_id
    element.dataset.userId = message.creator.id
    element.dataset.messageTimestamp = Date.parse(message.created_at)
    element.dataset.messageUpdatedAt = Date.parse(message.updated_at)
    element.dataset.messageUrl = message.url
    if (typeof message.body.editable_html === "string") element.dataset.messageEditableBody = message.body.editable_html
    else delete element.dataset.messageEditableBody
    element.dataset.mentionIds = message.mentions.map(({ id }) => Number(id)).join(",")
    element.classList.remove("message--failed")

    const permalink = element.querySelector("[data-stream-part=permalink]")
    permalink.href = message.url.replace(/\/messages\//, "/@")
    element.querySelectorAll("[data-stream-time]").forEach((time) => time.dateTime = message.created_at)

    const authorLink = element.querySelector("[data-stream-part=author-link]")
    authorLink.href = `/users/${message.creator.id}`
    authorLink.title = message.creator.name
    const avatar = element.querySelector("[data-stream-part=avatar]")
    avatar.src = message.creator.avatar_url
    avatar.alt = ""
    element.querySelector("[data-stream-part=author]").textContent = message.creator.name
    element.querySelector("[data-stream-part=room]").textContent = message.room.name || "Direct message"

    const presentation = element.querySelector("[data-stream-part=presentation]")
    if (editState) {
      editState.pendingMessage = message
      editState.pendingDelete = false
    } else {
      presentation.replaceChildren(this.presentationFor(message))
    }
    this.renderBoosts(element, message.boosts)

    const canAdminister = options.isAdmin || Number(message.creator.id) === Number(options.userId)
    element.querySelectorAll("[data-owner-action]").forEach((action) => action.hidden = !canAdminister)
  },

  presentationFor(message) {
    const attachment = message.attachment
    if (!attachment) {
      const body = document.createElement("div")
      body.className = "lexxy-content"
      if (message._optimisticPlain) body.textContent = message.body.plain_text
      else body.innerHTML = message.body.html // MessageResource HTML is server-sanitized.
      return body
    }

    if (String(attachment.content_type).startsWith("video/")) {
      const video = document.createElement("video")
      video.src = attachment.url
      if (attachment.representation_url) video.poster = attachment.representation_url
      video.controls = true
      video.preload = "none"
      video.className = "message__attachment"
      return video
    }

    if (String(attachment.content_type).startsWith("image/") || attachment.content_type === "application/pdf") {
      const link = document.createElement("a")
      link.href = attachment.url
      link.className = "flex"
      link.dataset.streamAction = "lightbox"
      link.dataset.downloadUrl = `${attachment.url}?disposition=attachment`
      const image = document.createElement("img")
      image.src = attachment.representation_url || attachment.url
      image.alt = attachment.filename
      image.className = "message__attachment"
      image.loading = "lazy"
      link.append(image)
      return link
    }

    const download = document.createElement("a")
    download.href = `${attachment.url}?disposition=attachment`
    download.textContent = attachment.filename
    return download
  },

  renderBoosts(messageElement, boosts) {
    const container = messageElement.querySelector("[data-stream-part=boosts]")
    container.replaceChildren(...boosts.map((boost) => this.boostElement(boost)))
  },

  boostElement(boost) {
    const element = document.createElement("div")
    element.id = `boost_${boost.id}`
    element.className = "boost boost-item flex-inline max-width align-center fill-white gap"
    element.dataset.boostId = boost.id
    element.dataset.boosterId = boost.booster.id

    const avatar = document.createElement("figure")
    avatar.className = "avatar boost__avatar flex-item-no-shrink"
    const link = document.createElement("a")
    link.className = "btn avatar"
    link.href = `/users/${boost.booster.id}`
    const image = document.createElement("img")
    image.src = boost.booster.avatar_url || `/users/${boost.booster.id}/avatar`
    image.width = 48
    image.height = 48
    image.alt = `${boost.booster.name} boosted ${boost.content}`
    link.append(image)
    avatar.append(link)

    const content = document.createElement("span")
    content.className = "txt-small"
    content.textContent = boost.content
    element.append(avatar, content)

    if (Number(boost.booster.id) === Number(options.userId)) {
      content.setAttribute("role", "button")
      content.tabIndex = 0
      content.setAttribute("aria-describedby", "delete_boost_accessible_label")
      content.dataset.streamAction = "reveal-boost"

      const remove = document.createElement("button")
      remove.type = "button"
      remove.className = "btn btn--negative boost__delete"
      remove.dataset.streamAction = "remove-boost"
      remove.setAttribute("aria-label", "Delete this boost")
      remove.textContent = "−"
      element.append(remove)
    }

    return element
  },

  indexMessage(element) {
    const id = Number(element.dataset.messageId)
    const clientId = String(element.dataset.clientMessageId || element.id.replace(/^message_/, ""))
    if (id) this.messagesById.set(id, element)
    if (clientId) this.messagesByClientId.set(clientId, element)
    this.lastUpdatedAt = Math.max(this.lastUpdatedAt, Number(element.dataset.messageUpdatedAt) || 0)
  },

  removeMessage(message) {
    const element = this.messagesById.get(Number(message?.id)) || this.messagesByClientId.get(String(message?.client_message_id || ""))
    if (!element) return
    const editState = this.editing.get(element)
    if (editState) {
      editState.pendingMessage = null
      editState.pendingDelete = true
      return
    }
    this.unindexMessage(element)
    element.remove()
    this.formatMessages()
    this.observeEdges()
  },

  unindexMessage(element) {
    this.messagesById.delete(Number(element.dataset.messageId))
    this.messagesByClientId.delete(String(element.dataset.clientMessageId || ""))
  },

  addBoost(messageId, boost) {
    const message = this.messagesById.get(Number(messageId))
    if (!message || message.querySelector(`[data-boost-id="${Number(boost.id)}"]`)) return
    message.querySelector("[data-stream-part=boosts]").append(this.boostElement(boost))
  },

  removeBoost(messageId, boostId) {
    this.messagesById.get(Number(messageId))?.querySelector(`[data-boost-id="${Number(boostId)}"]`)?.remove()
  },

  async handleMessageAction(event) {
    const action = event.target.closest("[data-stream-action]")
    if (!action) return
    const message = action.closest("[data-message-id]")
    if (!message) return

    event.preventDefault()
    const name = action.dataset.streamAction
    if (name === "reveal-boost") this.revealBoost(action)
    if (name === "boost") await this.createBoost(message, action.dataset.boostContent)
    if (name === "custom-boost") {
      const content = prompt("Boost")
      if (content) await this.createBoost(message, content.slice(0, 16))
    }
    if (name === "remove-boost") await this.destroyBoost(message, action.closest("[data-boost-id]"))
    if (name === "edit") this.startEdit(message)
    if (name === "delete") await this.destroyMessage(message)
    if (name === "copy") await navigator.clipboard?.writeText(message.dataset.messageUrl)
    if (name === "reply") this.replyTo(message)
    if (name === "lightbox") this.$dispatch("campfire:lightbox", { url: action.href, download: action.dataset.downloadUrl })
  },

  revealBoost(content) {
    const boost = content.closest("[data-boost-id]")
    if (!boost || Number(boost.dataset.boosterId) !== Number(options.userId)) return

    boost.classList.toggle("expanded")
    boost.querySelector('[data-stream-action="remove-boost"]')?.focus()
  },

  handleBoostReveal(event) {
    const content = event.target.closest('[data-stream-action="reveal-boost"]')
    if (!content) return

    event.preventDefault()
    this.revealBoost(content)
  },

  async createBoost(message, content) {
    if (!content) return
    const temporaryId = `pending-${safeId()}`
    const optimistic = { id: temporaryId, content, created_at: new Date().toISOString(), booster: { id: options.userId, name: options.userName, avatar_url: options.userAvatarUrl } }
    const pending = this.boostElement(optimistic)
    message.querySelector("[data-stream-part=boosts]").append(pending)

    const form = new FormData()
    form.append("boost[content]", content)
    try {
      const payload = await jsonResponse(await fetch(`/messages/${message.dataset.messageId}/boosts`, { method: "POST", headers: requestHeaders(), body: form }))
      this.addBoost(message.dataset.messageId, payload.boost)
      pending.remove()
    } catch {
      pending.remove()
      this.streamError = "The boost was not saved."
    }
  },

  async destroyBoost(message, boost) {
    if (!boost) return
    const next = boost.nextSibling
    boost.remove()
    try {
      await jsonResponse(await fetch(`/messages/${message.dataset.messageId}/boosts/${boost.dataset.boostId}`, { method: "DELETE", headers: requestHeaders() }))
    } catch {
      message.querySelector("[data-stream-part=boosts]").insertBefore(boost, next)
      this.streamError = "The boost could not be removed."
    }
  },

  async startEdit(message) {
    if (this.editing.has(message)) return
    const messageId = Number(message.dataset.messageId)
    const activeElement = document.activeElement
    const restoreAction = message.contains(activeElement) ? activeElement.closest("[data-stream-action]")?.dataset.streamAction : null
    const editableBody = await this.editableBody(message)
    if (editableBody === null) return
    const indexedMessage = this.messagesById.get(messageId)
    message = indexedMessage?.isConnected ? indexedMessage : message.isConnected ? message : null
    if (!message || this.editing.has(message)) return
    const presentation = message.querySelector("[data-stream-part=presentation]")
    if (!presentation) return
    const original = presentation.cloneNode(true)
    const restoreFocus = restoreAction ? message.querySelector(`[data-stream-action="${restoreAction}"]`) : activeElement?.isConnected ? activeElement : null
    const editor = document.createElement("lexxy-editor")
    editor.className = "input lexxy-content"
    editor.setAttribute("aria-label", "Edit message")
    editor.setAttribute("autofocus", "")
    editor.addEventListener("keydown", (event) => this.editKeydown(event, message))

    const actions = document.createElement("div")
    actions.className = "message__edit-btns"
    const save = document.createElement("button")
    save.type = "button"
    save.className = "btn btn--reversed"
    save.dataset.streamAction = "save-edit"
    save.textContent = "Save changes"
    const cancel = document.createElement("button")
    cancel.type = "button"
    cancel.className = "btn"
    cancel.dataset.streamAction = "cancel-edit"
    cancel.textContent = "Cancel"
    actions.append(save, cancel)
    presentation.replaceChildren(editor, actions)
    this.editing.set(message, { original, restoreFocus })
    this.$nextTick(() => {
      if (!editor.isConnected || !editor.hasAttribute("connected") || !editor.querySelector('[contenteditable="true"]')) {
        this.cancelEdit(message)
        this.streamError = "The message editor could not be initialized."
        return
      }
      editor.value = editableBody
      editor.focus()
    })
  },

  async editableBody(message) {
    if (Object.hasOwn(message.dataset, "messageEditableBody")) return message.dataset.messageEditableBody

    try {
      const resource = await jsonResponse(await fetch(message.dataset.messageUrl, { headers: requestHeaders() }))
      if (typeof resource?.body?.editable_html !== "string") throw new Error("Missing editable body")
      message.dataset.messageEditableBody = resource.body.editable_html
      return resource.body.editable_html
    } catch {
      this.streamError = "The message could not be opened for editing."
      return null
    }
  },

  editKeydown(event, message) {
    if (event.key === "Escape") {
      event.preventDefault()
      this.cancelEdit(message)
    } else if (event.key === "Enter" && (event.ctrlKey || event.metaKey)) {
      event.preventDefault()
      message.querySelector("[data-stream-action=save-edit]")?.click()
    }
  },

  cancelEdit(message) {
    const state = this.editing.get(message)
    if (!state) return
    message.querySelector("[data-stream-part=presentation]").replaceWith(state.original)
    this.editing.delete(message)
    if (state.pendingDelete) {
      this.removeMessage({ id: message.dataset.messageId, client_message_id: message.dataset.clientMessageId })
      return
    }
    if (state.pendingMessage) this.upsertMessage(state.pendingMessage)
    state.restoreFocus?.focus()
  },

  async handleEditAction(event) {
    const action = event.target.closest("[data-stream-action=save-edit], [data-stream-action=cancel-edit]")
    if (!action) return
    const message = action.closest("[data-message-id]")
    if (action.dataset.streamAction === "cancel-edit") {
      this.cancelEdit(message)
      return
    }

    const editor = message.querySelector("lexxy-editor")
    const form = new FormData()
    form.append("_method", "PATCH")
    form.append("message[body]", editor.value)
    try {
      const updated = await jsonResponse(await fetch(message.dataset.messageUrl, { method: "POST", headers: requestHeaders(), body: form }))
      const restoreFocus = this.editing.get(message)?.restoreFocus
      this.editing.delete(message)
      const resolved = await this.resolveMessage(updated)
      if (resolved) this.upsertMessage(resolved)
      restoreFocus?.focus()
    } catch {
      this.streamError = "The message edit was not saved."
    }
  },

  async destroyMessage(message) {
    if (!confirm("Are you sure you want to delete this message?")) return
    const next = message.nextSibling
    this.unindexMessage(message)
    message.remove()
    this.formatMessages()
    try {
      await jsonResponse(await fetch(message.dataset.messageUrl, { method: "DELETE", headers: requestHeaders() }))
    } catch {
      this.$refs.messages.insertBefore(message, next)
      this.indexMessage(message)
      this.formatMessages()
      this.streamError = "The message could not be deleted."
    }
  },

  replyTo(message) {
    const source = message.querySelector("[data-stream-part=presentation]").cloneNode(true)
    source.querySelectorAll(".mention").forEach((mention) => mention.replaceWith(mention.textContent.trim()))
    source.querySelectorAll(".og-embed").forEach((embed) => embed.remove())
    const wrapper = document.createElement("div")
    const quote = document.createElement("blockquote")
    quote.append(...source.childNodes)
    const cite = document.createElement("cite")
    cite.textContent = `${message.querySelector("[data-stream-part=author]").textContent} `
    const link = document.createElement("a")
    link.href = message.dataset.messageUrl
    link.textContent = "#"
    cite.append(link)
    wrapper.append(quote, cite, document.createElement("p"))
    this.$refs.editor.value = wrapper.innerHTML
    this.$refs.editor.focus()
  },

  composerKeydown(event) {
    if (event.key === "ArrowUp" && this.editorBlank && this.upToDate) {
      const own = Array.from(this.$refs.messages.children).filter((message) => Number(message.dataset.userId) === Number(options.userId)).pop()
      if (own) this.startEdit(own)
      return
    }
    if (event.key !== "Enter" || event.target.hasOpenPrompt) return
    const plainEnter = !event.shiftKey && !event.isComposing
    if (!this.usingTouchDevice && (event.metaKey || event.ctrlKey || (plainEnter && !this.toolbarOpen))) {
      event.preventDefault()
      this.submitComposer()
    }
  },

  async submitComposer() {
    const editor = this.$refs.editor
    const body = editor.value || ""
    const text = editor.toString?.().trim() || plainText(body)
    const files = this.files.slice()
    if (!text && files.length === 0) return

    try {
      await this.ensureLatest()
    } catch {
      this.streamError = "The latest messages could not be loaded. Your draft was not sent."
      return
    }

    const posts = []
    if (text) posts.push(this.postMessage({ body, text }))
    for (const file of files) posts.push(this.postMessage({ file, text: file.name }))

    this.files = []
    editor.value = ""
    localStorage.removeItem(this.draftKey)
    this.stopTyping()
    this.toolbarOpen = false

    await Promise.all(posts)
    editor.focus()
  },

  async postMessage({ body = "", text, file = null }) {
    const clientId = safeId()
    const now = new Date().toISOString()
    const optimistic = {
      id: 0,
      client_message_id: clientId,
      created_at: now,
      updated_at: now,
      body: { plain_text: text, html: "", editable_html: body, truncated: false },
      creator: { id: options.userId, name: options.userName, avatar_url: options.userAvatarUrl, role: options.isAdmin ? "administrator" : "member" },
      room: { id: options.roomId, name: options.roomName, type: options.roomType },
      url: "",
      attachment: null,
      boosts: [],
      mentions: [],
      _optimisticPlain: true,
    }
    this.upsertMessage(optimistic)
    const pending = this.messagesByClientId.get(clientId)
    this.scrollToLatest(true)

    const form = new FormData()
    form.append("message[client_message_id]", clientId)
    if (body) form.append("message[body]", body)
    if (file) form.append("message[attachment]", file)

    try {
      const created = await jsonResponse(await fetch(`/rooms/${options.roomId}/messages`, { method: "POST", headers: requestHeaders(), body: form }))
      const resolved = await this.resolveMessage(created)
      if (resolved) this.upsertMessage(resolved)
    } catch {
      pending?.classList.add("message--failed")
      this.streamError = file ? `${file.name} was not uploaded.` : "The message was not sent."
    }
  },

  addFiles(fileList) {
    this.files = [ ...this.files, ...Array.from(fileList) ].sort((a, b) => a.name.localeCompare(b.name))
  },

  removeFile(index) {
    this.files = this.files.filter((_file, position) => position !== index)
  },

  dropFiles(event) {
    event.preventDefault()
    this.addFiles(event.dataTransfer.files)
  },

  pasteFiles(event) {
    if (event.clipboardData.files.length === 0) return
    event.preventDefault()
    this.addFiles(event.clipboardData.files)
  },

  editorChanged() {
    const value = this.$refs.editor.value || ""
    if (this.editorBlank) localStorage.removeItem(this.draftKey)
    else localStorage.setItem(this.draftKey, value)

    if (this.editorBlank) this.stopTyping()
    else this.startTyping()
  },

  restoreDraft() {
    const draft = localStorage.getItem(this.draftKey)
    if (draft) this.$nextTick(() => this.$refs.editor.value = draft)
  },

  startTyping() {
    const now = Date.now()
    if (this.lastTypingSent && now - this.lastTypingSent < 1_000) return
    this.lastTypingSent = now
    this.sendTyping("start")
  },

  stopTyping() {
    this.lastTypingSent = 0
    this.sendTyping("stop")
  },

  async sendTyping(action) {
    try {
      await fetch(`/rooms/${options.roomId}/typing`, { method: "POST", headers: requestHeaders(true), body: JSON.stringify({ action }), keepalive: true })
    } catch {}
  },

  receiveTyping({ action, user }) {
    if (!user || Number(user.id) === Number(options.userId)) return
    if (action === "start") this.typingUsers.set(user.name, Date.now())
    else this.typingUsers.delete(user.name)
    this.refreshTyping()
  },

  refreshTyping() {
    const cutoff = Date.now() - TYPING_TIMEOUT
    for (const [name, timestamp] of this.typingUsers) if (timestamp < cutoff) this.typingUsers.delete(name)
    this.typingNames = Array.from(this.typingUsers.keys()).sort().join(", ")
  },

  connectionChanged(connected) {
    clearTimeout(this.offlineTimer)
    if (connected) {
      this.recover()
      this.connectedOnce = true
      this.disconnectedAt = null
      this.$refs.fields.disabled = false
      return
    }

    this.disconnectedAt = Date.now()
    this.offlineTimer = setTimeout(() => this.$refs.fields.disabled = true, OFFLINE_DELAY)
  },

  visibilityChanged() {
    if (document.visibilityState === "hidden") {
      this.hiddenAt = Date.now()
    } else if (this.hiddenAt && Date.now() - this.hiddenAt > 60_000) {
      this.hiddenAt = null
      this.recover()
    }
  },

  async recover() {
    try {
      let startedAt
      do {
        startedAt = this.mutationVersion
        await this.replaceCurrentWindow(await this.authoritativeSnapshot())
      } while (startedAt !== this.mutationVersion)
    } catch {
      this.streamError = "Messages could not be refreshed after reconnecting."
    }
  },

  async authoritativeSnapshot() {
    const response = await fetch(`/rooms/${options.roomId}/messages`, { headers: requestHeaders() })
    return response.status === 204 ? [] : await jsonResponse(response)
  },

  async replaceCurrentWindow(messages) {
    const wasNearLatest = this.nearLatest()
    const distanceFromBottom = this.$refs.messages.scrollHeight - this.$refs.messages.scrollTop
    const retained = Array.from(this.$refs.messages.children).filter((message) => !Number(message.dataset.messageId) || this.editing.has(message))
    Array.from(this.$refs.messages.children).filter((message) => !retained.includes(message)).forEach((message) => message.remove())
    this.messagesById.clear()
    this.messagesByClientId.clear()
    this.lastUpdatedAt = 0
    retained.forEach((message) => this.indexMessage(message))
    this.upToDate = true
    const seen = new Set()
    for (const message of messages) {
      const resolved = await this.resolveMessage(message)
      if (resolved) seen.add(this.upsertMessage(resolved))
    }
    retained.filter((message) => this.editing.has(message) && Number(message.dataset.messageId) && !seen.has(message))
      .forEach((message) => this.removeMessage({ id: message.dataset.messageId, client_message_id: message.dataset.clientMessageId }))
    if (wasNearLatest) this.scrollToLatest(true)
    else this.$refs.messages.scrollTop = Math.max(0, this.$refs.messages.scrollHeight - distanceFromBottom)
  },

  observeEdges() {
    this.$nextTick(() => {
      this.edgeObserver?.disconnect()
      this.edgeObserver = new IntersectionObserver((entries) => {
        for (const entry of entries) {
          if (!entry.isIntersecting || this.pageLoading) continue
          if (entry.target === this.$refs.messages.firstElementChild) this.loadPage("before", entry.target.dataset.messageId)
          if (entry.target === this.$refs.messages.lastElementChild && !this.upToDate) this.loadPage("after", entry.target.dataset.messageId)
        }
      }, { root: this.$refs.messages })
      if (this.$refs.messages.firstElementChild) this.edgeObserver.observe(this.$refs.messages.firstElementChild)
      if (this.$refs.messages.lastElementChild) this.edgeObserver.observe(this.$refs.messages.lastElementChild)
    })
  },

  async loadPage(direction, anchor) {
    anchor = String(anchor || "")
    if (!anchor || this.pageLoading || this.exhaustedPageAnchors.get(direction) === anchor) return
    this.pageLoading = true
    const oldHeight = this.$refs.messages.scrollHeight
    const oldTop = this.$refs.messages.scrollTop
    try {
      const response = await fetch(`/rooms/${options.roomId}/messages?${direction}=${anchor}`, { headers: requestHeaders() })
      if (response.status === 204) {
        this.exhaustedPageAnchors.set(direction, anchor)
        if (direction === "after") this.upToDate = true
        return
      }
      const messages = await jsonResponse(response)
      for (const message of messages) {
        const resolved = await this.resolveMessage(message)
        if (resolved) this.upsertMessage(resolved)
      }
      if (direction === "before") this.$refs.messages.scrollTop = oldTop + this.$refs.messages.scrollHeight - oldHeight
    } catch {
      this.streamError = "More message history could not be loaded."
    } finally {
      this.pageLoading = false
      this.observeEdges()
    }
  },

  async ensureLatest() {
    if (this.upToDate) return
    await this.replaceCurrentWindow(await this.authoritativeSnapshot())
  },

  async returnToLatest() {
    this.$refs.latest.hidden = true
    try {
      await this.ensureLatest()
      this.scrollToLatest(true)
    } catch {
      this.streamError = "The latest messages could not be loaded."
    }
  },

  insertInOrder(element) {
    const timestamp = Number(element.dataset.messageTimestamp)
    const id = Number(element.dataset.messageId)
    const following = Array.from(this.$refs.messages.children).find((candidate) => candidate !== element && (
      Number(candidate.dataset.messageTimestamp) > timestamp ||
      (Number(candidate.dataset.messageTimestamp) === timestamp && Number(candidate.dataset.messageId) > id)
    ))
    this.$refs.messages.insertBefore(element, following || null)
  },

  trimMessages() {
    while (this.$refs.messages.children.length > MAX_MESSAGES) {
      const removeFromTop = this.upToDate
      const messages = Array.from(this.$refs.messages.children)
      const element = (removeFromTop ? messages : messages.reverse()).find((message) => !this.editing.has(message))
      if (!element) return
      this.unindexMessage(element)
      element.remove()
      if (!removeFromTop) this.upToDate = false
    }
  },

  formatMessages() {
    const messages = Array.from(this.$refs.messages.children)
    const day = new Intl.DateTimeFormat(undefined, { dateStyle: "long" })
    const shortDay = new Intl.DateTimeFormat(undefined, { dateStyle: "short" })
    const time = new Intl.DateTimeFormat(undefined, { timeStyle: "short" })

    messages.forEach((message, index) => {
      const timestamp = Number(message.dataset.messageTimestamp)
      const date = new Date(timestamp)
      const previous = messages[index - 1]
      const previousDate = previous ? new Date(Number(previous.dataset.messageTimestamp)) : null
      const firstOfDay = !previousDate || shortDay.format(previousDate) !== shortDay.format(date)
      const threaded = previous && previous.dataset.userId === message.dataset.userId && Math.abs(timestamp - Number(previous.dataset.messageTimestamp)) <= 300_000
      message.classList.toggle("message--first-of-day", firstOfDay)
      message.classList.toggle("message--threaded", Boolean(threaded))
      message.classList.toggle("message--me", Number(message.dataset.userId) === Number(options.userId))
      const mentionIds = String(message.dataset.mentionIds || "").split(",").filter(Boolean).map(Number)
      message.classList.toggle("message--mentioned", mentionIds.includes(Number(options.userId)))
      message.classList.add("message--formatted")
      message.querySelectorAll("[data-stream-part=presentation] pre:not([data-highlighted])").forEach((block) => {
        block.querySelectorAll("br").forEach((lineBreak) => lineBreak.replaceWith("\n"))
        if (Array.from(block.childNodes).every((node) => node.nodeType === Node.TEXT_NODE)) {
          const language = block.dataset.language
          if (language && window.hljs?.getLanguage(language)) block.classList.add(`language-${language}`)
          window.hljs?.highlightElement(block)
        }
        block.dataset.highlighted = "true"
      })
      const dateElement = message.querySelector("[data-stream-time=date]")
      const timeElement = message.querySelector("[data-stream-time=time]")
      if (dateElement) dateElement.textContent = day.format(date)
      if (timeElement) timeElement.textContent = time.format(date)
    })
  },

  scrollToLatest(force = false) {
    if (force || this.nearLatest()) this.$refs.messages.scrollTop = this.$refs.messages.scrollHeight
  },

  nearLatest() {
    return this.$refs.messages.scrollHeight - this.$refs.messages.scrollTop - this.$refs.messages.clientHeight <= 100
  },

  async setRoomUnread(roomId, unread) {
    let room = document.querySelector(`[data-room-id="${Number(roomId)}"]`)
    if (!room && unread) {
      await this.refreshSidebar()
      room = document.querySelector(`[data-room-id="${Number(roomId)}"]`)
    }
    if (room && Number(roomId) !== Number(options.roomId)) room.classList.toggle("unread", unread)
    if (room && Number(roomId) === Number(options.roomId)) room.classList.remove("unread")
    const count = document.querySelectorAll("[data-room-id].unread").length
    if ("setAppBadge" in navigator && count > 0) navigator.setAppBadge(count)
    else if ("clearAppBadge" in navigator) navigator.clearAppBadge()
  },

  async refreshSidebar() {
    try {
      const response = await fetch("/users/me/sidebar", { headers: { "Accept": "text/html" } })
      if (!response.ok) throw new Error(`Request failed (${response.status})`)
      const template = document.createElement("template")
      template.innerHTML = await response.text()
      const sidebar = template.content.firstElementChild
      if (!sidebar || sidebar.id !== "user_sidebar") throw new Error("Invalid sidebar")
      document.querySelector("#user_sidebar")?.replaceWith(sidebar)
    } catch {
      this.streamError = "The room list could not be refreshed."
    }
  },

  get editorBlank() {
    return this.$refs.editor?.isBlank ?? !plainText(this.$refs.editor?.value || "")
  },

  get draftKey() {
    return `composer-draft-${options.roomId}`
  },

  get usingTouchDevice() {
    return "ontouchstart" in window || navigator.maxTouchPoints > 0
  },
}))
})
