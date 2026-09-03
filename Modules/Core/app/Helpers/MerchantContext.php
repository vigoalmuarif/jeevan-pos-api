<?php

namespace Modules\Core\Helpers;

use Modules\Merchant\Models\Merchant;

class MerchantContext
{
    private static ?Merchant $current = null;

    public static function set(Merchant $merchant): void
    {
        static::$current = $merchant;
    }

    public static function get(): ?Merchant
    {
        return static::$current;
    }

    public static function id(): ?int
    {
        return static::$current?->id;
    }

    public static function check(): bool
    {
        return static::$current !== null;
    }

    public static function clear(): void
    {
        static::$current = null;
    }

    public static function getOrFail(): Merchant
    {
        if (static::$current === null) {
            throw new \RuntimeException('No Merchant context has been set.');
        }

        return static::$current;
    }
}