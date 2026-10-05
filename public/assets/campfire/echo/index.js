import { cable } from "@hotwired/turbo-rails"
import { createConsumer } from "campfire/echo/consumer"
import "campfire/echo/stream_source"

// turbo-rails routes every cable.subscribeTo call through one consumer. Replacing it is the whole
// client-side change: no Stimulus controller and no helper was touched.
cable.setConsumer(createConsumer())
