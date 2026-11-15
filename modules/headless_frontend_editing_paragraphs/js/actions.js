/**
 * @file
 * Section actions (move, add, delete) for paragraph targets.
 */

/**
 * @param {object} Drupal
 *   The Drupal global.
 * @param {object} drupalSettings
 *   The Drupal settings.
 */
((Drupal, drupalSettings) => {
  const PARAGRAPH = /^paragraph:([0-9a-f-]{36})$/;

  const hfe = Drupal.headlessFrontendEditing || {};
  Drupal.headlessFrontendEditing = hfe;
  hfe.actions = hfe.actions || {};

  /**
   * Returns the URL of a section route, or null for other targets.
   *
   * @param {string} target
   *   The target id.
   * @param {string} path
   *   The route path after the section's uuid.
   *
   * @return {string|null}
   *   The URL.
   */
  const url = (target, path) => {
    const match = PARAGRAPH.exec(target);
    return match
      ? Drupal.url(`headless-frontend-editing/paragraph/${match[1]}/${path}`)
      : null;
  };

  const ok = (response) => {
    if (!response.ok) {
      throw new Error(`${response.url}: HTTP ${response.status}`);
    }
    return response;
  };

  ['up', 'down'].forEach((direction) => {
    hfe.actions[direction] = (target) => {
      const { root } = drupalSettings.headlessFrontendEditing || {};
      const move = url(target, `move/${direction}`);
      // Saved without a form to confirm: only right after a real click or key
      // press, which a script in the preview cannot fake.
      if (
        !move ||
        !root ||
        navigator.userActivation?.isActive === false ||
        !hfe.confirmDiscard()
      ) {
        return;
      }
      fetch(Drupal.url('session/token'))
        .then(ok)
        .then((response) => response.text())
        .then((token) =>
          fetch(`${move}?root=${encodeURIComponent(root)}`, {
            method: 'POST',
            headers: { 'X-CSRF-Token': token },
          }),
        )
        .then(ok)
        .then(() => {
          hfe.flash(Drupal.t('Section moved.'));
          window.location.reload();
        })
        .catch((error) => {
          // eslint-disable-next-line no-console
          console.warn('Headless frontend editing:', error);
        });
    };
  });

  Object.entries({
    delete: 'delete',
    'add-before': 'add/before',
    'add-after': 'add/after',
  }).forEach(([id, path]) => {
    hfe.actions[id] = (target) => {
      const open = url(target, path);
      if (open && hfe.confirmDiscard()) {
        hfe.open(open);
      }
    };
  });
})(Drupal, drupalSettings);
