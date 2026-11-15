/**
 * @file
 * Section actions (add, delete) for paragraph targets.
 */

/**
 * @param {object} Drupal
 *   The Drupal global.
 */
((Drupal) => {
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
})(Drupal);
