<?php

declare(strict_types=1);

namespace Drupal\headless_frontend_editing\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\headless_frontend_editing\PreviewToken;
use Drupal\headless_frontend_editing\TargetResolver;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serves the editing protocol endpoints and the frontend overlay client.
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
    private readonly ModuleExtensionList $moduleList,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get(TargetResolver::class),
      $container->get(PreviewToken::class),
      $container->get('entity_type.bundle.info'),
      $container->get('extension.list.module'),
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
      if (!$target || !$this->resolver->access($target, $this->currentUser())->isAllowed()) {
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

  /**
   * Serves the overlay client that frontends load in their preview route.
   *
   * Cached briefly and revalidated by ETag, so updates reach editors soon
   * without frontends adding a version to the URL.
   */
  public function client(Request $request): Response {
    $script = (string) file_get_contents($this->moduleList->getPath('headless_frontend_editing') . '/js/client.js');
    $response = new Response($script, 200, ['Content-Type' => 'text/javascript; charset=utf-8']);
    $response->setEtag(hash('sha256', $script));
    $response->setPublic();
    $response->setMaxAge(300);
    $response->isNotModified($request);
    return $response;
  }

}
