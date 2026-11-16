<?php

declare(strict_types=1);

namespace Drupal\headless_frontend_editing_test_access\Hook;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Form\FormStateInterface;

/**
 * Denies editing the promote field; adds a nested grouped checkbox to forms.
 */
final class TestAccessHooks {

  /**
   * Implements hook_entity_field_access().
   *
   * @param string $operation
   *   The operation.
   * @param \Drupal\Core\Field\FieldDefinitionInterface $field_definition
   *   The field.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The user.
   * @param \Drupal\Core\Field\FieldItemListInterface<\Drupal\Core\Field\FieldItemInterface>|null $items
   *   The items.
   */
  public function fieldAccess(string $operation, FieldDefinitionInterface $field_definition, AccountInterface $account, ?FieldItemListInterface $items = NULL): AccessResult {
    return AccessResult::forbiddenIf($operation === 'edit' && $field_definition->getName() === 'promote');
  }

  /**
   * Implements hook_field_widget_single_element_form_alter().
   *
   * Adds a checkbox inside the title widget, grouped into the advanced tabs
   * the way core's path widget is.
   *
   * @param array<mixed> $element
   *   The widget element.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   * @param array<string, mixed> $context
   *   The widget context.
   */
  public function widgetAlter(array &$element, FormStateInterface $form_state, array $context): void {
    if ($form_state->get('hfe') && $context['items']->getName() === 'title') {
      $element['hfe_test_flag'] = [
        '#type' => 'checkbox',
        '#title' => 'Nested flag',
        '#default_value' => TRUE,
        '#group' => 'advanced',
      ];
    }
  }

  /**
   * Implements hook_form_alter().
   *
   * Records what the nested checkbox submits.
   *
   * @param array<mixed> $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public function formAlter(array &$form, FormStateInterface $form_state): void {
    if ($form_state->get('hfe')) {
      $form['#entity_builders'][] = [self::class, 'recordFlag'];
    }
  }

  /**
   * Entity builder: records the submitted nested checkbox value.
   *
   * @param string $entity_type_id
   *   The entity type.
   * @param mixed $entity
   *   The entity.
   * @param array<mixed> $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public static function recordFlag(string $entity_type_id, mixed $entity, array &$form, FormStateInterface $form_state): void {
    \Drupal::state()->set('hfe_test_flag', (int) $form_state->getValue(['title', 0, 'hfe_test_flag']));
  }

}
