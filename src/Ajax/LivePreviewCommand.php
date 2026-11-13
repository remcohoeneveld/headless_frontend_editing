<?php

declare(strict_types=1);

namespace Drupal\headless_frontend_editing\Ajax;

use Drupal\Core\Ajax\CommandInterface;

/**
 * Tells the sidebar to show a node preview path in the frontend preview.
 */
final class LivePreviewCommand implements CommandInterface {

  public function __construct(
    private readonly string $path,
  ) {}

  /**
   * {@inheritdoc}
   *
   * @return array<string, string>
   *   The command and the preview path.
   */
  public function render(): array {
    return ['command' => 'hfeLivePreview', 'path' => $this->path];
  }

}
