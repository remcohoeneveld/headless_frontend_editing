<?php

declare(strict_types=1);

namespace Drupal\headless_frontend_editing\Hook;

use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\headless_frontend_editing\Controller\PreviewController;

/**
 * Theme-related hooks.
 */
final class ThemeHooks {

  public function __construct(
    private readonly RouteMatchInterface $routeMatch,
  ) {}

  /**
   * Implements hook_preprocess_html().
   *
   * @param array<string, mixed> $variables
   *   The template variables.
   */
  #[Hook('preprocess_html')]
  public function preprocessHtml(array &$variables): void {
    if (isset(PreviewController::ROUTES[$this->routeMatch->getRouteName()])) {
      $variables['attributes']['class'][] = 'hfe-preview-page';
    }
  }

}
