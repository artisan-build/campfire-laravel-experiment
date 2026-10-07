import * as Lexxy from "../lexxy-a21f41d4.js"
import hljs from "../highlight.js/core-4e141f64.js"
import CampfireRichTextExtension from "../lib/rich_text/campfire_extension-f62f017b.js"
import bash from "../languages/bash-13136668.js"
import css from "../languages/css-a7f2e40b.js"
import diff from "../languages/diff-94a335fe.js"
import go from "../languages/go-10465864.js"
import java from "../languages/java-95f3a30f.js"
import javascript from "../languages/javascript-18eb0dd8.js"
import json from "../languages/json-60184e1b.js"
import python from "../languages/python-8f48fd1a.js"
import ruby from "../languages/ruby-287ccd0f.js"
import rust from "../languages/rust-d48f93d8.js"
import sql from "../languages/sql-642be05a.js"
import xml from "../languages/xml-fa7f1eda.js"

for (const [ name, language ] of Object.entries({ bash, css, diff, go, java, javascript, json, python, ruby, rust, sql, xml })) {
  hljs.registerLanguage(name, language)
}
window.hljs = hljs

Lexxy.configure({
  global: {
    attachmentContentTypeNamespace: "campfire",
    extensions: [ CampfireRichTextExtension ]
  },
  default: {
    toolbar: { attachments: false },
    headings: [ "h1" ]
  }
})
