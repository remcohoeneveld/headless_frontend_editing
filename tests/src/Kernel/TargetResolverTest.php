<?php

declare(strict_types=1);

namespace Drupal\Tests\headless_frontend_editing\Kernel;

use Drupal\headless_frontend_editing\TargetResolver;
use Drupal\KernelTests\KernelTestBase;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests resolving target strings.
 */
#[Group('headless_frontend_editing')]
#[RunTestsInSeparateProcesses]
final class TargetResolverTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system', 'user', 'field', 'filter', 'text', 'node', 'language', 'content_translation', 'headless_frontend_editing',
  ];

  /**
   * An article with a Dutch translation.
   */
  private Node $node;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installConfig(['node']);
    $this->installSchema('node', ['node_access']);
    ConfigurableLanguage::createFromLangcode('nl')->save();
    NodeType::create(['type' => 'article', 'name' => 'Article'])->save();
    $this->node = Node::create(['type' => 'article', 'title' => 'Hello', 'langcode' => 'en']);
    $this->node->save();
  }

  /**
   * Returns the resolver under test.
   */
  private function resolver(): TargetResolver {
    return $this->container->get(TargetResolver::class);
  }

  /**
   * Tests entity and field targets.
   */
  public function testResolves(): void {
    $uuid = $this->node->uuid();
    $target = $this->resolver()->resolve("node:$uuid", 'en');
    $this->assertSame($this->node->id(), $target->entity->id());
    $this->assertNull($target->fieldName);
    $this->assertSame("node:$uuid", $target->id());
    $target = $this->resolver()->resolve("node:$uuid:title", 'en');
    $this->assertSame('title', $target->fieldName);
    $this->assertSame("node:$uuid:title", $target->id());
    $this->assertNull($this->resolver()->resolve("node:$uuid:nope", 'en'));
    $this->assertNull($this->resolver()->resolve('node:00000000-0000-0000-0000-000000000000', 'en'));
  }

  /**
   * Tests malformed targets.
   */
  #[DataProvider('malformed')]
  public function testMalformed(string $template): void {
    $this->assertNull($this->resolver()->resolve(str_replace('UUID', $this->node->uuid(), $template), 'en'));
  }

  /**
   * Data provider.
   *
   * @return array<int, array<int, string>>
   *   Target templates.
   */
  public static function malformed(): array {
    return [['node:123'], ['NODE:UUID'], ['node:UUID:x:y'], ['config_test:UUID']];
  }

  /**
   * Tests that a missing translation does not resolve.
   */
  public function testMissingTranslation(): void {
    $this->assertNull($this->resolver()->resolve('node:' . $this->node->uuid(), 'nl'));
  }

  /**
   * Tests that the requested translation is returned.
   */
  public function testTranslation(): void {
    $uuid = $this->node->uuid();
    $this->node->addTranslation('nl', ['title' => 'Hallo'])->save();
    $dutch = $this->resolver()->resolve("node:$uuid", 'nl');
    $this->assertSame('nl', $dutch?->entity->language()->getId());
    $this->assertSame('en', $this->resolver()->resolve("node:$uuid", 'en')?->entity->language()->getId());
  }

}
