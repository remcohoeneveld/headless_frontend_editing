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
  // Ink tab on a violet ring: the ring keeps a white inner edge, so it shows on
  // dark and light sections alike.
  const STYLE = `
    [hidden] { display: none !important; }
    .outline {
      position: fixed;
      border-radius: 2px;
      /* A tint over the whole section shows where it starts and ends. */
      background: #6e56ff12;
      box-shadow: 0 0 0 1px #fff, 0 0 0 3px #6e56ff;
    }
    /* Appearing fades in; moving to another target glides there. */
    .outline:not([hidden]) { animation: hfe-in 0.16s ease-out; }
    .toolbar:not([hidden]) { animation: hfe-tab 0.18s ease-out; }
    .glide {
      transition-property: top, left, width, height;
      transition-duration: 0.22s;
      transition-timing-function: cubic-bezier(0.2, 0.8, 0.2, 1);
    }
    @keyframes hfe-in { from { opacity: 0; } }
    @keyframes hfe-tab { from { opacity: 0; transform: translateY(4px); } }
    @media (prefers-reduced-motion: reduce) {
      .outline, .toolbar { animation: none !important; }
      .glide { transition: none; }
    }
    .toolbar {
      position: fixed;
      display: flex;
      align-items: center;
      gap: 2px;
      padding: 3px;
      border-radius: 8px 8px 0 0;
      background: #17191f;
      color: #fff;
      font: 500 13px/1 system-ui, -apple-system, 'Segoe UI', sans-serif;
      letter-spacing: 0;
      pointer-events: auto;
      box-shadow: 0 0 0 1px #6e56ff;
    }
    .toolbar.inside { border-radius: 0 0 8px; }
    .label {
      max-width: 16em;
      padding: 0 8px 0 7px;
      overflow: hidden;
      color: #b4b8c5;
      font-weight: 400;
      white-space: nowrap;
      text-overflow: ellipsis;
    }
    button {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      height: 28px;
      padding: 0 10px;
      border: 0;
      border-radius: 5px;
      background: transparent;
      color: inherit;
      font: inherit;
      cursor: pointer;
    }
    button.primary { background: #fff; color: #17191f; }
    button.icon { width: 28px; padding: 0; justify-content: center; }
    button:hover:not(:disabled) { background: #2c2f38; }
    button.primary:hover:not(:disabled) { background: #e7e3ff; }
    button.danger:hover:not(:disabled) { background: #c92a2a; }
    button:disabled { opacity: 0.35; cursor: default; }
    button:focus-visible { outline: 2px solid #9d8cff; outline-offset: 1px; }
    .sep { width: 1px; height: 18px; margin: 0 3px; background: #3a3d47; }
    svg { flex: none; }
  `;

  // Icons for known actions; other actions show their label.
  const ICONS = {
    edit: 'M4 20h4L19 9l-4-4L4 16zM14 6l4 4',
    up: 'M12 19V5M6 11l6-6 6 6',
    down: 'M12 5v14M6 13l6 6 6-6',
    'add-before': 'M5 20h14M12 4v10M7 9h10',
    'add-after': 'M5 4h14M12 10v10M7 15h10',
    delete: 'M4 7h16M9 7V4h6v3M6 7l1 13h10l1-13',
  };

  const icon = (path) => {
    const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
    svg.setAttribute('viewBox', '0 0 24 24');
    svg.setAttribute('width', '16');
    svg.setAttribute('height', '16');
    svg.setAttribute('fill', 'none');
    svg.setAttribute('stroke', 'currentColor');
    svg.setAttribute('stroke-width', '2');
    svg.setAttribute('stroke-linecap', 'round');
    svg.setAttribute('stroke-linejoin', 'round');
    svg.setAttribute('aria-hidden', 'true');
    const shape = document.createElementNS(
      'http://www.w3.org/2000/svg',
      'path',
    );
    shape.setAttribute('d', path);
    svg.append(shape);
    return svg;
  };

  /** Described targets, keyed by "id|lang". */
  const descriptions = new Map();
  let current = null;
  let announced = '';
  let described = false;
  // Retry delays for hfe:ready until Drupal first answers: the host page may
  // not be listening yet when this frame starts.
  const RETRIES = [500, 1000, 2000, 4000, 8000];
  // How long the pointer may cross another target on its way to the toolbar.
  const SWITCH_DELAY = 300;
  // Actions that start a group of their own in the toolbar.
  const GROUPS = new Set(['up', 'add-before', 'delete']);
  // The target just moved: shown again after the page reloads.
  const MOVED_KEY = 'headlessFrontendEditing.moved';

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
   *
   * @param {boolean} force
   *   Whether to send even if the set is unchanged.
   */
  const announce = (force = false) => {
    const keys = [
      ...new Set(
        [...document.querySelectorAll('[data-hfe]')]
          .filter((element) => TARGET.test(element.getAttribute('data-hfe')))
          .map(keyOf),
      ),
    ];
    if (!force && keys.join(',') === announced) {
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
    toolbar.setAttribute('aria-keyshortcuts', 'Alt+Enter');
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
      // A tab on the top edge, or inside the target when there is no room.
      const inside = rect.top - 3 < toolbar.offsetHeight;
      toolbar.classList.toggle('inside', inside);
      Object.assign(toolbar.style, {
        top: `${inside ? Math.max(0, rect.top) : rect.top - 3 - toolbar.offsetHeight}px`,
        left: `${Math.max(0, rect.left - 3)}px`,
      });
    };

    let glideTimer;
    const show = (element) => {
      const description = element && descriptions.get(keyOf(element));
      // From one target straight to the next: glide, instead of jumping.
      const glide = !!(current && description && element !== current);
      outline.classList.toggle('glide', glide);
      toolbar.classList.toggle('glide', glide);
      clearTimeout(glideTimer);
      // Scrolling repositions at once: the glide is only for the switch.
      glideTimer = setTimeout(() => {
        outline.classList.remove('glide');
        toolbar.classList.remove('glide');
      }, 250);
      current = description ? element : null;
      outline.hidden = !current;
      toolbar.hidden = !current;
      if (!current) {
        return;
      }
      const label = document.createElement('span');
      label.className = 'label';
      label.textContent = description.label;
      const buttons = description.actions
        .filter(
          (action) =>
            action &&
            typeof action.id === 'string' &&
            typeof action.label === 'string',
        )
        .map((action) => {
          const button = document.createElement('button');
          button.type = 'button';
          button.disabled = !!action.disabled;
          const path = Object.hasOwn(ICONS, action.id) && ICONS[action.id];
          if (action.id === 'edit') {
            // The main action keeps its words next to the icon.
            button.className = 'primary';
            button.append(icon(path), action.label);
          } else if (path) {
            button.className = action.id === 'delete' ? 'icon danger' : 'icon';
            button.setAttribute('aria-label', action.label);
            button.title = action.label;
            button.append(icon(path));
          } else {
            button.textContent = action.label;
          }
          button.dataset.action = action.id;
          button.addEventListener('click', () => {
            if (action.id === 'up' || action.id === 'down') {
              try {
                window.sessionStorage.setItem(MOVED_KEY, description.id);
              } catch (e) {
                // Not kept: the page reloads at its top.
              }
            }
            post({
              type: 'hfe:action',
              target: description.id,
              lang: description.lang,
              action: action.id,
            });
          });
          return button;
        });
      // Dividers after the main action and between groups of actions.
      const children = [label];
      buttons.forEach((button, i) => {
        if (i === 1 || (i > 1 && GROUPS.has(button.dataset.action))) {
          const sep = document.createElement('span');
          sep.className = 'sep';
          children.push(sep);
        }
        children.push(button);
      });
      toolbar.replaceChildren(...children);
      place();
    };

    // After a move reloads the page, brings the moved target back into view.
    const restoreMoved = () => {
      let id = null;
      try {
        id = window.sessionStorage.getItem(MOVED_KEY);
        window.sessionStorage.removeItem(MOVED_KEY);
      } catch (e) {
        return;
      }
      const element =
        id &&
        [...document.querySelectorAll('[data-hfe]')].find(
          (candidate) => descriptions.get(keyOf(candidate))?.id === id,
        );
      if (element) {
        element.scrollIntoView({ block: 'center' });
        show(element);
      }
    };

    // The toolbar sits above its target, over the edge of the section before
    // it: moving up to it crosses that section, which must not take over.
    let pending;
    const point = (event) => {
      clearTimeout(pending);
      // Events from inside the toolbar report its host as target, and focus
      // in the toolbar keeps it open.
      if (event.target === host || shadow.activeElement) {
        return;
      }
      const element = describedAt(event.target);
      if (element === current) {
        return;
      }
      // Only in the strip above the target, where its tab is: elsewhere the
      // toolbar follows the pointer at once.
      const top = current && current.getBoundingClientRect().top;
      if (
        current &&
        event.type === 'mouseover' &&
        !toolbar.classList.contains('inside') &&
        event.clientY < top &&
        event.clientY > top - toolbar.offsetHeight - 12
      ) {
        pending = setTimeout(() => show(element), SWITCH_DELAY);
      } else {
        show(element);
      }
    };
    document.addEventListener('mouseover', point);
    document.addEventListener('focusin', point);
    document.documentElement.addEventListener('mouseleave', () => {
      clearTimeout(pending);
      if (!shadow.activeElement) {
        show(null);
      }
    });
    // Alt+Enter in a target, or in anything focusable inside one, moves focus
    // to that target's toolbar.
    document.addEventListener('keydown', (event) => {
      const element =
        event.altKey && event.key === 'Enter'
          ? describedAt(document.activeElement)
          : null;
      if (!element) {
        return;
      }
      if (element !== current) {
        show(element);
      }
      const button = toolbar.querySelector('button:not(:disabled)');
      if (button) {
        event.preventDefault();
        button.focus();
      }
    });
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
          (link.getAttribute('target') &&
            link.getAttribute('target') !== '_self')
        ) {
          return;
        }
        // getAttribute(): SVG links have no string href or target property.
        let url;
        try {
          url = new URL(link.getAttribute('href'), window.location.href);
        } catch (e) {
          return;
        }
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
        described = true;
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
        restoreMoved();
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
    const retry = (attempt) => {
      if (described || attempt >= RETRIES.length) {
        return;
      }
      setTimeout(() => {
        if (!described) {
          announce(true);
          retry(attempt + 1);
        }
      }, RETRIES[attempt]);
    };
    retry(0);
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
