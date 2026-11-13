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

  /**
   * Toolbar actions by id, called with a validated target and langcode.
   *
   * Other modules add their own: actions[id] = (target, lang) => {...}.
   */
  Drupal.headlessFrontendEditing = {
    actions: {
      edit(target, lang) {
        Drupal.headlessFrontendEditing.open(
          Drupal.url(
            `headless-frontend-editing/edit/${encodeURIComponent(lang)}/${encodeURIComponent(target)}`,
          ),
        );
      },
    },
    // Opens a URL in the editing sidebar; set once the sidebar is attached.
    open() {},
  };

  let csrfToken = null;

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
        .then((response) => response.text())
        .catch((error) => {
          csrfToken = null;
          throw error;
        });
    return csrfToken;
  };

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
      const settings = drupalSettings.headlessFrontendEditing;
      once('hfe-protocol', '.hfe-preview', context).forEach((preview) => {
        const editor = document.querySelector('.hfe-editor');
        if (!settings.editable || !editor) {
          return;
        }
        const frame = editor.querySelector('.hfe-editor__frame');
        const origin = new URL(settings.frontendOrigin).origin;
        // Whether the preview shows unsaved content from the sidebar.
        let live = false;

        const toFrontend = (message) => {
          preview.contentWindow.postMessage({ v: 1, ...message }, origin);
        };
        const show = (path) =>
          fetch(settings.tokenUrl, { cache: 'no-store' })
            .then((response) => response.json())
            .then(({ token }) => toFrontend({ type: 'hfe:show', path, token }));
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
              fetch(settings.describeUrl, {
                method: 'POST',
                headers: {
                  'X-CSRF-Token': token,
                  'Content-Type': 'application/json',
                },
                body: JSON.stringify({ targets: valid }),
              }),
            )
            .then((response) => response.json())
            .then((result) =>
              toFrontend({ type: 'hfe:describe', targets: result.targets }),
            );
        };
        const close = () => {
          if (editor.hidden) {
            return;
          }
          editor.hidden = true;
          frame.src = 'about:blank';
          preview.focus();
          if (live) {
            live = false;
            show(settings.path);
          }
        };

        Drupal.headlessFrontendEditing.open = (url) => {
          frame.src = url;
          editor.hidden = false;
          frame.focus();
        };
        editor
          .querySelector('.hfe-editor__close')
          .addEventListener('click', close);
        document.addEventListener('keydown', (event) => {
          if (event.key === 'Escape') {
            close();
          }
        });

        window.addEventListener('message', (event) => {
          const data = event.data;
          if (!data || data.v !== 1) {
            return;
          }
          if (
            event.origin === origin &&
            event.source === preview.contentWindow
          ) {
            const { actions } = Drupal.headlessFrontendEditing;
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
            event.source === frame.contentWindow
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
      });
    },
  };
})(Drupal, drupalSettings, once);
