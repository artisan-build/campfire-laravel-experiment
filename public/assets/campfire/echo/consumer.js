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
      return { kind: "presence", channel: `rooms.${room}.presence`, command: `/rooms/${room}/presence` }
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
    if (this.descriptor?.command && data?.action) {
      command(this.descriptor.command, { action: data.action })
    }
  }

  unsubscribe() {
    if (this.unsubscribed) return
    this.unsubscribed = true
    this.releaseConnection?.()
    if (this.descriptor?.kind === "presence" && this.present) {
      command(this.descriptor.command, { action: "absent" })
      this.present = false
    }
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
      // Joining is authorized server-side, and that is where the membership row is marked present.
      command(descriptor.command, { action: "present" })
      this.present = true
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
