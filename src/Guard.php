<?php

namespace Webrek\MongoPermission;

class Guard
{
    public static function resolveForModel(object $model, ?string $explicit = null): string
    {
        if ($explicit !== null && $explicit !== '') {
            return $explicit;
        }

        if (property_exists($model, 'guard_name')) {
            $ref = new \ReflectionProperty($model, 'guard_name');
            $ref->setAccessible(true);
            if ($ref->isInitialized($model)) {
                $value = $ref->getValue($model);
                if (! empty($value)) {
                    return $value;
                }
            }
        }

        $authDefault = config('auth.defaults.guard');
        if (! empty($authDefault)) {
            return $authDefault;
        }

        return config('permission.default_guard', 'web');
    }
}
