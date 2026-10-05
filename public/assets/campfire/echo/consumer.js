import { getEcho, currentUserId, onConnectionChange, isConnected, reconnect } from "campfire/echo/config"
import { command } from "campfire/echo/commands"

// An Action Cable consumer shape on top of Echo, so turbo-rails' `cable.subscribeTo` and the five
// Stimulus controllers that use it keep working untouched. The channel names are Laravel's, built
// here and authorized by routes/channels.php.
function descriptorFor(params) {
  const room = params.room_id
  const me = currentUserId()

  switch (params.channel) {
    case "PresenceChannel":
      // No `command`: joining the channel IS the presence report, and presence_controller's
      // fifty-second refresh therefore sends nothing. Reverb holds the member list and the server
      // asks for it when it needs it, so an idle tab costs the app no requests at all.
      return { kind: "presence", channel: `rooms.${room}.presence` }
    case "TypingNotificationsChannel":
      return { kind: "private", channel: `rooms.${room}.typing`, event: "typing", command: `/rooms/${room}/typing` }
    case "UnreadRoomsChannel":
      return { kind: "private", channel: `users.${me}.unreads`, event: "unread" }
    case "ReadRoomsChannel":
      return { kind: "private", channel: `users.${me}.reads`, event: "read" }
    case "HeartbeatChannel":
      // Upstream's heartbeat only ever reported whether the socket was alive, which the connection
      // state already answers. No channel is needed.
      return { kind: "heartbeat" }
    default:
      return null
  }
}

class Subscription {
  constructor(consumer, params, mixin) {
    this.consumer = consumer
    this.params = params
    this.mixin = mixin || {}
    this.descriptor = descriptorFor(params)
    this.unsubscribed = false
    this.#start()
  }

  send(data) {
    // Only typing has a server-side command. A presence `present` / `absent` / `refresh` from
    // presence_controller is deliberately dropped on the floor.
    if (this.descriptor?.command && data?.action) {
      command(this.descriptor.command, { action: data.action })
    }
  }

  unsubscribe() {
    if (this.unsubscribed) return
    this.unsubscribed = true
    this.releaseConnection?.()
    // Leaving the channel is the whole of "absent": Reverb drops the member and the next lookup
    // sees it. Nothing has to be told.
    if (this.channelName) getEcho()?.leave(this.channelName)
  }

  #start() {
    const descriptor = this.descriptor
    if (!descriptor) return

    this.releaseConnection = onConnectionChange((state) => {
      if (this.unsubscribed) return
      state ? this.mixin.connected?.call(this.mixin) : this.mixin.disconnected?.call(this.mixin)
    })

    if (descriptor.kind === "heartbeat") return

    const instance = getEcho()
    if (!instance) return

    this.channelName = descriptor.channel

    if (descriptor.kind === "presence") {
      instance.join(descriptor.channel)
      return
    }

    instance.private(descriptor.channel).listen(`.${descriptor.event}`, (payload) => {
      if (!this.unsubscribed) this.mixin.received?.call(this.mixin, payload)
    })
  }
}

class Subscriptions {
  constructor(consumer) {
    this.consumer = consumer
  }

  create(params, mixin) {
    return new Subscription(this.consumer, typeof params === "string" ? { channel: params } : params, mixin)
  }
}

class Consumer {
  constructor() {
    this.subscriptions = new Subscriptions(this)
    // refresh_room_controller reaches for this when the browser comes back online.
    this.connection = {
      monitor: { visibilityDidChange: () => reconnect() },
      isOpen: () => isConnected()
    }
  }
}

export function createConsumer() {
  return new Consumer()
}
