<?php

namespace app\common\cache;

class GeoManuscriptTokenCache extends BaseCache
{
    private const PREFIX = 'geo_manuscript_open_token_';

    public function setPlatformAccessToken(string $token, int $ttl): bool
    {
        return $this->storeToken(self::PREFIX . 'platform', $token, $ttl);
    }

    public function getAccessToken(int $tenantId): array
    {
        $value = $this->get(self::PREFIX . max(0, $tenantId), []);
        return is_array($value) ? $value : [];
    }

    public function setAccessToken(int $tenantId, string $token, int $ttl): bool
    {
        return $this->storeToken(self::PREFIX . max(0, $tenantId), $token, $ttl);
    }

    private function storeToken(string $key, string $token, int $ttl): bool
    {
        return $this->set($key, [
            'token' => trim($token),
            'issued_at' => time(),
        ], $ttl);
    }

    public function deleteAccessToken(int $tenantId): bool
    {
        return $this->delete(self::PREFIX . max(0, $tenantId));
    }
}