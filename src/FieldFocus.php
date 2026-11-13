<?php

declare(strict_types=1);

namespace Drupal\headless_frontend_editing;

/**
 * Remembers, for this request, the one field the editing form is limited to.
 */
final class FieldFocus {

  /**
   * The focused entity type, bundle and field, or NULL.
   *
   * @var array{string, string, string}|null
   */
  private ?array $focus = NULL;

  /**
   * Limits the editing form of a bundle to one field; NULL for all fields.
   */
  public function set(string $entity_type_id, string $bundle, ?string $field_name): void {
    $this->focus = $field_name === NULL ? NULL : [$entity_type_id, $bundle, $field_name];
  }

  /**
   * Returns the focused field of a bundle, or NULL for all fields.
   */
  public function get(string $entity_type_id, string $bundle): ?string {
    return $this->focus !== NULL && $this->focus[0] === $entity_type_id && $this->focus[1] === $bundle ? $this->focus[2] : NULL;
  }

}
