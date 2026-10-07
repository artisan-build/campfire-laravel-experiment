import { mkdirSync, writeFileSync } from "node:fs"
import { dirname } from "node:path"

const redacted = "[REDACTED]"

function redactCredentialRoutes(text) {
  return text
    .replace(/(\/join\/)[^/?#\s"'<>]+/g, `$1${redacted}`)
    .replace(/(\/session\/transfers\/)[^/?#\s"'<>]+/g, `$1${redacted}`)
    .replace(/(\/rooms\/\d+\/)[^/?#\s"'<>]+(\/messages(?:[/?#\s"'<>]|$))/g, `$1${redacted}$2`)
}

function sanitizeText(text, credentials) {
  let sanitized = text.replace(/https?:\/\/[^\s"'<>]+/g, value => {
    try {
      const url = new URL(value)
      url.pathname = redactCredentialRoutes(url.pathname)
      url.username = ""
      url.password = ""
      url.search = ""
      url.hash = ""

      return url.toString()
    } catch {
      return value
    }
  })
  sanitized = sanitized.replace(/(\/[A-Za-z0-9._~!$&()*+,;=:@%/-]+)[?#][^\s"'<>]*/g, "$1")
  sanitized = redactCredentialRoutes(sanitized)
  for (const credential of credentials) {
    if (typeof credential === "string" && credential) sanitized = sanitized.replaceAll(credential, redacted)
  }

  return sanitized
}

export function sanitizeArtifact(value, credentials) {
  if (typeof value === "string") return sanitizeText(value, credentials)
  if (Array.isArray(value)) return value.map(item => sanitizeArtifact(item, credentials))
  if (value && typeof value === "object") {
    return Object.fromEntries(Object.entries(value).map(([ key, item ]) => [
      sanitizeText(key, credentials),
      sanitizeArtifact(item, credentials),
    ]))
  }

  return value
}

export function createArtifactWriter(outputPath, credentials) {
  return artifact => {
    mkdirSync(dirname(outputPath), { recursive: true })
    writeFileSync(outputPath, JSON.stringify(sanitizeArtifact(artifact, credentials), null, 2))
  }
}
