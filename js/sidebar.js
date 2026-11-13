/**
 * @file
 * Live preview and save notices from the editing sidebar to its host page.
 */

/**
 * @param {object} $
 *   jQuery.
 * @param {object} Drupal
 *   The Drupal global.
 * @param {Function} once
 *   The once() helper.
 */
(($, Drupal, once) => {
  const DELAY = 600;

  /**
   * Posts a protocol message to the host page, which is same-origin.
   *
   * @param {object} message
   *   The message, without its version.
   */
  const notify = (message) => {
    window.parent.postMessage({ v: 1, ...message }, window.location.origin);
  };

  Drupal.behaviors.headlessFrontendEditingSidebar = {
    attach(context) {
      once('hfe-live', 'form.hfe-form', context).forEach((form) => {
        const button = form.querySelector('.hfe-live');
        let timer;
        const schedule = (event) => {
          if (event.target === button) {
            return;
          }
          clearTimeout(timer);
          timer = setTimeout(() => $(button).trigger('hfe:live'), DELAY);
        };
        form.addEventListener('input', schedule);
        form.addEventListener('change', schedule);
      });
      once('hfe-saved', '.hfe-saved', context).forEach(() => {
        notify({ type: 'hfe:saved' });
      });
    },
  };

  /**
   * Ajax command: shows the live preview path in the frontend preview.
   *
   * @param {Drupal.Ajax} ajax
   *   The Drupal Ajax object.
   * @param {object} response
   *   The response, with the preview path.
   */
  Drupal.AjaxCommands.prototype.hfeLivePreview = (ajax, response) => {
    notify({ type: 'hfe:live', path: response.path });
  };
})(jQuery, Drupal, once);
