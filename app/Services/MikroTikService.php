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
    public function getProfiles(Network $network, ?string $mode = null): array
    {
        try {
            if (!$this->connect($network)) {
                throw new Exception('Cannot connect to MikroTik');
            }

            $mode = $this->resolveMode($mode);
            if ($mode === 'user_manager') {
                $profiles = $this->fetchUserManagerProfiles();
            } elseif ($mode === 'hotspot') {
                $profiles = $this->fetchHotspotProfiles();
            } else {
                // auto: Prefer User Manager profiles (matches public/profile.php)
                $profiles = $this->fetchUserManagerProfiles();
                if (empty($profiles)) {
                    // Fallback to Hotspot profiles
                    $profiles = $this->fetchHotspotProfiles();
                }
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
     * Get active hotspot sessions
     */
    public function getActiveHotspotSessions(Network $network): array
    {
        try {
            if (!$this->connect($network)) {
                throw new Exception('Cannot connect to MikroTik');
            }

            $response = $this->api->comm('/ip/hotspot/active/print', []);
            if ($this->isTrapResponse($response)) {
                $msg = $this->extractTrapMessage($response) ?? 'Failed to fetch active sessions';
                throw new Exception($msg);
            }

            $this->disconnect();

            return $this->normalizeRows($response);
        } catch (Exception $e) {
            Log::error('MikroTik Active Sessions Error', [
                'network_id' => $network->id,
                'error' => $e->getMessage(),
            ]);
            $this->disconnect();
            return [];
        }
    }

    /**
     * Get connected devices via ARP table
     */
    public function getConnectedDevices(Network $network): array
    {
        try {
            if (!$this->connect($network)) {
                throw new Exception('Cannot connect to MikroTik');
            }

            $response = $this->api->comm('/ip/arp/print', []);
            if ($this->isTrapResponse($response)) {
                $msg = $this->extractTrapMessage($response) ?? 'Failed to fetch connected devices';
                throw new Exception($msg);
            }

            $this->disconnect();

            return $this->normalizeRows($response);
        } catch (Exception $e) {
            Log::error('MikroTik Connected Devices Error', [
                'network_id' => $network->id,
                'error' => $e->getMessage(),
            ]);
            $this->disconnect();
            return [];
        }
    }

    /**
     * Get hotspot hosts (connected)
     */
    public function getHotspotHosts(Network $network): array
    {
        try {
            if (!$this->connect($network)) {
                throw new Exception('Cannot connect to MikroTik');
            }

            $response = $this->api->comm('/ip/hotspot/host/print', []);
            if ($this->isTrapResponse($response)) {
                $msg = $this->extractTrapMessage($response) ?? 'Failed to fetch hotspot hosts';
                throw new Exception($msg);
            }

            $this->disconnect();

            return $this->normalizeRows($response);
        } catch (Exception $e) {
            Log::error('MikroTik Hotspot Hosts Error', [
                'network_id' => $network->id,
                'error' => $e->getMessage(),
            ]);
            $this->disconnect();
            return [];
        }
    }

    /**
     * Get hotspot users
     */
    public function getHotspotUsers(Network $network): array
    {
        try {
            if (!$this->connect($network)) {
                throw new Exception('Cannot connect to MikroTik');
            }

            $response = $this->api->comm('/ip/hotspot/user/print', []);
            if ($this->isTrapResponse($response)) {
                $msg = $this->extractTrapMessage($response) ?? 'Failed to fetch hotspot users';
                throw new Exception($msg);
            }

            $this->disconnect();

            return $this->normalizeRows($response);
        } catch (Exception $e) {
            Log::error('MikroTik Hotspot Users Error', [
                'network_id' => $network->id,
                'error' => $e->getMessage(),
            ]);
            $this->disconnect();
            return [];
        }
    }

    /**
     * Get User Manager users
     */
    public function getUserManagerUsers(Network $network): array
    {
        try {
            $this->lastError = null;
            if (!$this->connect($network)) {
                throw new Exception('Cannot connect to MikroTik');
            }

            $response = $this->api->comm('/tool/user-manager/user/print', [
                '?customer' => $network->mikrotik_user,
            ]);
            if ($this->isTrapResponse($response)) {
                $msg = $this->extractTrapMessage($response) ?? 'Failed to fetch user manager users';
                throw new Exception($msg);
            }

            $this->disconnect();

            return $this->normalizeRows($response);
        } catch (Exception $e) {
            $this->lastError = $e->getMessage();
            Log::error('MikroTik User Manager Users Error', [
                'network_id' => $network->id,
                'error' => $e->getMessage(),
            ]);
            $this->disconnect();
            return [];
        }
    }

    /**
     * Create hotspot user
     */
    public function createHotspotUserWithParams(Network $network, array $data): bool
    {
        try {
            if (!$this->connect($network)) {
                throw new Exception('Cannot connect to MikroTik');
            }

            $result = $this->createHotspotUserOnSession($data);
            $this->disconnect();

            return $result;
        } catch (Exception $e) {
            $this->lastError = $e->getMessage();
            Log::error('MikroTik Create Hotspot User Error', [
                'network_id' => $network->id,
                'error' => $e->getMessage(),
            ]);
            $this->disconnect();
            return false;
        }
    }

    public function createHotspotUserOnSession(array $data): bool
    {
        if (!$this->api || !$this->api->connected) {
            throw new Exception('Cannot connect to MikroTik');
        }

        $params = $this->buildHotspotUserParams($data, true);
        $response = $this->api->comm('/ip/hotspot/user/add', $params);
        $this->ensureNoTrap($response, 'Failed to create hotspot user');

        return true;
    }

    /**
     * Update hotspot user
     */
    public function updateHotspotUser(Network $network, string $userId, array $data): bool
    {
        try {
            if (!$this->connect($network)) {
                throw new Exception('Cannot connect to MikroTik');
            }

            $result = $this->updateHotspotUserOnSession($userId, $data);
            $this->disconnect();

            return $result;
        } catch (Exception $e) {
            Log::error('MikroTik Update Hotspot User Error', [
                'network_id' => $network->id,
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
            $this->disconnect();
            return false;
        }
    }

    public function updateHotspotUserOnSession(string $userId, array $data): bool
    {
        if (!$this->api || !$this->api->connected) {
            throw new Exception('Cannot connect to MikroTik');
        }

        $params = $this->buildHotspotUserParams($data, true);
        $params['.id'] = $userId;

        $response = $this->api->comm('/ip/hotspot/user/set', $params);
        $this->ensureNoTrap($response, 'Failed to update hotspot user');

        return true;
    }

    public function removeHotspotUserById(Network $network, string $userId): bool
    {
        try {
            if (!$this->connect($network)) {
                throw new Exception('Cannot connect to MikroTik');
            }

            $response = $this->api->comm('/ip/hotspot/user/remove', [
                '.id' => $userId,
            ]);
            $this->ensureNoTrap($response, 'Failed to remove hotspot user');

            $this->disconnect();
            return true;
        } catch (Exception $e) {
            Log::error('MikroTik Remove Hotspot User Error', [
                'network_id' => $network->id,
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
            $this->disconnect();
            return false;
        }
    }

    public function disableHotspotUserById(Network $network, string $userId): bool
    {
        try {
            if (!$this->connect($network)) {
                throw new Exception('Cannot connect to MikroTik');
            }

            $response = $this->api->comm('/ip/hotspot/user/disable', [
                '.id' => $userId,
            ]);
            $this->ensureNoTrap($response, 'Failed to disable hotspot user');

            $this->disconnect();
            return true;
        } catch (Exception $e) {
            Log::error('MikroTik Disable Hotspot User Error', [
                'network_id' => $network->id,
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
            $this->disconnect();
            return false;
        }
    }

    public function enableHotspotUserById(Network $network, string $userId): bool
    {
        try {
            if (!$this->connect($network)) {
                throw new Exception('Cannot connect to MikroTik');
            }

            $response = $this->api->comm('/ip/hotspot/user/enable', [
                '.id' => $userId,
            ]);
            $this->ensureNoTrap($response, 'Failed to enable hotspot user');

            $this->disconnect();
            return true;
        } catch (Exception $e) {
            Log::error('MikroTik Enable Hotspot User Error', [
                'network_id' => $network->id,
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
            $this->disconnect();
            return false;
        }
    }

    public function resetHotspotUserCounters(Network $network, string $userId): bool
    {
        try {
            if (!$this->connect($network)) {
                throw new Exception('Cannot connect to MikroTik');
            }

            $response = $this->api->comm('/ip/hotspot/user/reset-counters', [
                '.id' => $userId,
            ]);
            $this->ensureNoTrap($response, 'Failed to reset hotspot counters');

            $this->disconnect();
            return true;
        } catch (Exception $e) {
            Log::error('MikroTik Reset Hotspot Counters Error', [
                'network_id' => $network->id,
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
            $this->disconnect();
            return false;
        }
    }

    /**
     * Install or reset hotspot on-login script (bind first device)
     */
    public function installHotspotLoginScript(Network $network, string $profileName, bool $resetOnly = false): array
    {
        try {
            if (!$this->connect($network)) {
                throw new Exception('Cannot connect to MikroTik');
            }

            $profiles = $this->api->comm('/ip/hotspot/user/profile/print', [
                '?name' => $profileName,
            ]);
            if ($this->isTrapResponse($profiles)) {
                $msg = $this->extractTrapMessage($profiles) ?? 'Failed to fetch hotspot profile';
                throw new Exception($msg);
            }

            $profileId = null;
            $existingScript = '';
            if (is_array($profiles)) {
                foreach ($profiles as $row) {
                    if (is_array($row) && isset($row['.id'])) {
                        $profileId = $row['.id'];
                        $existingScript = $row['on-login'] ?? '';
                        break;
                    }
                }
            }

            if (!$profileId) {
                throw new Exception('لم يتم العثور على البروفايل في MikroTik');
            }

            $block = <<<'ROS'
#QAISNET_START
:local user $user;
:local mac $"mac-address";
:if ([:len $user] = 0) do={ :return; }
:local id [/ip hotspot user find name=$user];
:if ([:len $id] > 0) do={
  :local shared [/ip hotspot user get $id shared-users];
  :local userMac [/ip hotspot user get $id mac-address];
  :if (($shared=1) && ($userMac="")) do={
    /ip hotspot user set $id mac-address=$mac;
  }
}
#QAISNET_END
ROS;

            $newScript = $existingScript ?? '';
            if ($resetOnly) {
                $pattern = '/\s*#QAISNET_START[\s\S]*?#QAISNET_END\s*/';
                $newScript = preg_replace($pattern, '', (string) $newScript);
                $newScript = trim((string) $newScript);
            } else {
                if (strpos((string) $newScript, '#QAISNET_START') === false) {
                    $newScript = trim((string) $newScript);
                    if ($newScript !== '') {
                        $newScript .= "\n";
                    }
                    $newScript .= $block;
                }
            }

            $response = $this->api->comm('/ip/hotspot/user/profile/set', [
                '.id' => $profileId,
                'on-login' => $newScript,
            ]);
            $this->ensureNoTrap($response, 'Failed to update hotspot profile');

            $this->disconnect();
            return ['success' => true];
        } catch (Exception $e) {
            Log::error('MikroTik Hotspot Script Error', [
                'network_id' => $network->id,
                'profile' => $profileName,
                'error' => $e->getMessage(),
            ]);
            $this->disconnect();
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Get neighbor devices (connected devices list)
     */
    public function getNeighbors(Network $network): array
    {
        try {
            if (!$this->connect($network)) {
                throw new Exception('Cannot connect to MikroTik');
            }

            $response = $this->api->comm('/ip/neighbor/print', []);
            if ($this->isTrapResponse($response)) {
                $msg = $this->extractTrapMessage($response) ?? 'Failed to fetch neighbors';
                throw new Exception($msg);
            }

            $this->disconnect();

            return $this->normalizeRows($response);
        } catch (Exception $e) {
            Log::error('MikroTik Neighbors Error', [
                'network_id' => $network->id,
                'error' => $e->getMessage(),
            ]);
            $this->disconnect();
            return [];
        }
    }

    /**
     * Clear active hotspot sessions
     */
    public function clearActiveSessions(Network $network): array
    {
        try {
            if (!$this->connect($network)) {
                throw new Exception('Cannot connect to MikroTik');
            }

            $response = $this->api->comm('/ip/hotspot/active/print', []);
            if ($this->isTrapResponse($response)) {
                $msg = $this->extractTrapMessage($response) ?? 'Failed to fetch active sessions';
                throw new Exception($msg);
            }

            $removed = 0;
            if (is_array($response)) {
                foreach ($response as $row) {
                    if (is_array($row) && isset($row['.id'])) {
                        $remove = $this->api->comm('/ip/hotspot/active/remove', [
                            '.id' => $row['.id'],
                        ]);
                        $this->ensureNoTrap($remove, 'Failed to remove active session');
                        $removed++;
                    }
                }
            }

            $this->disconnect();
            return ['success' => true, 'count' => $removed];
        } catch (Exception $e) {
            Log::error('MikroTik Clear Active Sessions Error', [
                'network_id' => $network->id,
                'error' => $e->getMessage(),
            ]);
            $this->disconnect();
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Clear hotspot hosts
     */
    public function clearHotspotHosts(Network $network): array
    {
        try {
            if (!$this->connect($network)) {
                throw new Exception('Cannot connect to MikroTik');
            }

            $response = $this->api->comm('/ip/hotspot/host/print', []);
            if ($this->isTrapResponse($response)) {
                $msg = $this->extractTrapMessage($response) ?? 'Failed to fetch hotspot hosts';
                throw new Exception($msg);
            }

            $removed = 0;
            if (is_array($response)) {
                foreach ($response as $row) {
                    if (is_array($row) && isset($row['.id'])) {
                        $remove = $this->api->comm('/ip/hotspot/host/remove', [
                            '.id' => $row['.id'],
                        ]);
                        $this->ensureNoTrap($remove, 'Failed to remove hotspot host');
                        $removed++;
                    }
                }
            }

            $this->disconnect();
            return ['success' => true, 'count' => $removed];
        } catch (Exception $e) {
            Log::error('MikroTik Clear Hotspot Hosts Error', [
                'network_id' => $network->id,
                'error' => $e->getMessage(),
            ]);
            $this->disconnect();
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Get per-port traffic stats
     */
    public function getPortStats(Network $network): array
    {
        try {
            if (!$this->connect($network)) {
                throw new Exception('Cannot connect to MikroTik');
            }

            $interfaces = $this->api->comm('/interface/print', []);
            if ($this->isTrapResponse($interfaces)) {
                $msg = $this->extractTrapMessage($interfaces) ?? 'Failed to fetch interfaces';
                throw new Exception($msg);
            }

            $interfaces = $this->normalizeRows($interfaces);
            $ports = [];

            foreach ($interfaces as $iface) {
                $name = $iface['name'] ?? null;
                if (!$name) {
                    continue;
                }

                $monitor = $this->api->comm('/interface/monitor-traffic', [
                    'interface' => $name,
                    'once' => '',
                ]);

                $monitorRow = [];
                if (! $this->isTrapResponse($monitor)) {
                    if (is_array($monitor) && isset($monitor[0]) && is_array($monitor[0])) {
                        $monitorRow = $this->normalizeRow($monitor[0]);
                    } elseif (is_array($monitor)) {
                        $monitorRow = $this->normalizeRow($monitor);
                    }
                }

                $ports[] = [
                    'name' => $name,
                    'type' => $iface['type'] ?? null,
                    'running' => $iface['running'] ?? null,
                    'rx_bps' => isset($monitorRow['rx_bits_per_second']) ? (int) $monitorRow['rx_bits_per_second'] : null,
                    'tx_bps' => isset($monitorRow['tx_bits_per_second']) ? (int) $monitorRow['tx_bits_per_second'] : null,
                    'rx_pps' => isset($monitorRow['rx_packets_per_second']) ? (int) $monitorRow['rx_packets_per_second'] : null,
                    'tx_pps' => isset($monitorRow['tx_packets_per_second']) ? (int) $monitorRow['tx_packets_per_second'] : null,
                    'rx_bytes' => isset($iface['rx_byte']) ? (int) $iface['rx_byte'] : null,
                    'tx_bytes' => isset($iface['tx_byte']) ? (int) $iface['tx_byte'] : null,
                ];
            }

            $this->disconnect();

            return $ports;
        } catch (Exception $e) {
            Log::error('MikroTik Port Stats Error', [
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

    public function createUserOnSession(
        Network $network,
        string $username,
        string $password,
        string $profile,
        int $validityDays = 30,
        ?string $mode = null
    ): bool
    {
        try {
            if (!$this->api || !$this->api->connected) {
                throw new Exception('Cannot connect to MikroTik');
            }

            $mode = $this->resolveMode($mode);
            if ($mode === 'hotspot') {
                $this->createHotspotUser($username, $password, $profile, $validityDays);
                return true;
            }

            if ($mode === 'user_manager') {
                $userManagerCreated = $this->createUserManagerUser(
                    $network,
                    $username,
                    $password,
                    $profile
                );

                if (!$userManagerCreated) {
                    throw new Exception('User Manager غير متاح أو غير مُهيأ بشكل صحيح.');
                }
                return true;
            }

            // auto: Try User Manager first, fallback to Hotspot
            $userManagerCreated = $this->createUserManagerUser(
                $network,
                $username,
                $password,
                $profile
            );

            if (!$userManagerCreated) {
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

            $mode = $this->resolveMode();
            if ($mode === 'hotspot') {
                $hotspotResult = $this->removeHotspotUser($username);
                $this->disconnect();
                return $hotspotResult;
            }

            if ($mode === 'user_manager') {
                $userManagerResult = $this->removeUserManagerUser($network, $username);
                if ($userManagerResult === null) {
                    throw new Exception('User Manager غير متاح أو غير مُهيأ بشكل صحيح.');
                }
                $this->disconnect();
                return true; // true or false (not found) are both OK here
            }

            // auto: Try User Manager, fallback to Hotspot
            $userManagerResult = $this->removeUserManagerUser($network, $username);
            if ($userManagerResult === true) {
                $this->disconnect();
                return true;
            }

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
            $lower = strtolower($trapMessage);
            if (str_contains($lower, 'no such command')) {
                return false;
            }
            if (str_contains($lower, 'input does not match any value of customer')) {
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
            $lower = strtolower($trapMessage);
            if (str_contains($lower, 'no such command')) {
                return null; // user manager not available
            }
            if (str_contains($lower, 'input does not match any value of customer')) {
                return null; // wrong customer; fallback to hotspot
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

    private function buildHotspotUserParams(array $data, bool $includeName = true): array
    {
        $params = [];

        if ($includeName) {
            $name = $data['name'] ?? $data['username'] ?? null;
            if (is_string($name) && trim($name) !== '') {
                $params['name'] = $name;
            }
        }

        $password = $data['password'] ?? null;
        if (is_string($password) && trim($password) !== '') {
            $params['password'] = $password;
        }

        $profile = $data['profile'] ?? null;
        if (is_string($profile) && trim($profile) !== '') {
            $params['profile'] = $profile;
        }

        $server = $data['server'] ?? null;
        if (is_string($server) && trim($server) !== '') {
            $params['server'] = $server;
        }

        if (array_key_exists('limit_uptime', $data)) {
            $limitUptime = $data['limit_uptime'];
            if (is_string($limitUptime) && trim($limitUptime) !== '') {
                $params['limit-uptime'] = $limitUptime;
            }
        }

        if (array_key_exists('limit_bytes_total', $data)) {
            $limitBytes = $data['limit_bytes_total'];
            if ($limitBytes !== null && $limitBytes !== '') {
                $params['limit-bytes-total'] = $limitBytes;
            }
        }

        if (array_key_exists('shared_users', $data)) {
            $sharedUsers = $data['shared_users'];
            if ($sharedUsers !== null && $sharedUsers !== '') {
                $params['shared-users'] = $sharedUsers;
            }
        }

        if (array_key_exists('comment', $data)) {
            $comment = $data['comment'];
            if (is_string($comment) && trim($comment) !== '') {
                $params['comment'] = $comment;
            }
        }

        if (array_key_exists('mac_address', $data)) {
            $mac = $data['mac_address'];
            if ($mac === null) {
                $params['mac-address'] = '';
            } elseif (is_string($mac)) {
                $params['mac-address'] = trim($mac);
            }
        }

        if (array_key_exists('disabled', $data)) {
            $disabled = $data['disabled'];
            $params['disabled'] = $disabled ? 'yes' : 'no';
        }

        return $params;
    }

    private function resolveMode(?string $override = null): string
    {
        $override = strtolower(trim((string) $override));
        if ($override !== '' && in_array($override, ['hotspot', 'user_manager', 'auto'], true)) {
            return $override;
        }

        $mode = strtolower((string) config('services.mikrotik.mode', 'hotspot'));
        if (!in_array($mode, ['hotspot', 'user_manager', 'auto'], true)) {
            return 'hotspot';
        }
        return $mode;
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

    private function normalizeRows($response): array
    {
        if (!is_array($response)) {
            return [];
        }

        $rows = [];
        foreach ($response as $row) {
            if (!is_array($row)) {
                continue;
            }
            $rows[] = $this->normalizeRow($row);
        }

        return $rows;
    }

    private function normalizeRow(array $row): array
    {
        $normalized = [];
        foreach ($row as $key => $value) {
            $key = ltrim((string) $key, '.');
            $key = str_replace(['-', '/'], '_', $key);
            $normalized[$key] = $value;
        }

        return $normalized;
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
