<?php

declare(strict_types=1);

namespace Drupal\headless_frontend_editing\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\headless_frontend_editing\PreviewToken;
use Drupal\headless_frontend_editing\TargetResolver;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Serves the describe and token endpoints used by the editing host page.
 */
final class ProtocolController extends ControllerBase {

  /**
   * The most targets one describe request is answered for.
   */
  private const MAX_TARGETS = 200;

  public function __construct(
    private readonly TargetResolver $resolver,
    private readonly PreviewToken $previewToken,
    private readonly EntityTypeBundleInfoInterface $bundleInfo,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get(TargetResolver::class),
      $container->get(PreviewToken::class),
      $container->get('entity_type.bundle.info'),
    );
  }

  /**
   * Describes the targets the current user may edit; others are left out.
   */
  public function describe(Request $request): JsonResponse {
    $items = json_decode($request->getContent(), TRUE);
    $items = is_array($items) && is_array($items['targets'] ?? NULL) ? array_slice($items['targets'], 0, self::MAX_TARGETS) : [];
    $result = [];
    foreach ($items as $item) {
      if (!is_array($item) || !is_string($item['id'] ?? NULL) || !is_string($item['lang'] ?? NULL)) {
        continue;
      }
      $target = $this->resolver->resolve($item['id'], $item['lang']);
      if (!$target || !$target->entity->access('update')) {
        continue;
      }
      $entity = $target->entity;
      if ($target->fieldName === NULL) {
        $bundle = $this->bundleInfo->getBundleInfo($entity->getEntityTypeId())[$entity->bundle()]['label'] ?? $entity->bundle();
        $name = (string) $entity->label();
        $label = $this->t('@label (@bundle)', ['@label' => $name, '@bundle' => $bundle]);
        $action = $this->t('Edit @label', ['@label' => $name]);
      }
      else {
        if (!$entity->get($target->fieldName)->access('edit')) {
          continue;
        }
        $label = $entity->get($target->fieldName)->getFieldDefinition()->getLabel();
        $action = $this->t('Edit @label', ['@label' => $label]);
      }
      $description = [
        'id' => $target->id(),
        'lang' => $item['lang'],
        'label' => (string) $label,
        'actions' => [['id' => 'edit', 'label' => (string) $action, 'disabled' => FALSE]],
      ];
      $this->moduleHandler()->alter('headless_frontend_editing_target', $description, $target);
      $result[] = $description;
    }
    return new JsonResponse(['targets' => $result]);
  }

  /**
   * Returns a fresh preview token for the current user.
   */
  public function token(): JsonResponse {
    $response = new JsonResponse(['token' => $this->previewToken->create($this->currentUser())]);
    $response->headers->set('Cache-Control', 'no-store');
    return $response;
  }

}
