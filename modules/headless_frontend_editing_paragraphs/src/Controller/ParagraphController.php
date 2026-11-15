<?php

declare(strict_types=1);

namespace Drupal\headless_frontend_editing_paragraphs\Controller;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\headless_frontend_editing\Hook\EntityFormHooks;
use Drupal\headless_frontend_editing\Target;
use Drupal\headless_frontend_editing\TargetResolver;
use Drupal\headless_frontend_editing_paragraphs\Form\SectionOrderForm;
use Drupal\headless_frontend_editing_paragraphs\ParagraphTree;
use Drupal\paragraphs\ParagraphInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Adds and moves sections from the preview, and checks section route access.
 */
final class ParagraphController extends ControllerBase {

  public function __construct(
    private readonly ParagraphTree $tree,
    private readonly TargetResolver $resolver,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get(ParagraphTree::class), $container->get(TargetResolver::class));
  }

  /**
   * Lists the section types that can be added before or after a section.
   *
   * @return array<string, mixed>
   *   A render array.
   */
  public function add(string $uuid, string $position): array {
    $links = [];
    foreach ($this->tree->allowedTypes($this->load($uuid)) as $bundle => $type) {
      // The type's icon, when the site sets one, previews the section.
      $icon = $type->getIconUrl();
      $links[$bundle] = [
        'title' => [
          'icon' => $icon ? ['#theme' => 'image', '#uri' => $icon, '#alt' => ''] : [],
          'label' => ['#plain_text' => $type->label()],
        ],
        'url' => Url::fromRoute('headless_frontend_editing_paragraphs.add_form', [
          'uuid' => $uuid,
          'position' => $position,
          'bundle' => $bundle,
        ]),
        'attributes' => ['class' => $icon ? ['hfe-add__card'] : ['button', 'button--small']],
      ];
    }
    return [
      'help' => [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $position === 'before' ? $this->t('Add a section above this one.') : $this->t('Add a section below this one.'),
      ],
      'types' => $links
        ? ['#theme' => 'links', '#links' => $links, '#attributes' => ['class' => ['hfe-add']]]
        : ['#markup' => $this->t('No more sections can be added here.')],
      '#attached' => ['library' => ['headless_frontend_editing_paragraphs/add']],
    ];
  }

  /**
   * Builds the form for a new section of the given type.
   *
   * @return array<string, mixed>
   *   The form.
   */
  public function addForm(string $uuid, string $position, string $bundle): array {
    $anchor = $this->load($uuid);
    if (!isset($this->tree->allowedTypes($anchor)[$bundle])) {
      throw new NotFoundHttpException();
    }
    $paragraph = $this->entityTypeManager()->getStorage('paragraph')->create([
      'type' => $bundle,
      'langcode' => $anchor->language()->getId(),
    ]);
    return $this->entityFormBuilder()->getForm($paragraph, EntityFormHooks::OPERATION, [
      'hfe' => TRUE,
      'hfe_insert' => [$anchor, $position],
    ]);
  }

  /**
   * Swaps a section with its neighbor.
   *
   * Moves save at once, without a form to confirm, so the request must name
   * the page the editor has open (?root=node:<uuid>): a script on the
   * frontend could otherwise move sections of any page the editor may edit.
   */
  public function move(string $uuid, string $direction, Request $request): JsonResponse {
    $paragraph = $this->load($uuid);
    $root = $this->tree->root($paragraph);
    if (!$root || $request->query->get('root') !== $root->getEntityTypeId() . ':' . $root->uuid()) {
      throw new AccessDeniedHttpException('The section is not on the page being edited.');
    }
    return new JsonResponse(['moved' => $this->tree->move($paragraph, $direction)]);
  }

  /**
   * Returns the title of the add form.
   *
   * Markup, not a string: @section is escaped already, and Drupal escapes a
   * plain string title once more ("Text &amp;amp; Socials").
   */
  public function addTitle(string $bundle): TranslatableMarkup {
    $type = $this->entityTypeManager()->getStorage('paragraphs_type')->load($bundle);
    return $this->t('Add @section', ['@section' => $type?->label() ?? $bundle]);
  }

  /**
   * Checks access to the section as a target, see TargetResolver::access().
   *
   * That is update access to the root and edit access to the section fields
   * (ParagraphTree::access(), through this module's target access hook), and
   * every other module's hook_headless_frontend_editing_target_access(), so a
   * section a site locks cannot be moved, deleted or added next to either.
   * Unknown sections are let through, so the route can answer 404.
   */
  public function access(AccountInterface $account, string $uuid): AccessResultInterface {
    $paragraph = $this->tree->load($uuid);
    if (!$paragraph) {
      return AccessResult::allowed()->setCacheMaxAge(0);
    }
    $result = $this->resolver->access(new Target($paragraph), $account);
    return $result->isAllowed() ? $result : AccessResult::forbidden()->addCacheableDependency($result);
  }

  /**
   * Checks access() plus delete access to the section itself.
   */
  public function deleteAccess(AccountInterface $account, string $uuid): AccessResultInterface {
    $paragraph = $this->tree->load($uuid);
    return $paragraph
      ? $this->access($account, $uuid)->andIf($paragraph->access('delete', $account, TRUE))
      : AccessResult::allowed()->setCacheMaxAge(0);
  }

  /**
   * Checks access to the page layout of an entity.
   *
   * Needs edit access to the entity as a whole (TargetResolver::access(),
   * which also refuses pending drafts) and at least one section field the
   * user may reorder. Targets that do not name a whole, non-composite entity
   * are let through, so the form can answer 404.
   */
  public function orderAccess(AccountInterface $account, string $langcode, string $target): AccessResultInterface {
    $resolved = SectionOrderForm::resolve($this->resolver, $target, $langcode);
    if (!$resolved) {
      return AccessResult::allowed()->setCacheMaxAge(0);
    }
    $result = $this->resolver->access($resolved, $account);
    return $result->isAllowed() && $this->tree->sectionFields($resolved->entity, $account) && $this->tree->mayKeepState($resolved->entity, $account)
      ? $result
      : AccessResult::forbidden()->addCacheableDependency($result)->setCacheMaxAge(0);
  }

  /**
   * Loads a section, or answers 404.
   */
  private function load(string $uuid): ParagraphInterface {
    return $this->tree->load($uuid) ?? throw new NotFoundHttpException();
  }

}
