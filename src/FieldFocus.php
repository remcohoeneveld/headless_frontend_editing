<?php

declare(strict_types=1);

namespace Drupal\headless_frontend_editing;

/**
 * Remembers, for this request, the one field the editing form is limited to.
 */
final class FieldFocus {

  /**
   * The focused field, or NULL for the whole entity.
   */
  private ?string $fieldName = NULL;

  /**
   * Limits the editing form to one field; NULL for the whole entity.
   */
  public function set(?string $field_name): void {
    $this->fieldName = $field_name;
  }

  /**
   * Returns the focused field, or NULL for the whole entity.
   */
  public function get(): ?string {
    return $this->fieldName;
  }

}
