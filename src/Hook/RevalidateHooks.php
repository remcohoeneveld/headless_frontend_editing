<?php

declare(strict_types=1);

namespace Drupal\headless_frontend_editing\Hook;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\headless_frontend_editing\Revalidator;

/**
 * Queues content changes for frontend revalidation.
 */
final class RevalidateHooks {

  public function __construct(
    private readonly Revalidator $revalidator,
  ) {}

  /**
   * Implements hook_entity_insert(), hook_entity_update() and hook_entity_delete().
   */
  #[Hook('entity_insert')]
  #[Hook('entity_update')]
  #[Hook('entity_delete')]
  public function revalidate(EntityInterface $entity): void {
    $this->revalidator->queue($entity);
  }

}
