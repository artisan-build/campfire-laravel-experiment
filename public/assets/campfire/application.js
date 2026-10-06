// Campfire's entry point. The Echo consumer is installed before the Stimulus controllers load, so
// the first cable.subscribeTo already finds it.
import "@hotwired/turbo-rails"
import "campfire/echo"
import "campfire/alpine"
import "initializers"
import "controllers"
import { getEcho } from "campfire/echo/config"

const echo = getEcho()
if (echo) window.Echo = echo
window.Livewire.start()
