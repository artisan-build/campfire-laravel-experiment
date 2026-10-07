import * as Lexxy from "../../lexxy-a21f41d4.js"
import CiteNode from "./cite_node-b4d2b89a.js"

export default class CampfireRichTextExtension extends Lexxy.Extension {
  get allowedElements() {
    return [
      "cite",
      "figure",
      "figcaption",
      "actiontext-opengraph-embed",
      { tag: "div", attributes: [ "sgid" ] },
      { tag: "span", attributes: [ "sgid" ] },
      { tag: "img", attributes: [ "alt" ] },
      { tag: "a", attributes: [ "rel", "target" ] }
    ]
  }

  get lexicalExtension() {
    return this.defineExtension({
      name: "campfire/rich-text",
      nodes: [ CiteNode ]
    })
  }
}
