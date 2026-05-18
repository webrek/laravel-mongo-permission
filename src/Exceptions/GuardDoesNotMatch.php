<?php

namespace Webrek\MongoPermission\Exceptions;

class GuardDoesNotMatch extends MongoPermissionException
{
    public static function create(string $given, string $expected): self
    {
        return new self("The given guard '{$given}' does not match the expected guard '{$expected}'.");
    }
}
