// Campfire's entry point. The Echo consumer is installed before the Stimulus controllers load, so
// the first cable.subscribeTo already finds it.
import "@hotwired/turbo-rails"
import "campfire/echo"
import "initializers"
import "controllers"
