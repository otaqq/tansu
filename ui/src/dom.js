/**
 * Small DOM helpers. Text is always set as text, never as HTML, so names from the server cannot inject markup.
 */

/**
 * Creates an element.
 *
 * @param {string} tag
 * @param {Record<string, any>} [props] attributes; `on<event>` adds a listener, `class` / `part` / `hidden` / `disabled` as usual
 * @param {...(Node|string|null|undefined|false)} children
 * @returns {HTMLElement}
 */
export function h(tag, props = {}, ...children) {
  const element = document.createElement(tag);
  for (const [key, value] of Object.entries(props)) {
    if (value === undefined || value === null || value === false) continue;
    if (key.startsWith('on') && typeof value === 'function') {
      element.addEventListener(key.slice(2).toLowerCase(), value);
    } else if (key === 'dataset') {
      Object.assign(element.dataset, value);
    } else if (value === true) {
      element.setAttribute(key, '');
    } else {
      element.setAttribute(key, String(value));
    }
  }
  append(element, children);
  return element;
}

function append(element, children) {
  for (const child of children.flat()) {
    if (child === undefined || child === null || child === false) continue;
    element.append(typeof child === 'string' ? document.createTextNode(child) : child);
  }
}

/**
 * Replaces the children of an element.
 *
 * @param {Element} element
 * @param {...(Node|string|null|undefined|false)} children
 */
export function replace(element, ...children) {
  element.replaceChildren();
  append(element, children);
}

/**
 * Adopts a stylesheet into a shadow root (constructable stylesheets, or a <style> element where unsupported).
 *
 * @param {ShadowRoot} root
 * @param {string} css
 */
export function adoptStyles(root, css) {
  if ('adoptedStyleSheets' in root && typeof CSSStyleSheet !== 'undefined' && 'replaceSync' in CSSStyleSheet.prototype) {
    const sheet = new CSSStyleSheet();
    sheet.replaceSync(css);
    root.adoptedStyleSheets = [...root.adoptedStyleSheets, sheet];
  } else {
    root.append(h('style', {}, css));
  }
}
