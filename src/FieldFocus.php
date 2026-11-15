<?php

declare(strict_types=1);

namespace Drupal\headless_frontend_editing;

/**
 * Remembers, for this request, what the sidebar editing form edits.
 *
 * Either one field, or the whole entity.
 */
final class FieldFocus {

  /**
   * The edited entity type, bundle and field (NULL for all), or NULL.
   *
   * @var array{string, string, string|null}|null
   */
  private ?array $focus = NULL;

  /**
   * Limits the editing form of a bundle to one field; NULL for all fields.
   */
  public function set(string $entity_type_id, string $bundle, ?string $field_name): void {
    $this->focus = [$entity_type_id, $bundle, $field_name];
  }

  /**
   * Returns the focused field of a bundle, or NULL for all fields.
   */
  public function get(string $entity_type_id, string $bundle): ?string {
    return $this->matches($entity_type_id, $bundle) ? $this->focus[2] : NULL;
  }

  /**
   * Whether the whole entity of a bundle is being edited, not one field.
   */
  public function isWholeEntity(string $entity_type_id, string $bundle): bool {
    return $this->matches($entity_type_id, $bundle) && $this->focus[2] === NULL;
  }

  /**
   * Whether the edited entity is of this type and bundle.
   */
  private function matches(string $entity_type_id, string $bundle): bool {
    return $this->focus !== NULL && $this->focus[0] === $entity_type_id && $this->focus[1] === $bundle;
  }

}
