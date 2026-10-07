// The Laravel-native entry point intentionally has no Turbo or Action Cable compatibility layer.
import "./lexxy.js"
import "./message_stream.js"
import "./confirm.js"
import "./alpine.js"
import { getEcho } from "./echo/config.js"

const echo = getEcho()
if (echo) window.Echo = echo
window.Livewire.start()
