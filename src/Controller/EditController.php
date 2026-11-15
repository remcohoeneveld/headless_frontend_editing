<?php

declare(strict_types=1);

namespace Drupal\headless_frontend_editing\Controller;

use Drupal\Component\Utility\Html;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Messenger\MessengerInterface;
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
   * Returns the title of the edit form: the target's edit action.
   */
  public function title(string $langcode, string $target): string {
    $resolved = $this->resolver->resolve($target, $langcode);
    if (!$resolved) {
      return (string) $this->t('Edit');
    }
    if ($resolved->fieldName === NULL && $resolved->entity->getEntityTypeId() === 'node') {
      return (string) $this->t('Edit page');
    }
    return $this->resolver->describe($resolved, $langcode)['actions'][0]['label'];
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
   * The form's status messages, such as core's "Page X has been updated.",
   * go to the host as plain text, which shows them once the preview has
   * reloaded.
   *
   * @return array<string, mixed>
   *   A render array.
   */
  public function saved(): array {
    $messages = array_map(
      fn ($message) => trim(Html::decodeEntities(strip_tags((string) $message))),
      $this->messenger()->deleteByType(MessengerInterface::TYPE_STATUS),
    );
    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['hfe-saved'], 'data-hfe-message' => implode(' ', $messages)],
      'message' => ['#markup' => $this->t('Saved. Updating the preview…')],
      '#cache' => ['max-age' => 0],
    ];
  }

}
