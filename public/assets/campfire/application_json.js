// The Laravel-native entry point intentionally has no Turbo or Action Cable compatibility layer.
import "campfire/message_stream"
import "campfire/confirm"
import "campfire/alpine"
import "initializers"
import "controllers"

window.Livewire.start()
