<?php
declare(strict_types=1);

namespace MajiLink\Tests\Unit;

use MajiLink\Support\Jwt;
use PHPUnit\Framework\TestCase;

final class JwtTest extends TestCase
{
    private const KEY = 'test-key-not-used-anywhere-else';

    public function testClaimsSurviveARoundTrip(): void
    {
        $token = Jwt::encode(['sub' => 42, 'role' => 'customer', 'exp' => time() + 60], self::KEY);

        [$claims] = Jwt::decode($token, self::KEY);

        $this->assertSame(42, $claims['sub']);
        $this->assertSame('customer', $claims['role']);
    }

    public function testTokenWithAnotherKeyIsRejected(): void
    {
        $token = Jwt::encode(['sub' => 1, 'exp' => time() + 60], self::KEY);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Bad signature');
        Jwt::decode($token, 'a-different-key');
    }

    public function testTamperedPayloadIsRejected(): void
    {
        $token = Jwt::encode(['sub' => 1, 'exp' => time() + 60], self::KEY);
        [$header, , $signature] = explode('.', $token);
        $forged = rtrim(strtr(base64_encode(json_encode(['sub' => 2, 'exp' => time() + 60])), '+/', '-_'), '=');

        $this->expectException(\RuntimeException::class);
        Jwt::decode($header . '.' . $forged . '.' . $signature, self::KEY);
    }

    public function testExpiredTokenIsRejected(): void
    {
        $token = Jwt::encode(['sub' => 1, 'exp' => time() - 1], self::KEY);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Token expired');
        Jwt::decode($token, self::KEY);
    }

    public function testTokenWithoutExpiryIsRejected(): void
    {
        $token = Jwt::encode(['sub' => 1], self::KEY);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Token expired');
        Jwt::decode($token, self::KEY);
    }

    public function testMalformedTokenIsRejected(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Malformed token');
        Jwt::decode('not-a-jwt', self::KEY);
    }
}
