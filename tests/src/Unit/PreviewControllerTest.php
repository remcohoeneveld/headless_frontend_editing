<?php

declare(strict_types=1);

namespace Drupal\Tests\headless_frontend_editing\Unit;

use Drupal\headless_frontend_editing\Controller\PreviewController;
use Drupal\Tests\UnitTestCase;

/**
 * Tests the base path handling of the preview controller.
 *
 * @group headless_frontend_editing
 */
final class PreviewControllerTest extends UnitTestCase {

  /**
   * Tests stripping the base path.
   *
   * @testWith ["/sub/node/1", "/sub", "/node/1"]
   *           ["/node/1", "", "/node/1"]
   *           ["/subway/x", "/sub", "/subway/x"]
   */
  public function testWithoutBasePath(string $path, string $base, string $expected): void {
    $this->assertSame($expected, PreviewController::withoutBasePath($path, $base));
  }

}
