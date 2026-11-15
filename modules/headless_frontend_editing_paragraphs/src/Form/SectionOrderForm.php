<?php

declare(strict_types=1);

namespace Drupal\headless_frontend_editing_paragraphs\Form;

use Drupal\Component\Utility\Html;
use Drupal\Component\Utility\Unicode;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\headless_frontend_editing\LivePreview;
use Drupal\headless_frontend_editing\Target;
use Drupal\headless_frontend_editing\TargetResolver;
use Drupal\headless_frontend_editing_paragraphs\ParagraphTree;
use Drupal\node\Entity\Node;
use Drupal\paragraphs\ParagraphInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Lists the sections of a page, to reorder them by dragging, in the sidebar.
 *
 * The page is saved as one new revision.
 */
final class SectionOrderForm extends FormBase {

  /**
   * Field types whose first value summarizes a section.
   */
  private const TEXT_TYPES = ['string', 'string_long', 'text', 'text_long', 'text_with_summary'];

  /**
   * The page whose sections are listed, in the language edited.
   */
  protected ContentEntityInterface $entity;

  public function __construct(
    protected ParagraphTree $tree,
    protected TargetResolver $resolver,
    protected LivePreview $livePreview,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get(ParagraphTree::class),
      $container->get(TargetResolver::class),
      $container->get(LivePreview::class),
    );
  }

  /**
   * Resolves a target naming a whole entity that is not itself a section.
   *
   * Composite entities (paragraphs) are left out: they are saved through
   * their host, which this form does not do.
   */
  public static function resolve(TargetResolver $resolver, string $target, string $langcode): ?Target {
    $resolved = $resolver->resolve($target, $langcode);
    return $resolved && $resolved->fieldName === NULL && !$resolved->entity->getEntityType()->get('entity_revision_parent_type_field') ? $resolved : NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'headless_frontend_editing_paragraphs_order';
  }

  /**
   * {@inheritdoc}
   *
   * @param array<string, mixed> $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   * @param string|null $langcode
   *   The language edited.
   * @param string|null $target
   *   The target of the page, such as node:<uuid>.
   *
   * @return array<string, mixed>
   *   The form.
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?string $langcode = NULL, ?string $target = NULL): array {
    $resolved = self::resolve($this->resolver, (string) $target, (string) $langcode);
    $this->entity = $resolved->entity ?? throw new NotFoundHttpException();
    $fields = $this->tree->sectionFields($this->entity, $this->currentUser());
    $form['fields'] = ['#tree' => TRUE];
    $form['#attributes']['class'][] = 'hfe-form';
    $form['#attached']['library'][] = 'headless_frontend_editing_paragraphs/order';
    foreach ($fields as $name => $items) {
      $paragraphs = array_filter($items->referencedEntities(), fn ($paragraph) => $paragraph instanceof ParagraphInterface);
      $rows = [];
      foreach (array_values($paragraphs) as $delta => $paragraph) {
        $rows[$paragraph->id()] = $this->row($paragraph, $delta, count($paragraphs));
      }
      $form['fields'][$name] = [
        // What the user saw, to refuse saving over sections changed since.
        'ids' => ['#type' => 'hidden', '#default_value' => implode(',', $this->ids($name))],
        'sections' => [
          '#type' => 'table',
          '#header' => [$this->t('Section'), $this->t('Operations'), $this->t('Weight')],
          '#empty' => $this->t('There are no sections yet.'),
          '#attributes' => ['class' => ['hfe-order']],
          '#tabledrag' => [[
            'action' => 'order',
            'relationship' => 'sibling',
            'group' => 'hfe-order__weight',
          ],
          ],
        ] + $rows,
      ];
      if (count($fields) > 1) {
        $form['fields'][$name]['sections']['#caption'] = $items->getFieldDefinition()->getLabel();
      }
      $last = end($paragraphs);
      if ($last && $this->tree->allowedTypes($last)) {
        $form['fields'][$name]['add'] = [
          '#type' => 'link',
          '#title' => $this->t('Add section'),
          '#url' => Url::fromRoute('headless_frontend_editing_paragraphs.add', [
            'uuid' => $last->uuid(),
            'position' => 'after',
          ]),
          '#attributes' => ['class' => ['hfe-order__add']],
        ];
      }
    }
    if ($this->entity instanceof Node) {
      // Shows the new order in the preview after each drop; see order.js.
      $form['hfe_live'] = [
        '#type' => 'button',
        '#name' => 'hfe_live',
        '#value' => $this->t('Update preview'),
        '#attributes' => ['class' => ['hfe-live'], 'hidden' => TRUE],
        '#ajax' => [
          'callback' => '::livePreview',
          'event' => 'hfe:live',
          'disable-refocus' => TRUE,
          'progress' => ['type' => 'none'],
        ],
      ];
    }
    $form['actions'] = [
      '#type' => 'actions',
      'submit' => ['#type' => 'submit', '#value' => $this->t('Save order'), '#button_type' => 'primary'],
    ];
    return $form;
  }

  /**
   * Builds the table row of a section.
   *
   * @return array<string, mixed>
   *   The row.
   */
  private function row(ParagraphInterface $paragraph, int $delta, int $count): array {
    $langcode = $this->entity->language()->getId();
    if ($paragraph->hasTranslation($langcode)) {
      $paragraph = $paragraph->getTranslation($langcode);
    }
    $operations = [
      'edit' => [
        '#type' => 'link',
        '#title' => $this->t('Edit'),
        '#url' => Url::fromRoute('headless_frontend_editing.edit', [
          'langcode' => $paragraph->language()->getId(),
          'target' => 'paragraph:' . $paragraph->uuid(),
        ]),
      ],
    ];
    if ($paragraph->access('delete')) {
      $operations['delete'] = [
        '#type' => 'link',
        '#title' => $this->t('Delete'),
        '#url' => Url::fromRoute('headless_frontend_editing_paragraphs.delete', ['uuid' => $paragraph->uuid()]),
      ];
    }
    return [
      '#attributes' => ['class' => ['draggable', 'hfe-order__row']],
      'section' => [
        'type' => [
          '#type' => 'html_tag',
          '#tag' => 'strong',
          '#value' => Html::escape((string) $paragraph->getParagraphType()->label()),
          '#attributes' => ['class' => ['hfe-order__type']],
        ],
        'summary' => [
          '#type' => 'html_tag',
          '#tag' => 'div',
          '#value' => Html::escape(Unicode::truncate($this->summary($paragraph), 80, TRUE, TRUE)),
          '#attributes' => ['class' => ['hfe-order__summary']],
        ],
      ],
      'operations' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['hfe-order__actions']],
      ] + $operations,
      'weight' => [
        '#type' => 'weight',
        '#title' => $this->t('Weight for @section', ['@section' => $paragraph->getParagraphType()->label()]),
        '#title_display' => 'invisible',
        '#default_value' => $delta,
        '#delta' => max(10, $count),
        '#attributes' => ['class' => ['hfe-order__weight']],
      ],
    ];
  }

  /**
   * Returns the first text an editor wrote in a section, as plain text.
   *
   * Not Paragraph::getSummary(): that also lists option keys and settings,
   * which mean nothing to an editor.
   */
  private function summary(ParagraphInterface $paragraph): string {
    foreach ($paragraph->getFieldDefinitions() as $name => $definition) {
      if ($definition->getFieldStorageDefinition()->isBaseField() || !in_array($definition->getType(), self::TEXT_TYPES, TRUE)) {
        continue;
      }
      $text = trim(Html::decodeEntities(strip_tags((string) $paragraph->get($name)->value)));
      if ($text !== '') {
        return preg_replace('/\s+/', ' ', $text);
      }
    }
    return '';
  }

  /**
   * Returns the ids of the sections a field references now, in order.
   *
   * @return string[]
   *   The paragraph ids.
   */
  private function ids(string $field_name): array {
    return array_map(fn ($value) => (string) ($value['target_id'] ?? ''), $this->entity->get($field_name)->getValue());
  }

  /**
   * {@inheritdoc}
   *
   * @param array<string, mixed> $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    // The form object may come from the form cache (the live preview button
    // is an #ajax element): compare with, and save onto, the page as it is now.
    [$langcode, $target] = $form_state->getBuildInfo()['args'] + [NULL, NULL];
    $this->entity = self::resolve($this->resolver, (string) $target, (string) $langcode)->entity ?? throw new NotFoundHttpException();
    foreach (array_keys($form['fields']) as $name) {
      if (is_string($name) && $name[0] !== '#' && $form_state->getValue(['fields', $name, 'ids']) !== implode(',', $this->ids($name))) {
        $form_state->setErrorByName('fields', $this->t('The page changed since you opened this view. Reload and try again.'));
        return;
      }
    }
  }

  /**
   * {@inheritdoc}
   *
   * @param array<string, mixed> $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    if ($this->reorder($this->entity, $form_state)) {
      $this->tree->saveRoot($this->entity, (string) $this->t('Reordered sections from the frontend preview.'));
    }
    $this->livePreview->delete($this->entity);
    $this->messenger()->addStatus($this->t('Section order saved.'));
    $form_state->setRedirect('headless_frontend_editing.saved');
  }

  /**
   * Ajax callback: shows the page in the dragged order, unsaved.
   *
   * @param array<string, mixed> $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public function livePreview(array &$form, FormStateInterface $form_state): AjaxResponse {
    $node = clone $this->entity;
    if (!$node instanceof Node || $form_state->hasAnyErrors()) {
      return new AjaxResponse();
    }
    $this->reorder($node, $form_state);
    return $this->livePreview->show($node, LivePreview::key($node));
  }

  /**
   * Puts the sections of an entity in the submitted order.
   *
   * @return bool
   *   Whether the order changed.
   */
  private function reorder(ContentEntityInterface $entity, FormStateInterface $form_state): bool {
    $changed = FALSE;
    foreach ($form_state->getValue('fields') ?: [] as $name => $values) {
      $items = $entity->get($name);
      $old = $items->getValue();
      $weights = [];
      foreach ($old as $delta => $value) {
        // Sorted by weight, then by the old position.
        $weights[$delta] = [(int) ($values['sections'][$value['target_id'] ?? '']['weight'] ?? $delta), $delta];
      }
      asort($weights);
      $new = array_map(fn ($delta) => $old[$delta], array_keys($weights));
      if ($new !== $old) {
        $items->setValue($new);
        $changed = TRUE;
      }
    }
    return $changed;
  }

}
