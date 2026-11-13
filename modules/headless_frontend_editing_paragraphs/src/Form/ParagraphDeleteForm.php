<?php

declare(strict_types=1);

namespace Drupal\headless_frontend_editing_paragraphs\Form;

use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\headless_frontend_editing_paragraphs\ParagraphTree;
use Drupal\paragraphs\ParagraphInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Removes a section from its page, in the sidebar.
 *
 * The page is saved as a new revision, so the section can be restored from
 * the page's revisions.
 */
final class ParagraphDeleteForm extends ConfirmFormBase {

  /**
   * The section to remove.
   */
  protected ParagraphInterface $paragraph;

  public function __construct(
    protected ParagraphTree $tree,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get(ParagraphTree::class));
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'headless_frontend_editing_paragraphs_delete';
  }

  /**
   * {@inheritdoc}
   *
   * @param array<string, mixed> $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   * @param string|null $uuid
   *   The uuid of the section.
   *
   * @return array<string, mixed>
   *   The form.
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?string $uuid = NULL): array {
    $this->paragraph = $this->tree->load((string) $uuid) ?? throw new NotFoundHttpException();
    $form = parent::buildForm($form, $form_state);
    // The sidebar has its own close button; a cancel link would only navigate
    // inside it.
    unset($form['actions']['cancel']);
    if (count($this->tree->items($this->paragraph)) < 2) {
      $form['description']['#markup'] = $this->t('This is the only section, so it cannot be deleted. Add another section first.');
      unset($form['actions']['submit']);
    }
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion(): TranslatableMarkup {
    return $this->t('Delete the @section section?', ['@section' => $this->paragraph->getParagraphType()->label()]);
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription(): TranslatableMarkup {
    return $this->t('The page is saved as a new revision, from which the section can be restored.');
  }

  /**
   * {@inheritdoc}
   */
  public function getConfirmText(): TranslatableMarkup {
    return $this->t('Delete');
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl(): Url {
    return Url::fromRoute('headless_frontend_editing.saved');
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
    $items = $this->tree->items($this->paragraph);
    if (count($items) < 2) {
      return;
    }
    $items->removeItem($this->tree->delta($items, $this->paragraph));
    $parent = $this->paragraph->getParentEntity();
    $root = $parent instanceof ParagraphInterface ? $this->tree->attach($parent) : $parent;
    $this->tree->saveRoot($root, (string) $this->t('Deleted @section from the frontend preview.', ['@section' => $this->paragraph->getParagraphType()->label()]));
    $form_state->setRedirect('headless_frontend_editing.saved');
  }

}
