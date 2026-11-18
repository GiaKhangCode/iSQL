import assert from "node:assert/strict";
import { build } from "esbuild";
import { JSDOM } from "jsdom";
import { writeFile, unlink } from "node:fs/promises";
import { tmpdir } from "node:os";
import { join } from "node:path";
import { createRequire } from "node:module";

const dom = new JSDOM('<div id="root"></div>', { url: "http://localhost" });
for (const name of ["window", "document", "navigator", "HTMLElement"]) {
  Object.defineProperty(globalThis, name, { configurable: true, value: dom.window[name] });
}
globalThis.IS_REACT_ACT_ENVIRONMENT = true;
globalThis.ResizeObserver = class { observe() {} disconnect() {} };
let reduced = false;
window.matchMedia = () => ({ matches: reduced });
Object.defineProperties(HTMLElement.prototype, {
  clientWidth: { get() { return 800; } },
  offsetWidth: { get() { return 100; } },
  offsetLeft: { get() { return this.getAttribute("role") === "tab" ? [...this.parentElement.querySelectorAll("[role=tab]")].indexOf(this) * 104 + 14 : 0; } },
});
const scrolling = [];
HTMLElement.prototype.scrollTo = function (options) {
  scrolling.push(options);
  this.scrollLeft = options.left ?? 0;
  this.dispatchEvent(new window.Event("scroll"));
};
HTMLElement.prototype.setPointerCapture = () => {};

const output = await build({
  stdin: { contents: `
    export { createElement, act, useState } from "react";
    export { createRoot } from "react-dom/client";
    export { ExamSessionSwipeTabs } from "./src/shared/components/ExamSessionSwipeTabs";
    export { LayeredExamSessionBanner } from "./src/shared/components/LayeredExamSessionBanner";
  `, resolveDir: process.cwd(), loader: "tsx" },
  bundle: true, write: false, platform: "node", format: "cjs", jsx: "automatic",
  loader: { ".css": "empty" }, define: { "process.env.NODE_ENV": '"development"', "import.meta.env.VITE_API_URL": '""' },
});
const filename = join(tmpdir(), `sqluit-exam-session-interactions-${process.pid}.cjs`);
await writeFile(filename, output.outputFiles[0].contents);
try {
  const { createElement: h, act, useState, createRoot, ExamSessionSwipeTabs, LayeredExamSessionBanner } = createRequire(import.meta.url)(filename);
  const root = createRoot(document.getElementById("root"));
  const tabs = ["All", "Upcoming", "Live", "Past"].map((id, i) => ({ id, label: id, count: [24, 0, 6, 18][i] }));
  let opened = 0;
  function Harness() {
    const [value, onChange] = useState("All");
    return h(ExamSessionSwipeTabs, { tabs, value, onChange, label: "Exam session status", toolbar: h("input", { "aria-label": "Search" }), aside: h("aside", null, "Hall of Fame") },
      id => h("div", null, id, h("button", { onClick: () => opened++ }, "Details")));
  }
  await act(async () => root.render(h(Harness)));
  const buttons = [...document.querySelectorAll("[role=tab]")];
  const pages = document.querySelector(".u048-pages");
  const selected = () => document.querySelector('[role=tab][aria-selected="true"]');
  const key = async (button, key) => act(async () => button.dispatchEvent(new window.KeyboardEvent("keydown", { key, bubbles: true })));
  const pointer = async (type, x, y = 100, after) => act(async () => {
    const event = new window.MouseEvent(type, { bubbles: true, clientX: x, clientY: y, button: 0, cancelable: true });
    Object.defineProperties(event, { pointerType: { value: "mouse" }, pointerId: { value: 1 } });
    pages.dispatchEvent(event);
    after?.();
  });
  assert.equal(selected().textContent, "All24");
  await key(buttons[0], "ArrowRight");
  assert.equal(selected().textContent, "Upcoming0", "Keyboard can select an empty filter");
  assert.equal(document.activeElement, buttons[1]);
  assert.equal(document.querySelector('[role=tabpanel][aria-hidden="true"]').inert, true);
  assert.equal(document.getElementById(buttons[1].getAttribute("aria-controls")).inert, false);
  await key(buttons[1], "End");
  assert.equal(selected(), buttons[3]);
  await key(buttons[3], "Home");
  assert.equal(selected(), buttons[0]);

  await act(async () => { pages.scrollLeft = 400; pages.dispatchEvent(new window.Event("scroll")); });
  assert.equal(parseFloat(document.querySelector(".u048-line").style.width), 106, "Underline stretches continuously halfway through a swipe");
  reduced = true;
  await act(async () => { pages.dispatchEvent(new window.Event("scroll")); });
  assert.equal(parseFloat(document.querySelector(".u048-line").style.width), 80, "Reduced motion retains position without elastic stretch");
  await key(buttons[1], "Home");
  assert.equal(scrolling.at(-1).behavior, "auto");
  await pointer("pointerdown", 700);
  await pointer("pointermove", 100);
  await pointer("pointerup", 100, 100, () => document.querySelector('[role=tabpanel][aria-hidden="false"] button').click());
  assert.equal(selected(), buttons[1], "Mouse drag snaps to the next panel");
  assert.equal(opened, 0, "Dragging suppresses the resulting navigation click");

  await act(async () => root.render(h(LayeredExamSessionBanner, { id: "real-exam-session", className: "featured-exam-session", label: "Open exam session", onOpen: () => opened++, bannerUrl: "/uploads/banner.png" }, h("h3", null, "SQL"))));
  const banner = document.querySelector(".u049");
  assert.match(document.querySelector(".u049-image").style.backgroundImage, /uploads\/banner.png/);
  await act(async () => banner.focus());
  assert.ok(banner.classList.contains("is-open"), "Focus reveals the summary");
  await key(banner, "Escape");
  assert.ok(!banner.classList.contains("is-open"));
  await act(async () => banner.click());
  assert.equal(opened, 1, "A single tap opens the real exam session action");
  assert.equal(document.querySelectorAll("button button").length, 0);
  await act(async () => root.unmount());
  console.log("Exam session interactions passed: keyboard, swipe geometry, reduced motion, drag suppression, banner focus and navigation.");
} finally {
  await unlink(filename);
  dom.window.close();
}
// React's browser scheduler retains a MessageChannel in the isolated DOM process.
process.exit(0);
