<?php

namespace Modules\Core\Traits;

use InvalidArgumentException;
use Modules\Core\Helpers\MerchantContext;
use Modules\Core\Jobs\Middleware\EnsureMerchantContext;

trait MerchantAwareJob
{
    public int $merchantId;

    /**
     * Set the merchant ID explicitly or fallback to current MerchantContext.
     */
    public function withMerchant(?int $merchantId = null): self
    {
        $resolvedId = $merchantId ?? MerchantContext::id();

        if ($resolvedId === null) {
            throw new InvalidArgumentException('MerchantAwareJob requires a valid merchant ID or an active MerchantContext.');
        }

        $this->merchantId = $resolvedId;

        return $this;
    }

    /**
     * Initialize merchant ID during job construction.
     */
    public function initializeMerchantAwareJob(?int $merchantId = null): void
    {
        $this->withMerchant($merchantId);
    }

    /**
     * Get the queue middleware that should pass through.
     *
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [
            new EnsureMerchantContext($this->merchantId),
        ];
    }

    /**
     * Execute a callback wrapped in this job's merchant context.
     */
    public function runWithMerchantContext(callable $callback): mixed
    {
        $middleware = new EnsureMerchantContext($this->merchantId);

        return $middleware->handle($this, fn () => $callback());
    }
}

