<?php

use Design\Auth;
use PHPUnit\Framework\TestCase;

final class AuthTest extends TestCase
{
    public static function pass(int $id): ?string
    {
        return $id === 7 ? 'pass' : null;
    }

    private static function cookie(int $id, string $pass, string $ua): string
    {
        return $id . '.' . hash_hmac('md5', $pass . $ua, (string)$id);
    }

    public function test_valid_cookie_gives_customer(): void
    {
        $this->assertSame(7, Auth::customerId(self::cookie(7, 'pass', 'UA'), 'UA', [self::class, 'pass']));
    }

    public function test_other_user_agent_is_guest(): void
    {
        $this->assertSame(0, Auth::customerId(self::cookie(7, 'pass', 'UA'), 'Other', [self::class, 'pass']));
    }

    public function test_garbage_is_guest(): void
    {
        $this->assertSame(0, Auth::customerId('abc', 'UA', [self::class, 'pass']));
        $this->assertSame(0, Auth::customerId(null, 'UA', [self::class, 'pass']));
        $this->assertSame(0, Auth::customerId('', 'UA', [self::class, 'pass']));
    }

    public function test_unknown_customer_is_guest(): void
    {
        $this->assertSame(0, Auth::customerId(self::cookie(8, 'pass', 'UA'), 'UA', [self::class, 'pass']));
    }

    public function test_empty_password_is_guest(): void   // Final review: кука для клієнта з порожнім паролем підробляється без секрету
    {
        $forged = '7.' . hash_hmac('md5', '' . 'UA', '7');
        $this->assertSame(0, Auth::customerId($forged, 'UA', static fn(int $id): ?string => ''));
    }
}
