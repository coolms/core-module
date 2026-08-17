<?php

declare(strict_types=1);

namespace CoolMS\CoreModule\ApiManifest;

final readonly class AuthApiManifest
{
    public function __construct(
        public string $login,
        public string $logout,
        public string $refresh,
        public string $register,
        public string $me,
        public string $usersApi = '',
        public string $groupsApi = '',
        public string $preferencesUrl = '',
        public ?string $groupFormId = null,
        public ?string $userFormId = null,
    ) {
    }
}
