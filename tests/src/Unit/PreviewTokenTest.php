<?php

declare(strict_types=1);

namespace Drupal\Tests\headless_frontend_editing\Unit;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\PrivateKey;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Site\Settings;
use Drupal\headless_frontend_editing\PreviewToken;
use Drupal\Tests\UnitTestCase;

/**
 * Tests the signed preview token.
 *
 * @group headless_frontend_editing
 */
class PreviewTokenTest extends UnitTestCase {

  /**
   * Current request time reported by the mocked clock.
   */
  protected int $now = 1000000;

  /**
   * Builds a token service on the mocked clock.
   */
  protected function token(): PreviewToken {
    new Settings(['hash_salt' => 'salt']);
    $key = $this->createMock(PrivateKey::class);
    $key->method('get')->willReturn('private-key');
    $time = $this->createMock(TimeInterface::class);
    $time->method('getRequestTime')->willReturnCallback(fn () => $this->now);
    return new PreviewToken($key, $time);
  }

  /**
   * Builds an account with the given uid.
   */
  protected function account(int $uid): AccountInterface {
    $account = $this->createMock(AccountInterface::class);
    $account->method('id')->willReturn($uid);
    return $account;
  }

  /**
   * A fresh token validates to its uid.
   */
  public function testRoundTrip(): void {
    $token = $this->token();
    $this->assertSame(7, $token->validate($token->create($this->account(7))));
  }

  /**
   * A token older than the TTL is rejected.
   */
  public function testExpired(): void {
    $token = $this->token();
    $value = $token->create($this->account(7));
    $this->now += PreviewToken::TTL;
    $this->assertSame(7, $token->validate($value));
    $this->now += 1;
    $this->assertNull($token->validate($value));
  }

  /**
   * Changing the uid invalidates the signature.
   */
  public function testTampered(): void {
    $token = $this->token();
    $parts = explode('.', $token->create($this->account(7)));
    $parts[0] = '8';
    $this->assertNull($token->validate(implode('.', $parts)));
  }

  /**
   * Malformed tokens are rejected.
   *
   * @dataProvider malformedProvider
   */
  public function testMalformed(string $value): void {
    $this->assertNull($this->token()->validate($value));
  }

  /**
   * Malformed token values.
   *
   * @return list<array{string}>
   *   Argument lists.
   */
  public static function malformedProvider(): array {
    return array_map(fn ($v) => [$v], ['', 'a.b.c', '7.123', '7.x.sig', '7.123.sig.extra']);
  }

}
