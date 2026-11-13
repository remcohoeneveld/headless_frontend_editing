<?php

declare(strict_types=1);

namespace Drupal\headless_frontend_editing\Hook;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\ContentEntityTypeInterface;
use Drupal\Core\Entity\Display\EntityFormDisplayInterface;
use Drupal\Core\Entity\EntityFormInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\RevisionLogInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Render\Element;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\headless_frontend_editing\FieldFocus;
use Drupal\headless_frontend_editing\LivePreview;

/**
 * Entity form hooks for editing in the sidebar.
 */
final class EntityFormHooks {

  use StringTranslationTrait;

  /**
   * The form operation used for editing in the sidebar.
   */
  public const OPERATION = 'headless_editing';

  public function __construct(
    private readonly FieldFocus $fieldFocus,
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Implements hook_entity_type_build().
   *
   * Every content entity type with an edit or default form gets the editing
   * operation, with the same class, so its own form logic keeps working.
   *
   * @param array<string, \Drupal\Core\Entity\EntityTypeInterface> $entity_types
   *   The entity types.
   */
  #[Hook('entity_type_build')]
  public function entityTypeBuild(array &$entity_types): void {
    foreach ($entity_types as $entity_type) {
      $class = $entity_type->getFormClass('edit') ?: $entity_type->getFormClass('default');
      if ($entity_type instanceof ContentEntityTypeInterface && $class && !$entity_type->getFormClass(self::OPERATION)) {
        $entity_type->setFormClass(self::OPERATION, $class);
      }
    }
  }

  /**
   * Implements hook_entity_form_mode_alter().
   */
  #[Hook('entity_form_mode_alter')]
  public function formModeAlter(string &$form_mode, EntityInterface $entity): void {
    if ($form_mode === self::OPERATION) {
      $form_mode = $this->formMode();
    }
  }

  /**
   * Implements hook_entity_form_display_alter().
   *
   * Limits the editing form display to the focused field. The moderation state
   * always stays: entity forms ignore violations of fields they do not show,
   * so without it a field edit could skip the editor's workflow transitions.
   *
   * @param \Drupal\Core\Entity\Display\EntityFormDisplayInterface $form_display
   *   The form display.
   * @param array<string, string> $context
   *   The entity type, bundle and form mode.
   */
  #[Hook('entity_form_display_alter')]
  public function formDisplayAlter(EntityFormDisplayInterface $form_display, array $context): void {
    $field_name = $this->fieldFocus->get();
    if ($field_name === NULL || $context['form_mode'] !== $this->formMode()) {
      return;
    }
    foreach (array_keys($form_display->getComponents()) as $name) {
      if ($name !== $field_name && $name !== 'moderation_state') {
        $form_display->removeComponent($name);
      }
    }
  }

  /**
   * Implements hook_form_alter().
   *
   * @param array<mixed> $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  #[Hook('form_alter')]
  public function formAlter(array &$form, FormStateInterface $form_state): void {
    if (!$form_state->get('hfe')) {
      return;
    }
    $form['#attributes']['class'][] = 'hfe-form';
    $form['#attached']['library'][] = 'headless_frontend_editing/sidebar';
    $form['hfe_live'] = [
      '#type' => 'button',
      '#name' => 'hfe_live',
      '#value' => $this->t('Update preview'),
      '#attributes' => ['class' => ['hfe-live'], 'hidden' => TRUE],
      '#ajax' => [
        'callback' => LivePreview::class . '::callback',
        'event' => 'hfe:live',
        'disable-refocus' => TRUE,
        'progress' => ['type' => 'none'],
      ],
    ];
    $form['#entity_builders'][] = [self::class, 'newRevision'];
    $form['actions']['submit']['#submit'][] = [self::class, 'redirectToSaved'];
    // Previewing or deleting would replace the sidebar with a full page.
    foreach (['preview', 'delete'] as $action) {
      if (isset($form['actions'][$action])) {
        $form['actions'][$action]['#access'] = FALSE;
      }
    }

    $form_object = $form_state->getFormObject();
    if ($form_object instanceof EntityFormInterface && $form_object->getEntity()->getEntityTypeId() === 'node') {
      // Hide the node metadata. Elements are hidden one by one, not through
      // their group: a widget left accessible but not rendered would submit
      // empty, which unchecks checkboxes such as promote.
      $hidden = ['advanced' => TRUE, 'meta' => TRUE, 'revision_information' => TRUE, 'revision_log' => TRUE];
      do {
        $count = count($hidden);
        foreach (Element::children($form) as $key) {
          if (isset($form[$key]['#group']) && isset($hidden[$form[$key]['#group']])) {
            $hidden[$key] = TRUE;
          }
        }
      } while (count($hidden) > $count);
      foreach (array_keys($hidden) as $key) {
        if (isset($form[$key])) {
          $form[$key]['#access'] = FALSE;
        }
      }
    }
  }

  /**
   * Entity builder: saves every sidebar edit as a new, logged revision.
   *
   * @param string $entity_type_id
   *   The entity type.
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The entity built from the form.
   */
  public static function newRevision(string $entity_type_id, ContentEntityInterface $entity): void {
    if (!$entity->getEntityType()->isRevisionable()) {
      return;
    }
    $entity->setNewRevision();
    if ($entity instanceof RevisionLogInterface) {
      $entity->setRevisionLogMessage((string) t('Updated @label from the frontend preview.', ['@label' => $entity->label()]));
      $entity->setRevisionUserId((int) \Drupal::currentUser()->id());
      $entity->setRevisionCreationTime(\Drupal::time()->getRequestTime());
    }
  }

  /**
   * Submit handler: sends the sidebar to the saved page.
   *
   * @param array<string, mixed> $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public static function redirectToSaved(array &$form, FormStateInterface $form_state): void {
    $form_state->setRedirect('headless_frontend_editing.saved');
  }

  /**
   * Returns the configured form mode.
   */
  private function formMode(): string {
    return (string) $this->configFactory->get('headless_frontend_editing.settings')->get('form_mode');
  }

}
