<?php

declare(strict_types=1);

namespace Drupal\headless_frontend_editing\PageCache;

use Drupal\Core\PageCache\RequestPolicyInterface;
use Drupal\headless_frontend_editing\Authentication\PreviewTokenAuth;
use Symfony\Component\HttpFoundation\Request;

/**
 * Keeps requests carrying a preview token out of the page caches.
 */
final class DisallowPreviewTokenRequests implements RequestPolicyInterface {

  /**
   * {@inheritdoc}
   */
  public function check(Request $request): ?string {
    return $request->headers->has(PreviewTokenAuth::HEADER) ? self::DENY : NULL;
  }

}
