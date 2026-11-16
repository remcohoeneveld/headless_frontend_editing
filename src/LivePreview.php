<?php

declare(strict_types=1);

namespace Drupal\headless_frontend_editing;

use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityFormInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Form\FormState;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Drupal\Core\Url;
use Drupal\headless_frontend_editing\Ajax\LivePreviewCommand;
use Drupal\headless_frontend_editing\Controller\PreviewController;
use Drupal\node\Entity\Node;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Turns the unsaved sidebar form into a node preview for the frontend.
 */
final class LivePreview {

  public function __construct(
    private readonly ModuleHandlerInterface $moduleHandler,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly PrivateTempStoreFactory $tempStoreFactory,
    private readonly MessengerInterface $messenger,
    private readonly RequestStack $requestStack,
  ) {}

  /**
   * Ajax callback of the hidden live preview button.
   *
   * Drupal 10 only calls static "Class::method" callbacks, so this one hands
   * over to the service.
   *
   * @param array<string, mixed> $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public static function ajaxCallback(array &$form, FormStateInterface $form_state): AjaxResponse {
    return \Drupal::service(self::class)->callback($form, $form_state);
  }

  /**
   * Builds the live preview response.
   *
   * @param array<string, mixed> $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public function callback(array &$form, FormStateInterface $form_state): AjaxResponse {
    // Half-typed content may not validate yet; those errors are for saving.
    // Other errors in the session stay.
    $own = array_map('strval', $form_state->getErrors());
    $errors = $this->messenger->deleteByType(MessengerInterface::TYPE_ERROR);
    foreach ($errors as $error) {
      if (!in_array((string) $error, $own, TRUE)) {
        $this->messenger->addError($error);
      }
    }
    $form_object = $form_state->getFormObject();
    assert($form_object instanceof EntityFormInterface);
    $entity = $form_object->buildEntity($form, $form_state);
    assert($entity instanceof ContentEntityInterface);
    $root = $this->moduleHandler->invokeAll('headless_frontend_editing_preview_root', [$entity, $form_state])[0] ?? $entity;
    if (!$root instanceof Node) {
      return new AjaxResponse();
    }

    return $this->show($root, self::key($entity));
  }

  /**
   * Stores a node as a preview and tells the sidebar to show it.
   */
  public function show(Node $root, string $key): AjaxResponse {
    // The same structure NodeForm::preview() stores, so core's node_preview
    // parameter converter loads it.
    $root->in_preview = TRUE;
    $preview = (new FormState())->setFormObject(
      $this->entityTypeManager->getFormObject('node', 'default')->setEntity($root),
    );
    $this->tempStoreFactory->get('node_preview')->set($key, $preview);

    $path = Url::fromRoute('entity.node.preview', ['node_preview' => $key, 'view_mode_id' => 'full'])->toString();
    $path = PreviewController::withoutBasePath($path, $this->requestStack->getCurrentRequest()->getBasePath());
    return (new AjaxResponse())->addCommand(new LivePreviewCommand($path));
  }

  /**
   * Removes the preview of an edited entity.
   */
  public function delete(EntityInterface $entity): void {
    $this->tempStoreFactory->get('node_preview')->delete(self::key($entity));
  }

  /**
   * Returns the node preview tempstore key for an edited entity.
   */
  public static function key(EntityInterface $entity): string {
    return 'hfe-live-' . $entity->uuid();
  }

}
