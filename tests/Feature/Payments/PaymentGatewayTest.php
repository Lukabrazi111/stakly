<?php

use App\Models\User;
use App\Services\Payments\CryptomusGateway;
use App\Services\Payments\Dto\GatewayEventType;
use App\Services\Payments\Dto\GatewayPayoutStatus;
use App\Services\Payments\MockGateway;
use App\Services\Payments\NowPaymentsGateway;
use App\Services\Payments\PaymentGateway;
use Illuminate\Http\Request;

/**
 * Resolve the container binding under a given driver. Forgets the cached
 * singleton so the config change is re-read — the swap surface is the binding,
 * not the instance.
 */
function resolveGatewayWithDriver(string $driver): PaymentGateway
{
    config()->set('services.payments.driver', $driver);
    app()->forgetInstance(PaymentGateway::class);

    return app(PaymentGateway::class);
}

function mockWebhookRequest(array $payload, ?string $signature = null): Request
{
    $request = Request::create('/webhooks/payments', 'POST', content: json_encode($payload));
    $request->headers->set('Content-Type', 'application/json');

    if ($signature !== null) {
        $request->headers->set('X-Mock-Signature', $signature);
    }

    return $request;
}

describe('driver binding', function () {
    it('resolves the correct gateway for each configured driver (one-config-flip swap)', function (string $driver, string $class) {
        expect(resolveGatewayWithDriver($driver))->toBeInstanceOf($class);
    })->with([
        'mock' => ['mock', MockGateway::class],
        'nowpayments' => ['nowpayments', NowPaymentsGateway::class],
        'cryptomus' => ['cryptomus', CryptomusGateway::class],
    ]);

    it('throws on an unknown driver', function () {
        expect(fn () => resolveGatewayWithDriver('paypal'))
            ->toThrow(InvalidArgumentException::class);
    });

    it('defaults to the mock driver', function () {
        expect(config('services.payments.driver'))->toBe('mock');
    });
});

describe('unwired provider stubs', function () {
    it('throws on every method until M9 wiring lands', function () {
        $gateway = new NowPaymentsGateway;

        expect(fn () => $gateway->createPayout('10', 'Taddr', 'ref'))
            ->toThrow(RuntimeException::class, 'not wired');
        expect(fn () => $gateway->verifyWebhookSignature(mockWebhookRequest([])))
            ->toThrow(RuntimeException::class, 'not wired');
    });
});

describe('MockGateway', function () {
    beforeEach(function () {
        $this->gateway = new MockGateway;
    });

    it('returns the user\'s existing deposit address', function () {
        $user = User::factory()->create(['tron_address' => 'TMockAddress123']);

        expect($this->gateway->ensureDepositAccount($user)->address)->toBe('TMockAddress123');
    });

    it('produces a deterministic, completed payout keyed on the reference', function () {
        config()->set('services.payments.mock.network_fee', '1.500000');

        $a = $this->gateway->createPayout('100', 'Tdest', 'wd:7');
        $b = $this->gateway->createPayout('100', 'Tdest', 'wd:7');

        expect($a->providerPayoutId)->toBe('mock-payout:wd:7')
            ->and($a->providerPayoutId)->toBe($b->providerPayoutId)
            ->and($a->txHash)->toBe($b->txHash)
            ->and($a->txHash)->toStartWith('MOCK-')
            ->and($a->status)->toBe(GatewayPayoutStatus::Completed)
            ->and($a->networkFee)->toBe('1.500000');
    });

    it('estimates the configured network fee', function () {
        config()->set('services.payments.mock.network_fee', '1.500000');

        $estimate = $this->gateway->estimatePayoutFee('100', 'Tdest');

        expect($estimate->networkFee)->toBe('1.500000')
            ->and($estimate->providerFee)->toBe('0')
            ->and($estimate->total())->toBe('1.500000');
    });

    it('verifies a valid HMAC webhook signature and rejects a bad one', function () {
        config()->set('services.payments.mock.webhook_secret', 'shhh');
        $payload = ['event' => 'deposit', 'amount' => '99.5', 'address' => 'Tdest', 'tx_hash' => 'abc'];
        $body = json_encode($payload);
        $goodSig = hash_hmac('sha256', $body, 'shhh');

        expect($this->gateway->verifyWebhookSignature(mockWebhookRequest($payload, $goodSig)))->toBeTrue()
            ->and($this->gateway->verifyWebhookSignature(mockWebhookRequest($payload, 'wrong')))->toBeFalse();
    });

    it('rejects any signature when no secret is configured', function () {
        config()->set('services.payments.mock.webhook_secret', null);
        $payload = ['event' => 'deposit'];

        expect($this->gateway->verifyWebhookSignature(mockWebhookRequest($payload, 'anything')))->toBeFalse();
    });

    it('normalizes a deposit webhook into a canonical event', function () {
        $event = $this->gateway->parseWebhookEvent(mockWebhookRequest([
            'event' => 'deposit',
            'amount' => '99.500000',
            'address' => 'Tdest',
            'tx_hash' => 'deadbeef',
        ]));

        expect($event->type)->toBe(GatewayEventType::DepositCredited)
            ->and($event->amount)->toBe('99.500000')
            ->and($event->address)->toBe('Tdest')
            ->and($event->txHash)->toBe('deadbeef');
    });

    it('normalizes a payout webhook into a canonical event', function () {
        $event = $this->gateway->parseWebhookEvent(mockWebhookRequest([
            'event' => 'payout',
            'payout_id' => 'mock-payout:wd:7',
            'status' => 'completed',
            'tx_hash' => 'MOCK-XYZ',
        ]));

        expect($event->type)->toBe(GatewayEventType::PayoutUpdated)
            ->and($event->providerPayoutId)->toBe('mock-payout:wd:7')
            ->and($event->payoutStatus)->toBe(GatewayPayoutStatus::Completed)
            ->and($event->txHash)->toBe('MOCK-XYZ');
    });

    it('throws on an unrecognized webhook event', function () {
        expect(fn () => $this->gateway->parseWebhookEvent(mockWebhookRequest(['event' => 'nonsense'])))
            ->toThrow(InvalidArgumentException::class);
    });

    it('fails fast when a deposit webhook is missing a required field', function (array $payload) {
        expect(fn () => $this->gateway->parseWebhookEvent(mockWebhookRequest($payload)))
            ->toThrow(InvalidArgumentException::class);
    })->with([
        'no amount' => [['event' => 'deposit', 'address' => 'Tdest', 'tx_hash' => 'abc']],
        'no address' => [['event' => 'deposit', 'amount' => '99', 'tx_hash' => 'abc']],
        'no tx_hash' => [['event' => 'deposit', 'amount' => '99', 'address' => 'Tdest']],
    ]);

    it('fails fast on a payout webhook with an unknown status (not a raw ValueError)', function () {
        expect(fn () => $this->gateway->parseWebhookEvent(mockWebhookRequest([
            'event' => 'payout',
            'payout_id' => 'mock-payout:wd:7',
            'status' => 'exploded',
        ])))->toThrow(InvalidArgumentException::class);
    });

    it('synthesizes and persists a deposit address when the user has none (idempotent)', function () {
        $user = User::factory()->create(['tron_address' => null]);

        $first = $this->gateway->ensureDepositAccount($user);
        $second = $this->gateway->ensureDepositAccount($user->fresh());

        expect($first->address)->toStartWith('T')
            ->and($first->address)->toBe($second->address)
            ->and($user->fresh()->tron_address)->toBe($first->address);
    });
});
