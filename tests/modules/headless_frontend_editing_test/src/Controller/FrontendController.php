<?php

declare(strict_types=1);

namespace Drupal\headless_frontend_editing_test\Controller;

use Drupal\Component\Utility\Html;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Renders a node the way a decoupled frontend's preview route would.
 */
final class FrontendController extends ControllerBase {

  public function __construct(
    private readonly PrivateTempStoreFactory $tempStoreFactory,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('tempstore.private'));
  }

  /**
   * Renders the node at ?path, with editing targets and client.js on ?edit.
   */
  public function page(Request $request): Response {
    $path = (string) $request->query->get('path');
    if (!preg_match('#^(?:/[a-z]{2,3}(?:-[a-z0-9]+)?)?/node/(?:(\d+)|preview/([\w-]+)/full)$#', $path, $matches)) {
      throw new NotFoundHttpException();
    }
    $node = isset($matches[2])
      ? $this->tempStoreFactory->get('node_preview')->get($matches[2])?->getFormObject()->getEntity()
      : $this->entityTypeManager()->getStorage('node')->load($matches[1]);
    if (!$node instanceof NodeInterface || !$node->access('view')) {
      throw new NotFoundHttpException();
    }

    $edit = $request->query->get('edit');
    $target = fn (string $id) => $edit ? sprintf(' data-hfe="%s"', Html::escape($id)) : '';
    $script = $edit ? sprintf('<script src="%s" defer></script>', Html::escape(Url::fromRoute('headless_frontend_editing.client')->setAbsolute()->toString())) : '';
    $id = 'node:' . $node->uuid();
    return new Response(sprintf(
      '<!DOCTYPE html><html lang="%s"><head><meta charset="utf-8"><title>%s</title>%s</head><body><main%s><h1%s>%s</h1></main></body></html>',
      $node->language()->getId(),
      Html::escape($node->label()),
      $script,
      $target($id),
      $target($id . ':title'),
      Html::escape($node->label()),
    ));
  }

}
