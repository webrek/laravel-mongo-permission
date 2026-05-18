<?php

return [
    'models' => [
        'role' => Webrek\MongoPermission\Models\Role::class,
        'permission' => Webrek\MongoPermission\Models\Permission::class,
    ],

    'collection_names' => [
        'roles' => 'roles',
        'permissions' => 'permissions',
    ],

    'guard_names' => ['web', 'api'],
    'default_guard' => 'web',

    'teams' => true,

    'team_resolver' => function () {
        return null;
    },

    'strict_team_isolation' => false,

    'enable_wildcard_permission' => false,
    'wildcard_separator' => '.',

    'throw_on_missing_permission' => true,
    'handle_unauthorized' => true,

    'cache' => [
        'expiration_time' => null,
        'key' => 'mongo-permission',
        'store' => 'default',
    ],
];
