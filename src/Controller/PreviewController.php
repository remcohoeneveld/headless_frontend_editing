<?php

declare(strict_types=1);

namespace Drupal\headless_frontend_editing\Controller;

use Drupal\Core\Block\BlockManagerInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\DependencyInjection\ClassResolverInterface;
use Drupal\Core\Entity\Controller\EntityViewController;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Form\FormBuilderInterface;
use Drupal\Core\Link;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Url;
use Drupal\headless_frontend_editing\PreviewToken;
use Drupal\node\Controller\NodeController;
use Drupal\node\Controller\NodePreviewController;
use Drupal\node\Form\NodePreviewForm;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Renders the decoupled frontend in place of the node view routes.
 */
final class PreviewController extends ControllerBase {

  /**
   * The routes taken over, mapped to the method that serves them.
   */
  public const ROUTES = [
    'entity.node.canonical' => 'canonical',
    'entity.node.revision' => 'revision',
    'entity.node.preview' => 'preview',
  ];

  public function __construct(
    private readonly PreviewToken $previewToken,
    private readonly BlockManagerInterface $blockManager,
    private readonly ClassResolverInterface $classResolver,
    private readonly RequestStack $requestStack,
    private readonly RouteMatchInterface $routeMatch,
    FormBuilderInterface $formBuilder,
  ) {
    $this->formBuilder = $formBuilder;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get(PreviewToken::class),
      $container->get('plugin.manager.block'),
      $container->get('class_resolver'),
      $container->get('request_stack'),
      $container->get('current_route_match'),
      $container->get('form_builder'),
    );
  }

  /**
   * Serves entity.node.canonical.
   *
   * @return array<string, mixed>
   *   A render array.
   */
  public function canonical(NodeInterface $node, string $view_mode = 'full'): array {
    return $this->render($node, $node->toUrl('canonical')->toString(), NULL,
      fn () => $this->classResolver->getInstanceFromDefinition(EntityViewController::class)->view($node, $view_mode));
  }

  /**
   * Serves entity.node.revision.
   *
   * @return array<string, mixed>
   *   A render array.
   */
  public function revision(NodeInterface $node_revision): array {
    return $this->render($node_revision, $node_revision->toUrl('canonical')->toString(), (int) $node_revision->getRevisionId(),
      fn () => $this->classResolver->getInstanceFromDefinition(NodeController::class)->revisionShow($node_revision));
  }

  /**
   * Serves entity.node.preview.
   *
   * @return array<string, mixed>
   *   A render array.
   */
  public function preview(EntityInterface $node_preview, string $view_mode_id = 'full'): array {
    assert($node_preview instanceof NodeInterface);
    $build = $this->render($node_preview, $this->requestStack->getCurrentRequest()->getPathInfo(), NULL,
      fn () => $this->classResolver->getInstanceFromDefinition(NodePreviewController::class)->view($node_preview, $view_mode_id));
    if (isset($build['hfe'])) {
      $build['#attached']['library'][] = 'node/drupal.node.preview';
      $build['#attached']['page_top']['node_preview'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['node-preview-container', 'container-inline']],
        'view_mode' => $this->formBuilder->getForm(NodePreviewForm::class, $node_preview),
      ];
    }
    return $build;
  }

  /**
   * Builds the panel and preview iframe, or the core render via $fallback.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node to show.
   * @param string $path
   *   Path the frontend renders, without base path or host.
   * @param int|null $revision_id
   *   The revision to show, for the revision route.
   * @param callable $fallback
   *   Returns core's own render array.
   *
   * @return array<string, mixed>
   *   A render array.
   */
  private function render(NodeInterface $node, string $path, ?int $revision_id, callable $fallback): array {
    $config = $this->config('headless_frontend_editing.settings');
    $bundles = $config->get('bundles.node') ?: [];
    if ($bundles && !in_array($node->bundle(), $bundles, TRUE)) {
      return $fallback();
    }
    $frontend_url = rtrim((string) $config->get('frontend_url'), '/');
    if ($frontend_url === '') {
      if (!$this->currentUser()->hasPermission('administer headless frontend editing')) {
        return $fallback();
      }
      return [
        '#markup' => $this->t('Set the frontend URL on the <a href=":url">settings form</a> to show the decoupled frontend here.', [
          ':url' => Url::fromRoute('headless_frontend_editing.settings')->toString(),
        ]),
        '#cache' => ['max-age' => 0],
      ];
    }

    $route_name = $this->routeMatch->getRouteName();
    $uses_editor = $this->currentUser()->hasPermission('use headless frontend editing');
    $editable = $uses_editor && $route_name === 'entity.node.canonical' && $node->isDefaultRevision() && $node->access('update');
    $src = $frontend_url . $path;
    if ($uses_editor) {
      // Built by hand: Url would leave the slashes in path unencoded.
      $src = $frontend_url . $config->get('preview_path') . '?' . http_build_query(array_filter([
        'path' => $path,
        'token' => $this->previewToken->create($this->currentUser()),
        'revision' => $revision_id,
        'edit' => $editable ? 1 : NULL,
      ]), '', '&', PHP_QUERY_RFC3986);
    }

    $build = [
      'hfe' => [
        'panel' => $this->panel($node, $frontend_url . $path, $route_name),
        'preview' => $this->frame('hfe-preview', $src, $this->t('Preview of @label', ['@label' => $node->label()])),
      ],
    ];
    if ($editable) {
      $build['hfe']['editor'] = [
        '#type' => 'container',
        '#attributes' => [
          'class' => ['hfe-editor'],
          'role' => 'complementary',
          'aria-label' => $this->t('Editor'),
          'hidden' => TRUE,
        ],
        'close' => [
          '#type' => 'html_tag',
          '#tag' => 'button',
          '#value' => $this->t('Close'),
          '#attributes' => ['type' => 'button', 'class' => ['hfe-editor__close']],
        ],
        'frame' => $this->frame('hfe-editor__frame', NULL, $this->t('Editor')),
      ];
    }
    $build['#cache']['max-age'] = 0;
    $build['#attached']['library'][] = 'headless_frontend_editing/host';
    $build['#attached']['drupalSettings']['headlessFrontendEditing'] = [
      'frontendOrigin' => $this->origin($frontend_url),
      'path' => $path,
      'editable' => $editable,
      // Task 6 switches these to Url::fromRoute().
      'tokenUrl' => Url::fromUserInput('/headless-frontend-editing/token')->toString(),
      'describeUrl' => Url::fromUserInput('/headless-frontend-editing/describe')->toString(),
    ];
    return $build;
  }

  /**
   * Returns scheme://host[:port] of a URL.
   */
  private function origin(string $url): string {
    $parts = parse_url($url);
    return $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
  }

  /**
   * Builds the collapsible panel with node status, tabs and live link.
   *
   * @return array<string, mixed>
   *   A render array.
   */
  private function panel(NodeInterface $node, string $live_url, ?string $route_name): array {
    $body = [
      '#type' => 'container',
      '#attributes' => ['id' => 'hfe-panel-body', 'class' => ['hfe-panel__body']],
      'label' => [
        '#type' => 'inline_template',
        '#template' => '<strong>{{ label }}</strong> {{ status }}',
        '#context' => [
          'label' => $node->label(),
          'status' => $node->isPublished() ? $this->t('Published') : $this->t('Unpublished'),
        ],
      ],
      'tasks' => $this->blockManager
        ->createInstance('local_tasks_block', ['primary' => TRUE, 'secondary' => FALSE])
        ->build(),
    ];
    if ($node->isPublished() && $node->isDefaultRevision() && $route_name !== 'entity.node.preview') {
      $body['live'] = Link::fromTextAndUrl($this->t('Open on website'), Url::fromUri($live_url))->toRenderable();
    }
    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['hfe-panel']],
      'toggle' => [
        '#type' => 'html_tag',
        '#tag' => 'button',
        '#value' => $this->t('Editor panel'),
        '#attributes' => [
          'type' => 'button',
          'class' => ['hfe-panel__toggle'],
          'aria-expanded' => 'true',
          'aria-controls' => 'hfe-panel-body',
        ],
      ],
      'body' => $body,
    ];
  }

  /**
   * Builds an iframe.
   *
   * @return array<string, mixed>
   *   A render array.
   */
  private function frame(string $class, ?string $src, mixed $title): array {
    return [
      '#type' => 'html_tag',
      '#tag' => 'iframe',
      '#attributes' => ['class' => [$class], 'src' => $src, 'title' => $title],
    ];
  }

}
