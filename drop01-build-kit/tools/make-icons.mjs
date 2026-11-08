// Renders src/favicon.svg to the PNG app icons (180, 192, 512) with Playwright. Run: node tools/make-icons.mjs
import { chromium } from "./e2e/node_modules/playwright/index.mjs";
import fs from "node:fs";
const svg = fs.readFileSync(new URL("../src/favicon.svg", import.meta.url), "utf8");
const b = await chromium.launch();
for (const s of [180, 192, 512]) {
  const p = await b.newPage({ viewport: { width: s, height: s } });
  await p.setContent(`<style>html,body{margin:0;background:#09090B}svg{width:${s}px;height:${s}px;display:block}</style>${svg}`);
  await p.screenshot({ path: new URL(`../src/assets/icons/icon-${s}.png`, import.meta.url).pathname });
}
await b.close();
console.log("icons written");
