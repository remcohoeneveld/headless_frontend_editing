<?php

declare(strict_types=1);

namespace Drupal\headless_frontend_editing_paragraphs_test\Hook;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\State\StateInterface;

/**
 * Denies editing the field named in the state key of this module.
 */
final class LockedFieldHooks {

  public const STATE = 'headless_frontend_editing_paragraphs_test.locked_field';

  public function __construct(
    private readonly StateInterface $state,
  ) {}

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
  public function fieldAccess(string $operation, FieldDefinitionInterface $field_definition, AccountInterface $account, ?FieldItemListInterface $items = NULL): AccessResult {
    return AccessResult::forbiddenIf($operation === 'edit' && $field_definition->getName() === $this->state->get(self::STATE))
      ->setCacheMaxAge(0);
  }

}
