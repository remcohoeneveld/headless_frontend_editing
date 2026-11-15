/**
 * @file
 * Live preview of the section order after each drag or keyboard move.
 */

/**
 * @param {object} Drupal
 *   The Drupal global.
 */
((Drupal) => {
  const { onDrop } = Drupal.tableDrag.prototype;

  // Tabledrag sets the weights without a change event: send one, so the
  // sidebar's live preview (sidebar.js) picks up the new order.
  Drupal.tableDrag.prototype.onDrop = function hfeOnDrop(...args) {
    const result = onDrop.apply(this, args);
    if (this.table.closest('form.hfe-form')) {
      this.table.dispatchEvent(new Event('change', { bubbles: true }));
    }
    return result;
  };
})(Drupal);
