<?php

/**
 * @file
 * Hooks provided by Headless Frontend Editing.
 */

use Drupal\headless_frontend_editing\Target;

/**
 * Alters the description of a target before describe returns it.
 *
 * @param array<string, mixed> $description
 *   The description: id, lang, label and actions (each with id, label and
 *   disabled).
 * @param \Drupal\headless_frontend_editing\Target $target
 *   The target described. The current user may already edit it.
 */
function hook_headless_frontend_editing_target_alter(array &$description, Target $target): void {
  if ($target->fieldName === 'title') {
    $description['actions'][0]['label'] = t('Rename');
  }
}
