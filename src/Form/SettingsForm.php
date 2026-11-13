<?php

declare(strict_types=1);

namespace Drupal\headless_frontend_editing\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Settings for the headless frontend connection.
 */
final class SettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'headless_frontend_editing_settings';
  }

  /**
   * {@inheritdoc}
   *
   * @return string[]
   *   Config names.
   */
  protected function getEditableConfigNames(): array {
    return ['headless_frontend_editing.settings'];
  }

  /**
   * {@inheritdoc}
   *
   * @param array<string, mixed> $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return array<string, mixed>
   *   The form.
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['frontend_url'] = [
      '#type' => 'url',
      '#title' => $this->t('Frontend URL'),
      '#description' => $this->t('Public URL of the decoupled frontend, for example https://front.example.'),
      '#config_target' => 'headless_frontend_editing.settings:frontend_url',
    ];
    $form['frontend_internal_url'] = [
      '#type' => 'url',
      '#title' => $this->t('Frontend internal URL'),
      '#description' => $this->t('URL Drupal uses to reach the frontend server-side, for example for revalidation.'),
      '#config_target' => 'headless_frontend_editing.settings:frontend_internal_url',
    ];
    $form['preview_path'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Preview path'),
      '#config_target' => 'headless_frontend_editing.settings:preview_path',
    ];
    $form['revalidate_path'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Revalidate path'),
      '#config_target' => 'headless_frontend_editing.settings:revalidate_path',
    ];
    $form['form_mode'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Form mode'),
      '#description' => $this->t('Machine name of the form mode used for in-place editing.'),
      '#config_target' => 'headless_frontend_editing.settings:form_mode',
    ];
    return parent::buildForm($form, $form_state);
  }

}
