<?php

declare(strict_types=1);

namespace Drupal\headless_frontend_editing;

use Drupal\Core\Entity\ContentEntityInterface;

/**
 * An editable thing: a content entity, or one field of it.
 */
final class Target {

  public function __construct(
    public readonly ContentEntityInterface $entity,
    public readonly ?string $fieldName = NULL,
  ) {}

  /**
   * Returns the canonical target string: entity_type:uuid[:field].
   */
  public function id(): string {
    return implode(':', array_filter([$this->entity->getEntityTypeId(), $this->entity->uuid(), $this->fieldName]));
  }

}
