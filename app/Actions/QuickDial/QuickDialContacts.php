<?php

namespace App\Actions\QuickDial;

use Statum\NativeContacts\NativeContactsPlugin;

class QuickDialContacts
{
    public function __construct(
        private readonly NativeContactsPlugin $contactsPlugin,
    ) {}

    public function checkPermission(): bool
    {
        return $this->contactsPlugin->checkPermission();
    }

    public function requestPermission(): bool
    {
        return $this->contactsPlugin->requestPermission();
    }

    /**
     * @return array<int, array{name: string, phone: string, label: ?string}>
     */
    public function search(string $query, int $limit = 20): array
    {
        return $this->contactsPlugin->search($query, $limit);
    }
}
