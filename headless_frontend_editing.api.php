<?php

/**
 * @file
 * Hooks provided by Headless Frontend Editing.
 */

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\headless_frontend_editing\Target;

/**
 * Checks access to edit a target, on top of entity and field access.
 *
 * Runs for describe and for the edit route. Any forbidden result refuses the
 * target. Composite entities (such as paragraphs, which are saved through
 * their host) are refused unless an implementation allows them; neutral
 * leaves other targets as entity and field access decide.
 *
 * @param \Drupal\headless_frontend_editing\Target $target
 *   The target.
 * @param \Drupal\Core\Session\AccountInterface $account
 *   The user.
 *
 * @return \Drupal\Core\Access\AccessResultInterface
 *   The access result.
 */
function hook_headless_frontend_editing_target_access(Target $target, AccountInterface $account): AccessResultInterface {
  return AccessResult::forbiddenIf($target->fieldName === 'sticky')->setCacheMaxAge(0);
}

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
