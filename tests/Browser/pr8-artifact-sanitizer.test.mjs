#!/usr/bin/env node

import assert from "node:assert/strict"
import { mkdtempSync, readFileSync, rmSync } from "node:fs"
import { tmpdir } from "node:os"
import { join } from "node:path"
import { createArtifactWriter } from "./pr8-artifact.mjs"

const credentials = [
  "admin@example.test",
  "admin-password-secret",
  "join-code-secret",
  "member@example.test",
  "member-password-secret",
  "signed-transfer-secret",
]
const artifacts = [
  {
    ok: true,
    provenance: { admin: credentials[0], [credentials[4]]: credentials[5] },
    nested: [{ password: credentials[1], member: credentials[3], url: `https://${credentials[0]}:${credentials[1]}@campfire.test/join/${credentials[2]}?email=${credentials[3]}#fragment` }],
    expected_negative_evidence: [{ control: "join-code locked-property substitution", status: 500 }],
  },
  {
    ok: false,
    member: { email: credentials[3], password: credentials[4], join: credentials[2], transfer: credentials[5] },
    error: `Navigation to https://campfire.test/session/transfers/${credentials[5]}?password=${credentials[1]}#fragment failed for ${credentials[0]}`,
    console_problems: [{ text: `join failed at /join/${credentials[2]}?member=${credentials[3]}#fragment for ${credentials[0]}` }],
  },
]

const directory = mkdtempSync(join(tmpdir(), "pr8-artifact-"))
try {
  for (const [ index, artifact ] of artifacts.entries()) {
    const path = join(directory, `${index}.json`)
    createArtifactWriter(path, credentials)(artifact)
    const serialized = readFileSync(path, "utf8")
    for (const credential of credentials) assert.ok(!serialized.includes(credential), `artifact contains ${credential}`)
    assert.ok(!serialized.includes("?"), "artifact URL retained its query")
    assert.ok(!serialized.includes("#fragment"), "artifact URL retained its fragment")
    assert.ok(serialized.includes(index === 0 ? "/join/[REDACTED]" : "/session/transfers/[REDACTED]"))
  }
  assert.match(readFileSync(join(directory, "0.json"), "utf8"), /"control": "join-code locked-property substitution"/)
  assert.match(readFileSync(join(directory, "0.json"), "utf8"), /"status": 500/)
} finally {
  rmSync(directory, { recursive: true, force: true })
}
