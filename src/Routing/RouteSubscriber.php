<?php

declare(strict_types=1);

namespace Drupal\headless_frontend_editing\Routing;

use Drupal\Core\Routing\RouteSubscriberBase;
use Drupal\headless_frontend_editing\Controller\PreviewController;
use Symfony\Component\Routing\RouteCollection;

/**
 * Renders the decoupled frontend on the node view routes.
 */
final class RouteSubscriber extends RouteSubscriberBase {

  /**
   * {@inheritdoc}
   */
  protected function alterRoutes(RouteCollection $collection): void {
    foreach (PreviewController::ROUTES as $route_name => $method) {
      // The controller falls back to core for unconfigured bundles.
      $collection->get($route_name)?->setDefault('_controller', PreviewController::class . '::' . $method)
        ->setOption('_admin_route', TRUE);
    }
  }

}
