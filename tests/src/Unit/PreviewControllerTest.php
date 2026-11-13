<?php

declare(strict_types=1);

namespace Drupal\Tests\headless_frontend_editing\Unit;

use Drupal\headless_frontend_editing\Controller\PreviewController;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestWith;

/**
 * Tests the base path handling of the preview controller.
 */
#[Group('headless_frontend_editing')]
final class PreviewControllerTest extends UnitTestCase {

  /**
   * Tests stripping the base path.
   */
  #[TestWith(['/sub/node/1', '/sub', '/node/1'])]
  #[TestWith(['/node/1', '', '/node/1'])]
  #[TestWith(['/subway/x', '/sub', '/subway/x'])]
  public function testWithoutBasePath(string $path, string $base, string $expected): void {
    $this->assertSame($expected, PreviewController::withoutBasePath($path, $base));
  }

}
