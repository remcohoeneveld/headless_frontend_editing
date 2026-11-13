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
  const STORAGE_KEY = 'headlessFrontendEditing.panelCollapsed';
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
    fetch(settings().tokenUrl, { cache: 'no-store' })
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

  hfe.open = (url) => {
    const frame = editorFrame();
    if (!frame) {
      return;
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
        window.location.reload();
      }
    }
  });

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
        try {
          set(window.localStorage.getItem(STORAGE_KEY) !== '1');
        } catch (e) {
          // Storage unavailable: keep the default.
        }
        toggle.addEventListener('click', () => {
          const expanded = toggle.getAttribute('aria-expanded') !== 'true';
          set(expanded);
          try {
            window.localStorage.setItem(STORAGE_KEY, expanded ? '0' : '1');
          } catch (e) {
            // Not persisted.
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
            !/^\/(?!\/)/.test(data.path) ||
            drupalSettings.path.baseUrl.replace(/\/$/, '') + data.path ===
              window.location.pathname
          ) {
            return;
          }
          window.location.assign(
            drupalSettings.path.baseUrl.replace(/\/$/, '') + data.path,
          );
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
          dirty = false;
          const doc = frame.contentDocument;
          if (!doc) {
            return;
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
