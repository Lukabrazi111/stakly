<?php

use App\Services\Provider\Exceptions\PermanentProviderError;
use App\Services\Provider\Exceptions\ProviderError;
use App\Services\Provider\Exceptions\RateLimitedError;
use App\Services\Provider\Exceptions\TransientProviderError;
use Carbon\CarbonImmutable;

test('all subclasses extend ProviderError', function () {
    expect(new TransientProviderError('x'))->toBeInstanceOf(ProviderError::class);
    expect(new RateLimitedError('x'))->toBeInstanceOf(ProviderError::class);
    expect(new PermanentProviderError('x'))->toBeInstanceOf(ProviderError::class);
});

test('RateLimitedError carries retryAt when supplied', function () {
    $retryAt = CarbonImmutable::parse('2026-06-06T12:34:56Z');

    $error = new RateLimitedError('rate-limited', retryAt: $retryAt);

    expect($error->retryAt())->toBe($retryAt);
});

test('RateLimitedError defaults retryAt to null', function () {
    expect((new RateLimitedError('rate-limited'))->retryAt())->toBeNull();
});

test('subclasses preserve message and previous exception', function () {
    $previous = new RuntimeException('boom');

    $transient = new TransientProviderError('transient up', previous: $previous);
    expect($transient->getMessage())->toBe('transient up');
    expect($transient->getPrevious())->toBe($previous);

    $permanent = new PermanentProviderError('permanent down', previous: $previous);
    expect($permanent->getMessage())->toBe('permanent down');
    expect($permanent->getPrevious())->toBe($previous);

    $rateLimited = new RateLimitedError('rate hit', previous: $previous);
    expect($rateLimited->getMessage())->toBe('rate hit');
    expect($rateLimited->getPrevious())->toBe($previous);
});
