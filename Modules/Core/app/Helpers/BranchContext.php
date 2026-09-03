<?php

namespace Modules\Core\Helpers;

use Modules\Operational\Models\Branch;

class BranchContext
{
    private static ?Branch $current = null;

    public static function set(Branch $branch): void
    {
        static::$current = $branch;
    }

    public static function get(): ?Branch
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

    public static function getOrFail(): Branch
    {
        if (static::$current === null) {
            throw new \RuntimeException(
                'No branch context has been set.'
            );
        }

        return static::$current;
    }
}