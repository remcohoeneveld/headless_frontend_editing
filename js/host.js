/**
 * @file
 * Panel, route sync and editing protocol for the decoupled frontend view tab.
 */

/**
 * @param {object} Drupal
 *   The Drupal global.
 * @param {object} drupalSettings
 *   The Drupal settings.
 * @param {Function} once
 *   The once() helper.
 */
((Drupal, drupalSettings, once) => {
  const TARGET =
    /^([a-z_]+):([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})(?::([a-z0-9_]+))?$/;
  const LANG = /^[a-z]{2,3}(?:-[a-z0-9]+)?$/;
  const LIVE =
    /^\/(?:[a-z]{2,3}(?:-[a-z0-9]+)?\/)?node\/preview\/[\w-]+\/full$/;

  const hfe = Drupal.headlessFrontendEditing || {};
  Drupal.headlessFrontendEditing = hfe;

  /**
   * Toolbar actions by id, called with a validated target and langcode.
   *
   * Other modules add their own: actions[id] = (target, lang) => {...}.
   */
  hfe.actions = hfe.actions || {};
  hfe.actions.edit = (target, lang) => {
    if (!hfe.confirmDiscard()) {
      return;
    }
    hfe.open(
      Drupal.url(
        `headless-frontend-editing/edit/${encodeURIComponent(lang)}/${encodeURIComponent(target)}`,
      ),
    );
  };

  const FLASH_KEY = 'headlessFrontendEditing.flash';
  const FLASH_TIME = 4000;

  /**
   * Keeps a confirmation to show after the page reloads.
   *
   * @param {string} message
   *   Plain text, such as "Page saved.".
   */
  hfe.flash = (message) => {
    try {
      window.sessionStorage.setItem(FLASH_KEY, message);
    } catch (e) {
      // Not shown: the reload itself is the confirmation.
    }
  };

  // Elements and settings are looked up on use: the message listener below is
  // registered before behaviors attach, so no early message is lost.
  const preview = () => document.querySelector('.hfe-preview');
  const editor = () => document.querySelector('.hfe-editor');
  const editorFrame = () => document.querySelector('.hfe-editor__frame');
  const settings = () => drupalSettings.headlessFrontendEditing || {};
  const frontendOrigin = () => new URL(settings().frontendOrigin).origin;

  // Whether the preview shows unsaved content from the sidebar.
  let live = false;
  // Whether the sidebar form changed since it loaded.
  let dirty = false;
  let csrfToken = null;

  /**
   * Fails a fetch response that is not 2xx.
   *
   * @param {Response} response
   *   The response.
   *
   * @return {Response}
   *   The same response.
   */
  const ok = (response) => {
    if (!response.ok) {
      throw new Error(`${response.url}: HTTP ${response.status}`);
    }
    return response;
  };

  const warn = (error) => {
    // eslint-disable-next-line no-console
    console.warn('Headless frontend editing:', error);
  };

  /**
   * Returns the CSRF token for the describe request, fetched once.
   *
   * @return {Promise<string>}
   *   The token.
   */
  const csrf = () => {
    csrfToken =
      csrfToken ||
      fetch(Drupal.url('session/token'))
        .then(ok)
        .then((response) => response.text())
        .catch((error) => {
          csrfToken = null;
          throw error;
        });
    return csrfToken;
  };

  const toFrontend = (message) => {
    preview().contentWindow.postMessage({ v: 1, ...message }, frontendOrigin());
  };

  /**
   * Shows a path in the preview, with a fresh preview token.
   *
   * @param {string} path
   *   The frontend path.
   */
  const show = (path) => {
    csrf()
      .then((csrfToken) =>
        fetch(settings().tokenUrl, {
          method: 'POST',
          cache: 'no-store',
          headers: { 'X-CSRF-Token': csrfToken },
        }),
      )
      .then(ok)
      .then((response) => response.json())
      .then(({ token }) => toFrontend({ type: 'hfe:show', path, token }))
      .catch(warn);
  };

  /**
   * Answers hfe:ready with the descriptions of the editable targets.
   *
   * @param {Array} targets
   *   The targets the frontend sent.
   */
  const describe = (targets) => {
    const valid = targets
      .filter(
        (target) =>
          target &&
          typeof target.id === 'string' &&
          typeof target.lang === 'string' &&
          TARGET.test(target.id) &&
          LANG.test(target.lang.toLowerCase()),
      )
      .map(({ id, lang }) => ({ id, lang: lang.toLowerCase() }));
    csrf()
      .then((token) =>
        fetch(settings().describeUrl, {
          method: 'POST',
          headers: {
            'X-CSRF-Token': token,
            'Content-Type': 'application/json',
          },
          body: JSON.stringify({ targets: valid }),
        }),
      )
      .then(ok)
      .then((response) => response.json())
      .then((result) =>
        toFrontend({ type: 'hfe:describe', targets: result.targets }),
      )
      .catch(warn);
  };

  /**
   * Asks whether unsaved changes in the sidebar may be discarded.
   *
   * @return {boolean}
   *   True when there are none, or the editor agrees to discard them.
   */
  hfe.confirmDiscard = () =>
    // eslint-disable-next-line no-alert
    !dirty || window.confirm(Drupal.t('Discard unsaved changes?'));

  const editorTitle = () => document.querySelector('.hfe-editor__title');

  /**
   * Opens a page in the sidebar.
   *
   * @param {string} url
   *   The URL of a sidebar page.
   * @param {string} title
   *   The title to show until the page reports its own.
   */
  hfe.open = (url, title = '') => {
    const frame = editorFrame();
    if (!frame) {
      return;
    }
    if (editorTitle()) {
      editorTitle().textContent = title;
    }
    frame.src = url;
    editor().hidden = false;
    frame.focus();
  };

  /**
   * Closes the sidebar, after confirming that unsaved changes may go.
   */
  const close = () => {
    if (!editor() || editor().hidden || !hfe.confirmDiscard()) {
      return;
    }
    editor().hidden = true;
    editorFrame().src = 'about:blank';
    dirty = false;
    preview().focus();
    if (live) {
      live = false;
      show(settings().path);
    }
  };

  const onEscape = (event) => {
    if (event.key === 'Escape' && !event.defaultPrevented) {
      close();
    }
  };

  window.addEventListener('message', (event) => {
    const data = event.data;
    const frame = preview();
    const sidebar = editorFrame();
    if (!data || data.v !== 1 || !settings().editable || !frame || !sidebar) {
      return;
    }
    if (
      event.origin === frontendOrigin() &&
      event.source === frame.contentWindow
    ) {
      const { actions } = hfe;
      if (data.type === 'hfe:ready' && Array.isArray(data.targets)) {
        describe(data.targets);
      } else if (
        data.type === 'hfe:action' &&
        typeof data.target === 'string' &&
        typeof data.lang === 'string' &&
        TARGET.test(data.target) &&
        LANG.test(data.lang.toLowerCase()) &&
        Object.hasOwn(actions, data.action) &&
        typeof actions[data.action] === 'function'
      ) {
        actions[data.action](data.target, data.lang.toLowerCase());
      }
    } else if (
      event.origin === window.location.origin &&
      event.source === sidebar.contentWindow
    ) {
      if (
        data.type === 'hfe:live' &&
        typeof data.path === 'string' &&
        LIVE.test(data.path)
      ) {
        live = true;
        show(data.path);
      } else if (data.type === 'hfe:saved') {
        hfe.flash(
          (typeof data.message === 'string' && data.message.trim()) ||
            Drupal.t('Saved.'),
        );
        window.location.reload();
      }
    }
  });

  // The confirmation kept by hfe.flash() before the reload, above the dock.
  Drupal.behaviors.headlessFrontendEditingFlash = {
    attach(context) {
      once('hfe-flash', '.hfe-preview', context).forEach(() => {
        let message = null;
        try {
          message = window.sessionStorage.getItem(FLASH_KEY);
          window.sessionStorage.removeItem(FLASH_KEY);
        } catch (e) {
          return;
        }
        if (!message) {
          return;
        }
        const toast = document.createElement('div');
        toast.className = 'hfe-toast';
        toast.setAttribute('role', 'status');
        document.body.append(toast);
        // Filled after insertion, so screen readers announce it.
        setTimeout(() => {
          toast.textContent = message;
        }, 100);
        setTimeout(() => {
          toast.classList.add('hfe-toast--out');
          toast.addEventListener('animationend', () => toast.remove());
          // Without animations (reduced motion), animationend never comes.
          setTimeout(() => toast.remove(), 400);
        }, FLASH_TIME);
      });
    },
  };

  // The dock's menu: page status, tabs and link. Closes like a menu.
  Drupal.behaviors.headlessFrontendEditingPanel = {
    attach(context) {
      once('hfe-panel', '.hfe-panel__toggle', context).forEach((toggle) => {
        const body = document.getElementById(
          toggle.getAttribute('aria-controls'),
        );
        const set = (expanded) => {
          toggle.setAttribute('aria-expanded', expanded ? 'true' : 'false');
          body.hidden = !expanded;
        };
        toggle.addEventListener('click', () => set(body.hidden));
        document.addEventListener('click', (event) => {
          if (!toggle.contains(event.target) && !body.contains(event.target)) {
            set(false);
          }
        });
        // A click in the preview or sidebar iframe only blurs this window.
        window.addEventListener('blur', () => set(false));
        // Capture: runs before the editor's Escape, which closes the sidebar.
        document.addEventListener(
          'keydown',
          (event) => {
            if (event.key === 'Escape' && !body.hidden) {
              event.preventDefault();
              set(false);
              toggle.focus();
            }
          },
          true,
        );
      });
    },
  };

  // Panel tools, such as editing the whole page, open in the sidebar.
  Drupal.behaviors.headlessFrontendEditingTools = {
    attach(context) {
      once('hfe-tool', '[data-hfe-open]', context).forEach((button) => {
        button.addEventListener('click', () => {
          if (hfe.confirmDiscard()) {
            hfe.open(
              button.getAttribute('data-hfe-open'),
              button.getAttribute('data-hfe-title') ||
                button.textContent.trim(),
            );
          }
        });
      });
    },
  };

  Drupal.behaviors.headlessFrontendEditingRouteSync = {
    attach(context) {
      const settings = drupalSettings.headlessFrontendEditing;
      once('hfe-route-sync', '.hfe-preview', context).forEach((frame) => {
        const origin = new URL(settings.frontendOrigin).origin;
        window.addEventListener('message', (event) => {
          const data = event.data;
          if (
            event.origin !== origin ||
            event.source !== frame.contentWindow ||
            !data ||
            data.v !== 1 ||
            data.type !== 'hfe:navigate' ||
            typeof data.path !== 'string' ||
            !data.path.startsWith('/') ||
            /[\\\s]/.test(data.path)
          ) {
            return;
          }
          // Parsed, not matched: the URL parser drops tabs and newlines, so
          // "/\t/evil.com" would become "//evil.com", another site.
          let url;
          try {
            url = new URL(
              drupalSettings.path.baseUrl.replace(/\/$/, '') + data.path,
              window.location.origin,
            );
          } catch (e) {
            return;
          }
          if (
            url.origin !== window.location.origin ||
            url.pathname === window.location.pathname
          ) {
            return;
          }
          window.location.assign(url.pathname);
        });
      });
    },
  };

  Drupal.behaviors.headlessFrontendEditingProtocol = {
    attach(context) {
      once('hfe-protocol', '.hfe-editor', context).forEach((element) => {
        const frame = element.querySelector('.hfe-editor__frame');
        const changed = () => {
          dirty = true;
        };
        element
          .querySelector('.hfe-editor__close')
          .addEventListener('click', close);
        document.addEventListener('keydown', onEscape);
        frame.addEventListener('load', () => {
          const doc = frame.contentDocument;
          // A form shown again with errors (a failed save) still holds the
          // unsaved changes.
          dirty = !!doc?.querySelector(
            'form.hfe-form [aria-invalid="true"], .messages--error',
          );
          if (!doc) {
            return;
          }
          // The sidebar page's own heading names what is open.
          const heading = doc.querySelector('.hfe-sidebar-page__title');
          if (heading && editorTitle()) {
            editorTitle().textContent = heading.textContent.trim();
          }
          doc.addEventListener('keydown', onEscape);
          doc.addEventListener('input', changed);
          doc.addEventListener('change', changed);
          // CKEditor 5 only reports changes through this jQuery event.
          frame.contentWindow.jQuery?.(doc).on('formUpdated', changed);
        });
      });
    },
  };
})(Drupal, drupalSettings, once);
