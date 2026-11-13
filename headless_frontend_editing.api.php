<?php

/**
 * @file
 * Hooks provided by Headless Frontend Editing.
 */

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Form\FormStateInterface;
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

/**
 * Returns the entity to preview live while another entity is edited.
 *
 * The live preview shows the first non-NULL result of all implementations, or
 * else the edited entity itself. Only nodes can be previewed live.
 *
 * @param \Drupal\Core\Entity\ContentEntityInterface $entity
 *   The edited entity, built from the unsaved form values.
 * @param \Drupal\Core\Form\FormStateInterface $form_state
 *   The state of the sidebar form.
 *
 * @return \Drupal\Core\Entity\ContentEntityInterface|null
 *   The entity to preview, holding the edited entity in memory, or NULL.
 */
function hook_headless_frontend_editing_preview_root(ContentEntityInterface $entity, FormStateInterface $form_state): ?ContentEntityInterface {
  if ($entity->getEntityTypeId() === 'paragraph' && ($parent = $entity->getParentEntity())) {
    // Show the unsaved paragraph in place of its saved version.
    $parent = clone $parent;
    foreach ($parent->get($entity->get('parent_field_name')->value) as $item) {
      if ($item->target_id == $entity->id()) {
        $item->entity = $entity;
      }
    }
    return $parent;
  }
  return NULL;
}
