<?php

declare(strict_types=1);

namespace Drupal\headless_frontend_editing_paragraphs\Form;

use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Form\FormStateInterface;
use Drupal\headless_frontend_editing_paragraphs\ParagraphTree;
use Drupal\paragraphs\ParagraphInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Edits or adds a section in the sidebar, saving the page it is on.
 *
 * Saving the paragraph alone would leave the page pointing at the old
 * paragraph revision, so the page is saved as a new revision instead. The
 * form state value hfe_insert = [anchor paragraph, 'before'|'after'] marks a
 * new section.
 */
final class ParagraphForm extends ContentEntityForm {

  /**
   * The paragraph tree service.
   */
  protected ParagraphTree $tree;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    $form = parent::create($container);
    $form->tree = $container->get(ParagraphTree::class);
    return $form;
  }

  /**
   * {@inheritdoc}
   *
   * @param array<string, mixed> $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public function save(array $form, FormStateInterface $form_state): int {
    $paragraph = $this->entity;
    assert($paragraph instanceof ParagraphInterface);
    $insert = $form_state->get('hfe_insert');
    $root = $this->tree->attach($paragraph, $insert);
    if (!$root) {
      return parent::save($form, $form_state);
    }
    $args = ['@section' => $paragraph->getParagraphType()->label()];
    $this->tree->saveRoot($root, (string) ($insert
      ? $this->t('Added @section from the frontend preview.', $args)
      : $this->t('Updated @section from the frontend preview.', $args)));
    // Shown by the editing host once the preview has reloaded.
    $this->messenger()->addStatus($insert ? $this->t('@section added.', $args) : $this->t('@section saved.', $args));
    return $insert ? SAVED_NEW : SAVED_UPDATED;
  }

}
