<?php

declare(strict_types=1);

namespace SimoraTests;

use App\Contracts\Clock;
use App\Domain\Payments\PaymentException;
use App\Infrastructure\CurlPaystackTransport;
use App\Infrastructure\PaystackProvider;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SimoraTests\Support\PaystackTestTransport;

final class PaystackProviderTest extends TestCase
{
    private function provider(?PaystackTestTransport $http = null, string $mode = 'timestamped'): PaystackProvider
    {
        $clock = new class implements Clock {
            public function now(): DateTimeImmutable
            {
                return new DateTimeImmutable('2026-11-06T12:00:00Z');
            }
        };
        return new PaystackProvider(
            $http ?? new PaystackTestTransport(),
            $clock,
            'unit',
            ['whk_old' => 'whsec_old', 'whk_new' => 'whsec_new'],
            'sk_test_unit',
            $mode
        );
    }

    private function body(): string
    {
        return '{"event":"charge.success","data":{"id":18446744073709551615,"reference":"sim-test","domain":"test"}}';
    }

    private function header(string $body, int $offset = 0): string
    {
        $t = (new DateTimeImmutable('2026-11-06T12:00:00Z'))->getTimestamp() + $offset;
        return 't=' . $t . ',v1=whk_unknown:' . str_repeat('0', 128) . ',v1=whk_new:' . hash_hmac('sha512', $t
            . '.' . $body, 'whsec_new');
    }

    public function testModernRotationRawBytesAndUnsigned64BitIdentifiers(): void
    {
        $notice = $this->provider()->verifyWebhook(
            $this->body(),
            ['Paystack-Signature' => $this->header($this->body())]
        );
        self::assertSame('18446744073709551615', $notice->transactionId);
        self::assertSame('charge.success:18446744073709551615', $notice->eventId);
        self::assertSame('timestamped', $notice->verificationKind);
        $this->expectException(PaymentException::class);
        $this->provider()->verifyWebhook($this->body() . ' ', ['Paystack-Signature' => $this->header($this->body())]);
    }

    public static function invalidSignatureOffsets(): array
    {
        return [[-301], [301], [-3600], [3600]];
    }

    #[DataProvider('invalidSignatureOffsets')]
    public function testTimestampReplayWindow(int $offset): void
    {
        $this->expectException(PaymentException::class);
        $this->provider()->verifyWebhook($this->body(), ['Paystack-Signature' => $this->header(
            $this->body(),
            $offset
        )]);
    }

    public function testExplicitLegacyModeAndNoInvalidModernDowngrade(): void
    {
        $legacy = hash_hmac('sha512', $this->body(), 'sk_test_unit');
        self::assertSame('legacy', $this->provider(null, 'legacy')->verifyWebhook(
            $this->body(),
            ['x-paystack-signature' => $legacy]
        )->verificationKind);
        $this->expectException(PaymentException::class);
        $this->provider(null, 'legacy')->verifyWebhook($this->body(), ['x-paystack-signature' => $legacy,
            'Paystack-Signature' => 'invalid']);
    }

    public function testLegacyDisabledByDefaultAndLiveKeysRejected(): void
    {
        try {
            $this->provider()->verifyWebhook($this->body(), ['x-paystack-signature' => hash_hmac(
                'sha512',
                $this->body(),
                'sk_test_unit'
            )]);
            self::fail('Legacy accepted by default.');
        } catch (PaymentException $error) {
            self::assertSame(401, $error->status);
        }
        $this->expectException(PaymentException::class);
        new CurlPaystackTransport('sk_live_unit', 'live');
    }

    public function testUnsupportedEventsNeverBecomePaymentEvidence(): void
    {
        $body = '{"event":"transfer.success","data":{"reference":"sim-test"}}';
        $notice = $this->provider()->verifyWebhook($body, ['Paystack-Signature' => $this->header($body)]);
        self::assertNull($notice->merchantReference);
        self::assertNull($notice->transactionId);
    }

    public function testTransportRejectsTransfersRefundsAndProviderPlanBeforeNetwork(): void
    {
        $transport = new CurlPaystackTransport('sk_test_unit');
        foreach (['/transfer', '/transferrecipient', '/refund', '/subscription'] as $path) {
            try {
                $transport->request('POST', $path, []);
                self::fail('Unauthorized provider operation accepted.');
            } catch (PaymentException $error) {
                self::assertSame('provider_operation_disabled', $error->errorCode);
            }
        }
        $this->expectException(PaymentException::class);
        $transport->request('POST', '/transaction/initialize', ['plan' => 'unapproved']);
    }

    public function testVerifyUsesActualTransactionStatusAndExactIntegers(): void
    {
        $http = new PaystackTestTransport();
        $http->response = ['id' => '18446744073709551615', 'reference' => 'sim-test', 'domain' => 'test',
            'amount' => 3000000, 'currency' => 'NGN', 'status' => 'success', 'paid_at' => '2026-11-06T11:59:00.123Z',
            'customer' => ['email' => 'unit@example.invalid']];
        $fact = $this->provider($http)->lookup('sim-test');
        self::assertSame(3000000, $fact->amountMinor);
        self::assertSame('succeeded', $fact->status);
        self::assertSame('18446744073709551615', $fact->providerReference);
        self::assertSame('GET', $http->calls[0][0]);
        $http->response['amount'] = 3000000.0;
        $this->expectException(PaymentException::class);
        $this->provider($http)->lookup('sim-test');
    }

    public static function badCheckoutUrls(): array
    {
        return [['http://checkout.paystack.com/x'], ['https://checkout.paystack.com.evil.invalid/x'],
            ['https://user@checkout.paystack.com/x'], ['https://checkout.paystack.com:443/x'],
                ['https://checkout.paystack.com/x#fragment']];
    }

    #[DataProvider('badCheckoutUrls')]
    public function testHostedCheckoutRejectsUntrustedUrls(string $url): void
    {
        $http = new PaystackTestTransport();
        $http->response = ['reference' => 'sim-test', 'authorization_url' => $url];
        $this->expectException(PaymentException::class);
        $this->provider($http)->initialize('sim-test', 'unit@example.invalid', 200000, 'https://example.invalid/plans');
    }
}
