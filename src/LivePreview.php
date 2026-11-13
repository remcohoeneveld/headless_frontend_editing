<?php

declare(strict_types=1);

namespace Drupal\headless_frontend_editing;

use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityFormInterface;
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
   * @param array<string, mixed> $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public function callback(array &$form, FormStateInterface $form_state): AjaxResponse {
    // Half-typed content may not validate yet; those errors are for saving.
    $this->messenger->deleteByType(MessengerInterface::TYPE_ERROR);
    $response = new AjaxResponse();
    $form_object = $form_state->getFormObject();
    assert($form_object instanceof EntityFormInterface);
    $entity = $form_object->buildEntity($form, $form_state);
    assert($entity instanceof ContentEntityInterface);
    $root = $this->moduleHandler->invokeAll('headless_frontend_editing_preview_root', [$entity, $form_state])[0] ?? $entity;
    if (!$root instanceof Node) {
      return $response;
    }

    // The same structure NodeForm::preview() stores, so core's node_preview
    // parameter converter loads it.
    $root->in_preview = TRUE;
    $preview = (new FormState())->setFormObject(
      $this->entityTypeManager->getFormObject('node', 'default')->setEntity($root),
    );
    $key = 'hfe-live-' . $root->uuid();
    $this->tempStoreFactory->get('node_preview')->set($key, $preview);

    $path = Url::fromRoute('entity.node.preview', ['node_preview' => $key, 'view_mode_id' => 'full'])->toString();
    $path = PreviewController::withoutBasePath($path, $this->requestStack->getCurrentRequest()->getBasePath());
    return $response->addCommand(new LivePreviewCommand($path));
  }

}
