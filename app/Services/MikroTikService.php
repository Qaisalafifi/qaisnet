<?php

namespace App\Services;

use App\Models\Network;
use Exception;
use Illuminate\Support\Facades\Log;

class MikroTikService
{
    private $api;
    private $network;
    private $connectTimeoutSeconds = 5;
    private $readTimeoutSeconds = 10;
    private $lastError = null;

    private function createClient(Network $network): RouterosAPI
    {
        $api = new RouterosAPI();
        $api->port = $network->api_port ?? 8728;
        $api->ssl = ((int) $api->port === 8729);
        $api->timeout = max($this->connectTimeoutSeconds, $this->readTimeoutSeconds);
        $api->attempts = 1;
        $api->delay = 0;

        return $api;
    }

    /**
     * Connect to MikroTik Router
     */
    public function connect(Network $network): bool
    {
        try {
            $this->lastError = null;
            $this->network = $network;

            $password = $network->decrypted_password;
            $this->api = $this->createClient($network);

            $connected = $this->api->connect($network->ip_address, $network->mikrotik_user, $password);
            if (!$connected) {
                $error = trim((string) $this->api->error_str);
                if ($error !== '') {
                    throw new Exception("Cannot connect to MikroTik: {$error} ({$this->api->error_no})");
                }
                throw new Exception('Authentication failed or API service not reachable');
            }

            return true;
        } catch (Exception $e) {
            $this->disconnect();
            $this->lastError = $e->getMessage();
            Log::error('MikroTik Connection Error', [
                'network_id' => $network->id,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Test connection to MikroTik
     */
    public function testConnection(Network $network): array
    {
        try {
            if ($this->connect($network)) {
                $this->disconnect();
                return [
                    'success' => true,
                    'message' => 'تم الاتصال بنجاح',
                ];
            }

            return [
                'success' => false,
                'message' => $this->lastError ?? 'فشل الاتصال',
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }

    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    /**
     * Get all profiles from MikroTik (for Hotspot)
     */
    public function getProfiles(Network $network): array
    {
        try {
            if (!$this->connect($network)) {
                throw new Exception('Cannot connect to MikroTik');
            }

            // Prefer User Manager profiles (matches public/profile.php)
            $profiles = $this->fetchUserManagerProfiles();
            if (empty($profiles)) {
                // Fallback to Hotspot profiles
                $profiles = $this->fetchHotspotProfiles();
            }

            $this->disconnect();

            return array_values(array_filter($profiles, function ($profile) {
                return !empty($profile['id']) || !empty($profile['name']);
            }));
        } catch (Exception $e) {
            Log::error('MikroTik Get Profiles Error', [
                'network_id' => $network->id,
                'error' => $e->getMessage(),
            ]);
            $this->disconnect();
            return [];
        }
    }

    /**
     * Create user in MikroTik Hotspot
     */
    public function createUser(Network $network, string $username, string $password, string $profile, int $validityDays = 30): bool
    {
        try {
            if (!$this->connect($network)) {
                throw new Exception('Cannot connect to MikroTik');
            }

            $result = $this->createUserOnSession($network, $username, $password, $profile, $validityDays);
            $this->disconnect();
            return $result;
        } catch (Exception $e) {
            Log::error('MikroTik Create User Error', [
                'network_id' => $network->id,
                'username' => $username,
                'error' => $e->getMessage(),
            ]);
            $this->disconnect();
            return false;
        }
    }

    public function createUserOnSession(Network $network, string $username, string $password, string $profile, int $validityDays = 30): bool
    {
        try {
            if (!$this->api || !$this->api->connected) {
                throw new Exception('Cannot connect to MikroTik');
            }

            // Try User Manager flow first (matches legacy print.php)
            $userManagerCreated = $this->createUserManagerUser(
                $network,
                $username,
                $password,
                $profile
            );

            if (!$userManagerCreated) {
                // Fallback to Hotspot if User Manager is unavailable
                $this->createHotspotUser($username, $password, $profile, $validityDays);
            }

            return true;
        } catch (Exception $e) {
            Log::error('MikroTik Create User Error', [
                'network_id' => $network->id,
                'username' => $username,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Disable user in MikroTik
     */
    public function disableUser(Network $network, string $username): bool
    {
        try {
            if (!$this->connect($network)) {
                throw new Exception('Cannot connect to MikroTik');
            }

            $userId = $this->findHotspotUserId($username);
            if (!$userId) {
                $this->disconnect();
                return true; // already missing
            }

            $response = $this->api->comm('/ip/hotspot/user/disable', [
                '.id' => $userId,
            ]);

            $this->ensureNoTrap($response, 'Failed to disable user');
            $this->disconnect();

            return true;
        } catch (Exception $e) {
            Log::error('MikroTik Disable User Error', [
                'network_id' => $network->id,
                'username' => $username,
                'error' => $e->getMessage(),
            ]);
            $this->disconnect();
            return false;
        }
    }

    /**
     * Remove user from MikroTik (User Manager first, fallback to Hotspot)
     */
    public function removeUser(Network $network, string $username): bool
    {
        try {
            if (!$this->connect($network)) {
                throw new Exception('Cannot connect to MikroTik');
            }

            // Try User Manager
            $userManagerResult = $this->removeUserManagerUser($network, $username);
            if ($userManagerResult === true) {
                $this->disconnect();
                return true;
            }

            // Fallback to Hotspot
            $hotspotResult = $this->removeHotspotUser($username);
            $this->disconnect();

            return $hotspotResult;
        } catch (Exception $e) {
            Log::error('MikroTik Remove User Error', [
                'network_id' => $network->id,
                'username' => $username,
                'error' => $e->getMessage(),
            ]);
            $this->disconnect();
            return false;
        }
    }

    private function ensureNoTrap($response, string $fallbackMessage): void
    {
        if (is_array($response)) {
            if (isset($response['!trap'][0]['message'])) {
                throw new Exception($response['!trap'][0]['message']);
            }
            if (isset($response['!trap'])) {
                throw new Exception($fallbackMessage);
            }
            if (isset($response['!fatal'][0]['message'])) {
                throw new Exception($response['!fatal'][0]['message']);
            }
            if (isset($response['!fatal'])) {
                throw new Exception($fallbackMessage);
            }
        }
    }

    private function extractTrapMessage($response): ?string
    {
        if (!is_array($response)) {
            return null;
        }
        if (isset($response['!trap'][0]['message'])) {
            return $response['!trap'][0]['message'];
        }
        if (isset($response['!fatal'][0]['message'])) {
            return $response['!fatal'][0]['message'];
        }
        return null;
    }

    private function createUserManagerUser(Network $network, string $username, string $password, string $profile): bool
    {
        $params = [
            'customer' => $network->mikrotik_user,
            'username' => $username,
        ];
        if ($password !== '') {
            $params['password'] = $password;
        }

        $response = $this->api->comm('/tool/user-manager/user/add', $params);

        $trapMessage = $this->extractTrapMessage($response);
        if ($trapMessage !== null) {
            if (stripos($trapMessage, 'no such command') !== false) {
                return false;
            }
            throw new Exception($trapMessage);
        }

        $response = $this->api->comm('/tool/user-manager/user/create-and-activate-profile', [
            'customer' => $network->mikrotik_user,
            'profile' => $profile,
            'numbers' => $username,
        ]);
        $this->ensureNoTrap($response, 'Failed to create and activate profile');

        return true;
    }

    private function createHotspotUser(string $username, string $password, string $profile, int $validityDays): void
    {
        $params = [
            'name' => $username,
            'profile' => $profile,
            'limit-uptime' => ($validityDays * 24) . 'h',
        ];
        if ($password !== '') {
            $params['password'] = $password;
        }

        $response = $this->api->comm('/ip/hotspot/user/add', $params);

        $this->ensureNoTrap($response, 'Failed to create user');
    }

    private function removeUserManagerUser(Network $network, string $username): ?bool
    {
        $response = $this->api->comm('/tool/user-manager/user/print', [
            '?username' => $username,
            '?customer' => $network->mikrotik_user,
        ]);

        $trapMessage = $this->extractTrapMessage($response);
        if ($trapMessage !== null) {
            if (stripos($trapMessage, 'no such command') !== false) {
                return null; // user manager not available
            }
            throw new Exception($trapMessage);
        }

        $userId = null;
        if (is_array($response)) {
            foreach ($response as $item) {
                if (is_array($item) && isset($item['.id'])) {
                    $userId = $item['.id'];
                    break;
                }
            }
        }

        if (!$userId) {
            return false; // not found
        }

        $removeResponse = $this->api->comm('/tool/user-manager/user/remove', [
            '.id' => $userId,
        ]);
        $this->ensureNoTrap($removeResponse, 'Failed to remove user manager user');

        return true;
    }

    private function removeHotspotUser(string $username): bool
    {
        $userId = $this->findHotspotUserId($username);
        if (!$userId) {
            return true; // already missing
        }

        $response = $this->api->comm('/ip/hotspot/user/remove', [
            '.id' => $userId,
        ]);
        $this->ensureNoTrap($response, 'Failed to remove hotspot user');

        return true;
    }

    private function findHotspotUserId(string $username): ?string
    {
        $users = $this->api->comm('/ip/hotspot/user/print', [
            '?name' => $username,
        ]);

        if (is_array($users)) {
            foreach ($users as $item) {
                if (is_array($item) && isset($item['.id'])) {
                    return $item['.id'];
                }
            }
        }

        return null;
    }

    private function fetchUserManagerProfiles(): array
    {
        $response = $this->api->comm('/tool/user-manager/profile/print', []);
        if ($this->isTrapResponse($response)) {
            return [];
        }

        $profiles = [];
        if (is_array($response)) {
            foreach ($response as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $profiles[] = [
                    'id' => $item['.id'] ?? null,
                    'name' => $item['name'] ?? null,
                    'validity' => $item['validity'] ?? null,
                    'transfer_limit' => $item['transfer-limit'] ?? null,
                    'price' => $item['price'] ?? null,
                    'rate_limit_rx_current' => $item['rate-limit-rx-current'] ?? null,
                    'rate_limit_tx_current' => $item['rate-limit-tx-current'] ?? null,
                    'source' => 'user-manager',
                ];
            }
        }

        return $profiles;
    }

    private function fetchHotspotProfiles(): array
    {
        $response = $this->api->comm('/ip/hotspot/user/profile/print', []);
        if ($this->isTrapResponse($response)) {
            return [];
        }

        $profiles = [];
        if (is_array($response)) {
            foreach ($response as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $profiles[] = [
                    'id' => $item['.id'] ?? null,
                    'name' => $item['name'] ?? null,
                    'session_timeout' => $item['session-timeout'] ?? null,
                    'limit_uptime' => $item['limit-uptime'] ?? null,
                    'source' => 'hotspot',
                ];
            }
        }

        return $profiles;
    }

    private function isTrapResponse($response): bool
    {
        return is_array($response) && (isset($response['!trap']) || isset($response['!fatal']));
    }

    /**
     * Disconnect from MikroTik
     */
    public function disconnect(): void
    {
        if ($this->api) {
            $this->api->disconnect();
            $this->api = null;
        }
    }

    /**
     * Destructor - ensure connection is closed
     */
    public function __destruct()
    {
        $this->disconnect();
    }
}
