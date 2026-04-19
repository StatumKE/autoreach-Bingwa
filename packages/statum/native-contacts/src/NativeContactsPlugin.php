<?php

namespace Statum\NativeContacts;

class NativeContactsPlugin
{
    public function checkPermission(): bool
    {
        if (! function_exists('nativephp_call')) {
            return false;
        }

        $result = nativephp_call('Contacts.CheckPermission', '{}');

        if (! is_string($result) || $result === '') {
            return false;
        }

        $decoded = json_decode($result, true);

        return (bool) ($decoded['data']['granted'] ?? false);
    }

    public function requestPermission(): bool
    {
        if (! function_exists('nativephp_call')) {
            return false;
        }

        $result = nativephp_call('Contacts.RequestPermission', '{}');

        return is_string($result) && $result !== '';
    }

    /**
     * @return array<int, array{name: string, phone: string, label: ?string}>
     */
    public function search(string $query = '', int $limit = 20): array
    {
        if (! function_exists('nativephp_call')) {
            return [];
        }

        $result = nativephp_call('Contacts.Search', json_encode([
            'query' => $query,
            'limit' => $limit,
        ]));

        if (! is_string($result) || $result === '') {
            return [];
        }

        $decoded = json_decode($result, true);

        if (! is_array($decoded)) {
            return [];
        }

        return array_values(array_filter($decoded['data']['contacts'] ?? [], static fn ($contact): bool => is_array($contact)));
    }
}
