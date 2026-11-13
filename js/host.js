/**
 * @file
 * Panel toggle and route sync for the decoupled frontend view tab.
 */

(function (Drupal, drupalSettings, once) {
  const STORAGE_KEY = 'headlessFrontendEditing.panelCollapsed';

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
            data.path === window.location.pathname
          ) {
            return;
          }
          window.location.assign(data.path);
        });
      });
    },
  };
})(Drupal, drupalSettings, once);
