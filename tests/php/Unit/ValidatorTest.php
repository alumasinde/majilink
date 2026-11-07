<?php
declare(strict_types=1);

namespace MajiLink\Tests\Unit;

use MajiLink\Http\HttpException;
use MajiLink\Support\Validator;
use PHPUnit\Framework\TestCase;

final class ValidatorTest extends TestCase
{
    /** @param array<string,string> $errors */
    private function assertFieldError(string $field, array $data, array $rules, ?string $contains = null): void
    {
        try {
            Validator::validate($data, $rules);
        } catch (HttpException $e) {
            $this->assertSame(422, $e->status);
            $this->assertArrayHasKey($field, $e->fields, 'Expected an error on ' . $field);
            if ($contains !== null) {
                $this->assertStringContainsString($contains, $e->fields[$field]);
            }

            return;
        }

        $this->fail('Validation should have failed for ' . $field);
    }

    public function testMissingRequiredFieldIsReported(): void
    {
        $this->assertFieldError('phone', [], ['phone' => 'required|phone'], 'required');
    }

    public function testValidPayloadIsReturned(): void
    {
        $clean = Validator::validate(
            ['alias' => 'Home', 'lat' => '-1.28'],
            ['alias' => 'required|string|max:80', 'lat' => 'required|lat']
        );

        $this->assertSame(['alias' => 'Home', 'lat' => '-1.28'], $clean);
    }

    public function testNullOptionalFieldIsDropped(): void
    {
        $clean = Validator::validate(['note' => null], ['note' => 'nullable|string']);

        $this->assertArrayNotHasKey('note', $clean);
    }

    public function testPasswordMustBeAtLeastEightCharacters(): void
    {
        $this->assertFieldError('password', ['password' => 'Maji12'], ['password' => 'required|password'], '8 characters');
    }

    public function testCommonPasswordIsRejected(): void
    {
        $this->assertFieldError('password', ['password' => 'password'], ['password' => 'required|password'], 'too common');
    }

    public function testStrongPasswordPasses(): void
    {
        $clean = Validator::validate(['password' => 'Maji12345!'], ['password' => 'required|password']);

        $this->assertSame('Maji12345!', $clean['password']);
    }

    public function testPinMustBeSixDigits(): void
    {
        $this->assertFieldError('pin', ['pin' => '12345'], ['pin' => 'required|pin'], '6 digits');
        $this->assertFieldError('pin', ['pin' => '12a456'], ['pin' => 'required|pin'], '6 digits');
    }

    public function testTrivialPinsAreRejected(): void
    {
        foreach (['123456', '654321', '111111', '000000'] as $pin) {
            $this->assertFieldError('pin', ['pin' => $pin], ['pin' => 'required|pin'], 'too easy');
        }
    }

    public function testNonSequentialPinPasses(): void
    {
        $clean = Validator::validate(['pin' => '112233'], ['pin' => 'required|pin']);

        $this->assertSame('112233', $clean['pin']);
    }

    public function testValidKenyanPhoneFormatsPass(): void
    {
        foreach (['0712345678', '+254712345678', '0110000111'] as $phone) {
            $clean = Validator::validate(['phone' => $phone], ['phone' => 'required|phone']);
            $this->assertSame($phone, $clean['phone']);
        }
    }

    public function testInvalidPhoneIsRejected(): void
    {
        $this->assertFieldError('phone', ['phone' => '0812345678'], ['phone' => 'required|phone'], 'Kenyan');
        $this->assertFieldError('phone', ['phone' => '07123456789'], ['phone' => 'required|phone'], 'Kenyan');
    }

    public function testNumericBoundsAreAppliedToNumbers(): void
    {
        $this->assertFieldError('quantity', ['quantity' => 0], ['quantity' => 'required|integer|min:1'], 'at least 1');
        $this->assertFieldError('quantity', ['quantity' => 21], ['quantity' => 'required|integer|max:20'], 'not be more than 20');
    }

    public function testStringLengthBoundsUseCharacters(): void
    {
        $this->assertFieldError('alias', ['alias' => 'ab'], ['alias' => 'required|min:3'], 'at least 3');
        $this->assertFieldError('alias', ['alias' => str_repeat('a', 81)], ['alias' => 'required|max:80'], 'not be more than 80');
    }

    public function testEnumeratedValueMustBeOneOfTheOptions(): void
    {
        $this->assertFieldError('payment_method', ['payment_method' => 'Card'], ['payment_method' => 'in:Cash,M-Pesa'], 'Cash');
        $clean = Validator::validate(['payment_method' => 'M-Pesa'], ['payment_method' => 'in:Cash,M-Pesa']);
        $this->assertSame('M-Pesa', $clean['payment_method']);
    }

    public function testTimeFormatIsChecked(): void
    {
        $this->assertFieldError('opens_at', ['opens_at' => '24:00'], ['opens_at' => 'time'], '08:30');
        $clean = Validator::validate(['opens_at' => '08:30'], ['opens_at' => 'time']);
        $this->assertSame('08:30', $clean['opens_at']);
    }

    public function testCoordinatesAreRangeChecked(): void
    {
        $this->assertFieldError('lat', ['lat' => '91'], ['lat' => 'required|lat'], 'latitude');
        $this->assertFieldError('lng', ['lng' => '181'], ['lng' => 'required|lng'], 'longitude');
    }

    public function testEmailFormatIsChecked(): void
    {
        $this->assertFieldError('email', ['email' => 'not-an-email'], ['email' => 'required|email'], 'valid email');
    }

    public function testEloquentStyleTypeRules(): void
    {
        $this->assertFieldError('quantity', ['quantity' => 'two'], ['quantity' => 'required|integer'], 'whole number');
        $this->assertFieldError('amount', ['amount' => 'many'], ['amount' => 'required|numeric'], 'number');
    }
}
