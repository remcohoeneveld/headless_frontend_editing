<?php

declare(strict_types=1);

namespace Drupal\headless_frontend_editing_paragraphs\Controller;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\headless_frontend_editing\Hook\EntityFormHooks;
use Drupal\headless_frontend_editing_paragraphs\ParagraphTree;
use Drupal\paragraphs\ParagraphInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Adds and moves sections from the frontend preview.
 */
final class ParagraphController extends ControllerBase {

  /**
   * The routes rendered inside the sidebar iframe.
   */
  public const ROUTES = [
    'headless_frontend_editing_paragraphs.add',
    'headless_frontend_editing_paragraphs.add_form',
    'headless_frontend_editing_paragraphs.delete',
  ];

  public function __construct(
    private readonly ParagraphTree $tree,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get(ParagraphTree::class));
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
   * Returns the title of the add form.
   */
  public function addTitle(string $bundle): string {
    $type = $this->entityTypeManager()->getStorage('paragraphs_type')->load($bundle);
    return (string) $this->t('Add @section', ['@section' => $type?->label() ?? $bundle]);
  }

  /**
   * Swaps a section with its neighbor.
   */
  public function move(string $uuid, string $direction): JsonResponse {
    return new JsonResponse(['moved' => $this->tree->move($this->load($uuid), $direction)]);
  }

  /**
   * Checks update access to the root and edit access to the section fields.
   *
   * Sections are changed in their own language, which is the page's language
   * in that translation. Unknown sections are let through, so the route can
   * answer 404.
   */
  public function access(AccountInterface $account, string $uuid): AccessResultInterface {
    $paragraph = $this->tree->load($uuid);
    if (!$paragraph) {
      return AccessResult::allowed()->setCacheMaxAge(0);
    }
    $result = $this->tree->access($paragraph, $account);
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
   * Loads a section, or answers 404.
   */
  private function load(string $uuid): ParagraphInterface {
    return $this->tree->load($uuid) ?? throw new NotFoundHttpException();
  }

}
