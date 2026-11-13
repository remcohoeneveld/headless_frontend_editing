<?php

declare(strict_types=1);

namespace Drupal\headless_frontend_editing_test_access\Hook;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Field\FieldItemListInterface;

/**
 * Denies editing the promote field.
 */
final class TestAccessHooks {

  /**
   * Implements hook_entity_field_access().
   *
   * @param string $operation
   *   The operation.
   * @param \Drupal\Core\Field\FieldDefinitionInterface $field_definition
   *   The field.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The user.
   * @param \Drupal\Core\Field\FieldItemListInterface<\Drupal\Core\Field\FieldItemInterface>|null $items
   *   The items.
   */
  #[Hook('entity_field_access')]
  public function fieldAccess(string $operation, FieldDefinitionInterface $field_definition, AccountInterface $account, ?FieldItemListInterface $items = NULL): AccessResult {
    return AccessResult::forbiddenIf($operation === 'edit' && $field_definition->getName() === 'promote');
  }

}
