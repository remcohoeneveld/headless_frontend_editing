<?php

declare(strict_types=1);

namespace Drupal\headless_frontend_editing\Controller;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Session\AccountInterface;
use Drupal\headless_frontend_editing\FieldFocus;
use Drupal\headless_frontend_editing\Hook\EntityFormHooks;
use Drupal\headless_frontend_editing\TargetResolver;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Serves the editing form and the saved page shown in the sidebar.
 */
final class EditController extends ControllerBase {

  /**
   * The routes rendered inside the sidebar iframe.
   */
  public const ROUTES = ['headless_frontend_editing.edit', 'headless_frontend_editing.saved'];

  public function __construct(
    private readonly TargetResolver $resolver,
    private readonly FieldFocus $fieldFocus,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get(TargetResolver::class),
      $container->get(FieldFocus::class),
    );
  }

  /**
   * Builds the entity form for a target, limited to its field if it has one.
   *
   * @return array<string, mixed>
   *   The form.
   */
  public function edit(string $langcode, string $target): array {
    $resolved = $this->resolver->resolve($target, $langcode) ?? throw new NotFoundHttpException();
    $this->fieldFocus->set($resolved->entity->getEntityTypeId(), $resolved->entity->bundle(), $resolved->fieldName);
    return $this->entityFormBuilder()->getForm($resolved->entity, EntityFormHooks::OPERATION, [
      'hfe' => TRUE,
      'hfe_target' => $resolved->id(),
      'langcode' => $resolved->entity->language()->getId(),
    ]);
  }

  /**
   * Checks access to the target, see TargetResolver::access().
   *
   * Targets that do not resolve are let through, so edit() can answer 404.
   */
  public function access(AccountInterface $account, string $langcode, string $target): AccessResultInterface {
    $resolved = $this->resolver->resolve($target, $langcode);
    return $resolved ? $this->resolver->access($resolved, $account) : AccessResult::allowed()->setCacheMaxAge(0);
  }

  /**
   * Tells the editing host the form was saved.
   *
   * @return array<string, mixed>
   *   A render array.
   */
  public function saved(): array {
    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['hfe-saved']],
      'message' => ['#markup' => $this->t('Saved. Updating the preview…')],
      '#attached' => ['library' => ['headless_frontend_editing/sidebar']],
      '#cache' => ['max-age' => 0],
    ];
  }

}
