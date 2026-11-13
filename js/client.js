/**
 * @file
 * Editing overlays for a decoupled frontend shown in Drupal's preview frame.
 *
 * Frontends load this file from Drupal in their preview route, only in edit
 * mode. It has no dependencies, outlines [data-hfe] targets and offers the
 * actions Drupal describes for them. All text is set with textContent.
 */

(() => {
  // Read now: currentScript is only set while this file first runs.
  const script = document.currentScript;
  if (!script || window.parent === window) {
    return;
  }
  const drupalOrigin = new URL(script.src).origin;
  const TARGET =
    /^([a-z_]+):([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})(?::([a-z0-9_]+))?$/;
  const STYLE = `
    [hidden] { display: none; }
    .outline {
      position: fixed;
      outline: 2px solid #2563eb;
      outline-offset: 2px;
    }
    .toolbar {
      position: fixed;
      display: flex;
      gap: 4px;
      padding: 4px;
      border-radius: 4px;
      background: #1e3a8a;
      color: #fff;
      font: 13px/1.2 system-ui, sans-serif;
      pointer-events: auto;
    }
    .label { padding: 4px 6px; }
    button {
      padding: 4px 8px;
      border: 0;
      border-radius: 3px;
      background: #fff;
      color: #1e3a8a;
      font: inherit;
      cursor: pointer;
    }
    button:disabled { opacity: 0.5; cursor: default; }
    button:focus-visible { outline: 2px solid #fff; outline-offset: 1px; }
  `;

  /** Described targets, keyed by "id|lang". */
  const descriptions = new Map();
  let current = null;
  let announced = '';

  const post = (message) => {
    window.parent.postMessage({ v: 1, ...message }, drupalOrigin);
  };

  const langOf = (element) =>
    (
      element.closest('[data-hfe-lang]')?.getAttribute('data-hfe-lang') ||
      document.documentElement.lang ||
      'en'
    ).toLowerCase();

  const keyOf = (element) =>
    `${element.getAttribute('data-hfe')}|${langOf(element)}`;

  /**
   * Returns the closest described target at or above a node.
   *
   * @param {Node} node
   *   The node.
   *
   * @return {Element|null}
   *   The target element, or null.
   */
  const describedAt = (node) => {
    let element = node instanceof Element ? node.closest('[data-hfe]') : null;
    while (element && !descriptions.has(keyOf(element))) {
      element = element.parentElement?.closest('[data-hfe]') ?? null;
    }
    return element;
  };

  /**
   * Asks Drupal to describe the targets, when the set has changed.
   */
  const announce = () => {
    const keys = [
      ...new Set(
        [...document.querySelectorAll('[data-hfe]')]
          .filter((element) => TARGET.test(element.getAttribute('data-hfe')))
          .map(keyOf),
      ),
    ];
    if (keys.join(',') === announced) {
      return;
    }
    announced = keys.join(',');
    post({
      type: 'hfe:ready',
      targets: keys.map((key) => {
        const [id, lang] = key.split('|');
        return { id, lang };
      }),
    });
  };

  const init = () => {
    const host = document.createElement('div');
    host.setAttribute('data-hfe-overlay', '');
    host.style.cssText =
      'position:fixed;inset:0;pointer-events:none;z-index:2147483647;';
    const shadow = host.attachShadow({ mode: 'open' });
    const style = document.createElement('style');
    style.textContent = STYLE;
    const outline = document.createElement('div');
    outline.className = 'outline';
    const toolbar = document.createElement('div');
    toolbar.className = 'toolbar';
    toolbar.setAttribute('role', 'toolbar');
    outline.hidden = true;
    toolbar.hidden = true;
    shadow.append(style, outline, toolbar);
    document.body.append(host);

    const place = () => {
      if (!current) {
        return;
      }
      const rect = current.getBoundingClientRect();
      Object.assign(outline.style, {
        top: `${rect.top}px`,
        left: `${rect.left}px`,
        width: `${rect.width}px`,
        height: `${rect.height}px`,
      });
      Object.assign(toolbar.style, {
        top: `${Math.max(0, rect.top - toolbar.offsetHeight)}px`,
        left: `${Math.max(0, rect.left)}px`,
      });
    };

    const show = (element) => {
      const description = element && descriptions.get(keyOf(element));
      current = description ? element : null;
      outline.hidden = !current;
      toolbar.hidden = !current;
      if (!current) {
        return;
      }
      const label = document.createElement('span');
      label.className = 'label';
      label.textContent = description.label;
      const buttons = description.actions.map((action) => {
        const button = document.createElement('button');
        button.type = 'button';
        button.textContent = action.label;
        button.disabled = !!action.disabled;
        button.addEventListener('click', () => {
          post({
            type: 'hfe:action',
            target: description.id,
            lang: description.lang,
            action: action.id,
          });
        });
        return button;
      });
      toolbar.replaceChildren(label, ...buttons);
      place();
    };

    const point = (event) => {
      // Events from inside the toolbar report its host as target: keep it open.
      if (event.target === host) {
        return;
      }
      const element = describedAt(event.target);
      if (element !== current) {
        show(element);
      }
    };
    document.addEventListener('mouseover', point);
    document.addEventListener('focusin', point);
    document.documentElement.addEventListener('mouseleave', () => show(null));
    window.addEventListener('scroll', place, { capture: true, passive: true });
    window.addEventListener('resize', place);
    new MutationObserver(() => {
      if (current && !current.isConnected) {
        show(null);
      }
      announce();
      place();
    }).observe(document.body, {
      childList: true,
      subtree: true,
      attributes: true,
      attributeFilter: ['data-hfe', 'data-hfe-lang'],
    });

    // Route sync: the editing host follows internal links.
    document.addEventListener(
      'click',
      (event) => {
        const link =
          event.target instanceof Element && event.target.closest('a[href]');
        if (
          !link ||
          event.button !== 0 ||
          event.metaKey ||
          event.ctrlKey ||
          event.shiftKey ||
          event.altKey ||
          (link.target && link.target !== '_self')
        ) {
          return;
        }
        const url = new URL(link.href);
        if (
          url.origin !== window.location.origin ||
          url.pathname === window.location.pathname
        ) {
          return;
        }
        event.preventDefault();
        post({ type: 'hfe:navigate', path: url.pathname });
      },
      true,
    );

    window.addEventListener('message', (event) => {
      const data = event.data;
      if (
        event.source !== window.parent ||
        event.origin !== drupalOrigin ||
        !data ||
        data.v !== 1
      ) {
        return;
      }
      if (data.type === 'hfe:describe' && Array.isArray(data.targets)) {
        data.targets.forEach((target) => {
          if (
            target &&
            typeof target.id === 'string' &&
            typeof target.lang === 'string' &&
            typeof target.label === 'string' &&
            Array.isArray(target.actions)
          ) {
            descriptions.set(`${target.id}|${target.lang}`, target);
          }
        });
      } else if (
        data.type === 'hfe:show' &&
        typeof data.path === 'string' &&
        typeof data.token === 'string'
      ) {
        const proceed = window.dispatchEvent(
          new CustomEvent('hfe:show', {
            cancelable: true,
            detail: { path: data.path, token: data.token },
          }),
        );
        if (proceed) {
          const url = new URL(window.location.href);
          url.searchParams.set('path', data.path);
          url.searchParams.set('token', data.token);
          window.location.replace(url);
        }
      }
    });

    announce();
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
