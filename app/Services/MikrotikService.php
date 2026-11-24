<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use RouterOS\Client;
use RouterOS\Query;
use Throwable;

class MikrotikService
{
    protected ?Client $client = null;

    protected function client(): Client
    {
        if ($this->client === null) {
            $this->client = new Client([
                'host'    => env('MIKROTIK_HOST', '103.142.194.75'),
                'user'    => env('MIKROTIK_USER', 'magang'),
                'pass'    => env('MIKROTIK_PASS', '2026##'),
                'port'    => (int) env('MIKROTIK_PORT', 8777),
                'timeout' => 3,
            ]);
        }

        return $this->client;
    }

    private function formatBitPair(?string $raw, bool $isLimit = false): string
    {
        if (!$raw || $raw === '0/0') {
            return $isLimit ? 'UNLIMITED' : '0 bps / 0 bps';
        }

        $parts = explode('/', $raw);
        if (count($parts) !== 2) {
            return $raw;
        }

        $formatUnit = function ($bits) {
            $val = (float) $bits;
            if ($val <= 0) {
                return '0 bps';
            }
            if ($val >= 1000000) {
                return round($val / 1000000, 2) . ' Mbps';
            }
            if ($val >= 1000) {
                return round($val / 1000, 1) . ' Kbps';
            }
            return $val . ' bps';
        };

        return $formatUnit($parts[0]) . ' / ' . $formatUnit($parts[1]);
    }

    public function getRouterResource(): array
    {
        $resource = $this->client()->query('/system/resource/print')->read();
        $data     = $resource[0] ?? [];

        $identityName = Cache::remember('mt_router_identity', 60, function () use ($data) {
            $identity = $this->client()->query('/system/identity/print')->read();
            return $identity[0]['name'] ?? ($data['board-name'] ?? 'MikroTik Core');
        });

        $totalMem   = (int) ($data['total-memory'] ?? 1);
        $freeMem    = (int) ($data['free-memory'] ?? 0);
        $usedMemPct = $totalMem > 0 ? round((($totalMem - $freeMem) / $totalMem) * 100) : 0;

        return [
            'identity'     => $identityName,
            'board_name'   => $data['board-name'] ?? 'MikroTik',
            'version'      => $data['version'] ?? '-',
            'uptime'       => $data['uptime'] ?? '-',
            'cpu_load'     => (int) ($data['cpu-load'] ?? 0),
            'memory_usage' => $usedMemPct,
        ];
    }

    public function getRouterInterfaces(): array
    {
        $interfaces = $this->client()->query('/interface/print')->read();
        $result     = [];

        foreach ($interfaces as $iface) {
            $name = $iface['name'] ?? null;
            $type = $iface['type'] ?? '';
            if (!$name) {
                continue;
            }
            if ($type === 'bridge' || str_contains(strtolower($name), 'olt') || str_contains(strtolower($name), 'ether') || $type === 'vlan') {
                $isDisabled = ($iface['disabled'] ?? 'false') === 'true';
                $isRunning  = ($iface['running'] ?? 'true') === 'true';

                $result[$name] = [
                    'name'     => $name,
                    'type'     => $type,
                    'disabled' => $isDisabled,
                    'running'  => $isRunning,
                    'healthy'  => !$isDisabled && $isRunning,
                ];
            }
        }

        return $result;
    }

    public function getNeighbors(): array
    {
        try {
            $rawNeighbors = $this->client()->query('/ip/neighbor/print')->read();
            $neighbors = [];

            foreach ($rawNeighbors as $n) {
                $identity = $n['identity'] ?? ($n['board'] ?? 'Unknown-Neighbor');
                $ip       = $n['address'] ?? ($n['address4'] ?? null);
                $iface    = $n['interface'] ?? 'ether1';
                $mac      = $n['mac-address'] ?? '';
                $platform = $n['platform'] ?? ($n['board'] ?? 'MikroTik');
                $board    = $n['board'] ?? 'RouterOS';

                $neighbors[] = [
                    'identity'  => $identity,
                    'ip'        => $ip,
                    'interface' => $iface,
                    'mac'       => $mac,
                    'platform'  => $platform,
                    'board'     => $board,
                    'version'   => $n['version'] ?? '-',
                ];
            }

            return $neighbors;
        } catch (Throwable) {
            return [];
        }
    }

    private function getRouterOwnIps(): array
    {
        return Cache::remember('mt_router_own_ips', 30, function () {
            $addresses = $this->client()->query('/ip/address/print')->read();
            $ownIps    = [env('MIKROTIK_HOST', '103.142.194.75')];

            foreach ($addresses as $addr) {
                if (isset($addr['address'])) {
                    $ownIps[] = explode('/', $addr['address'])[0];
                }
            }

            return array_values(array_unique($ownIps));
        });
    }

    public function getIntegratedClients(): array
    {
        $ownIps       = $this->getRouterOwnIps();
        $addressLists = $this->client()->query('/ip/firewall/address-list/print')->read();
        $arps         = $this->client()->query('/ip/arp/print')->read();
        $queues       = $this->client()->query('/queue/simple/print')->read();

        $dhcpLeases = [];
        try {
            $dhcpLeases = $this->client()->query('/ip/dhcp-server/lease/print')->read();
        } catch (Throwable) {
        }

        $pppActives = [];
        try {
            $pppActives = $this->client()->query('/ppp/active/print')->read();
        } catch (Throwable) {
        }

        $listMap  = [];
        $arpMap   = [];
        $queueMap = [];
        $dhcpMap  = [];
        $pppMap   = [];
        $allIps   = [];

        foreach ($addressLists as $item) {
            $ip = $item['address'] ?? null;
            if (!$ip || str_contains($ip, '/') || in_array($ip, $ownIps)) {
                continue;
            }
            $listMap[$ip] = $item;
            $allIps[$ip]  = true;
        }

        foreach ($pppActives as $ppp) {
            $ip = $ppp['address'] ?? null;
            if (!$ip || in_array($ip, $ownIps)) {
                continue;
            }
            $pppMap[$ip] = $ppp;
            $allIps[$ip] = true;
        }

        foreach ($dhcpLeases as $lease) {
            $ip = $lease['address'] ?? null;
            if (!$ip || in_array($ip, $ownIps)) {
                continue;
            }
            $dhcpMap[$ip] = $lease;
            $allIps[$ip]  = true;
        }

        foreach ($queues as $q) {
            if (!isset($q['target'])) {
                continue;
            }
            $cleanIp = explode('/', $q['target'])[0];
            if (!filter_var($cleanIp, FILTER_VALIDATE_IP) || in_array($cleanIp, $ownIps)) {
                continue;
            }
            $queueMap[$cleanIp] = $q;
            $allIps[$cleanIp]   = true;
        }

        foreach ($arps as $arp) {
            $ip = $arp['address'] ?? null;
            if (!$ip || in_array($ip, $ownIps)) {
                continue;
            }
            $arpMap[$ip] = $arp;

            $isStatic   = ($arp['dynamic'] ?? 'false') !== 'true';
            $isComplete = ($arp['complete'] ?? 'false') === 'true';
            $iface      = strtolower($arp['interface'] ?? '');
            $isLanIface = str_contains($iface, 'olt') || str_contains($iface, 'bridge') || str_contains($iface, 'lan');

            if ($isStatic || ($isComplete && $isLanIface)) {
                $allIps[$ip] = true;
            }
        }

        $clients = [];
        foreach (array_keys($allIps) as $ip) {
            $listInfo  = $listMap[$ip] ?? null;
            $pppInfo   = $pppMap[$ip] ?? null;
            $dhcpInfo  = $dhcpMap[$ip] ?? null;
            $arpInfo   = $arpMap[$ip] ?? null;
            $queueInfo = $queueMap[$ip] ?? null;

            $sources = [];
            if ($pppInfo) {
                $sources[] = 'PPPoE';
            }
            if ($dhcpInfo) {
                $sources[] = 'DHCP';
            }
            if ($arpInfo) {
                $sources[] = 'ARP';
            }
            if ($queueInfo) {
                $sources[] = 'Queue';
            }
            if ($listInfo) {
                $sources[] = 'FW-List';
            }

            $rawName = $listInfo['comment']
                ?? ($pppInfo['name']
                ?? ($dhcpInfo['comment']
                ?? ($dhcpInfo['host-name']
                ?? ($arpInfo['comment']
                ?? ($queueInfo['name'] ?? $ip)))));

            $cleanName = trim(str_replace(['[SIMULASI]', 'SIM-'], '', $rawName));
            if ($cleanName === '') {
                $cleanName = $ip;
            }

            $mac = $arpInfo['mac-address']
                ?? ($dhcpInfo['mac-address']
                ?? ($pppInfo['caller-id'] ?? '-'));

            $interface = $arpInfo['interface']
                ?? ($pppInfo ? 'PPPoE-Server' : 'bridge-olt1');

            $rawRate  = $queueInfo['rate'] ?? '0/0';
            $rawLimit = $queueInfo['max-limit'] ?? null;

            $arpDisabled   = $arpInfo !== null && (($arpInfo['disabled'] ?? 'false') === 'true');
            $arpInvalid    = $arpInfo !== null && (($arpInfo['invalid'] ?? 'false') === 'true');
            $queueDisabled = $queueInfo !== null && (($queueInfo['disabled'] ?? 'false') === 'true');
            $listDisabled  = $listInfo !== null && (($listInfo['disabled'] ?? 'false') === 'true');
            $isStaticArp   = $arpInfo !== null && (($arpInfo['dynamic'] ?? 'false') !== 'true');

            $clients[] = [
                'address_list_id' => $listInfo['.id'] ?? null,
                'ip_address'      => $ip,
                'status_list'     => $listInfo['list'] ?? 'AUTO_DETECTED',
                'nama_klien'      => $cleanName,
                'mac_address'     => $mac,
                'interface'       => $interface,
                'source'          => implode('+', $sources),
                'is_ppp_active'   => $pppInfo !== null,
                'is_static_arp'   => $isStaticArp,
                'arp_disabled'    => $arpDisabled,
                'arp_invalid'     => $arpInvalid,
                'queue_disabled'  => $queueDisabled,
                'list_disabled'   => $listDisabled,
                'has_queue'       => $queueInfo !== null && $rawLimit !== '0/0',
                'max_limit'       => $this->formatBitPair($rawLimit, true),
                'current_rate'    => $this->formatBitPair($rawRate, false),
                'is_flowing'      => $rawRate !== '0/0',
            ];
        }

        return $clients;
    }

    public function pingFromRouter(string $ipAddress, int $count = 2): array
    {
        $query = (new Query('/tool/ping'))
            ->equal('address', $ipAddress)
            ->equal('count', (string) $count)
            ->equal('interval', '150ms');

        $replies  = $this->client()->query($query)->read();
        $received = 0;
        $times    = [];

        foreach ($replies as $row) {
            if (isset($row['time']) && !isset($row['status'])) {
                $received++;
                $times[] = $row['time'];
            } elseif (isset($row['received']) && (int) $row['received'] > 0) {
                $received = max($received, (int) $row['received']);
            }
        }

        $sent     = $count;
        $received = min($received, $sent);
        $loss     = round((($sent - $received) / $sent) * 100);

        return [
            'ip'           => $ipAddress,
            'online'       => $received > 0,
            'is_simulated' => false,
            'sent'         => $sent,
            'received'     => $received,
            'loss'         => $loss . '%',
            'latency'      => !empty($times) ? end($times) : ($received > 0 ? '1ms' : 'RTO'),
        ];
    }

    public function checkClientsHealth(array $clients, bool $strictPhysical = false): array
    {
        $healthMap = [];

        foreach (array_slice($clients, 0, 30) as $c) {
            $ip           = $c['ip_address'];
            $linkDisabled = !empty($c['arp_disabled']) || !empty($c['arp_invalid']) || !empty($c['queue_disabled']);

            if ($linkDisabled) {
                $healthMap[$ip] = [
                    'ip'           => $ip,
                    'online'       => false,
                    'is_simulated' => !$strictPhysical,
                    'sent'         => 1,
                    'received'     => 0,
                    'loss'         => '100%',
                    'latency'      => 'RTO (Disabled)',
                ];
                continue;
            }

            if (!empty($c['is_flowing']) || !empty($c['is_ppp_active'])) {
                $healthMap[$ip] = [
                    'ip'           => $ip,
                    'online'       => true,
                    'is_simulated' => false,
                    'sent'         => 1,
                    'received'     => 1,
                    'loss'         => '0%',
                    'latency'      => !empty($c['is_ppp_active']) ? 'PPPoE Active' : 'Live Traffic',
                ];
                continue;
            }

            if (!$strictPhysical) {
                $healthMap[$ip] = [
                    'ip'           => $ip,
                    'online'       => true,
                    'is_simulated' => true,
                    'sent'         => 1,
                    'received'     => 1,
                    'loss'         => '0%',
                    'latency'      => 'Online (Auto)',
                ];
                continue;
            }

            $healthMap[$ip] = $this->pingFromRouter($ip, 1);
        }

        return $healthMap;
    }

    public function syncArpToAddressList(): array
    {
        $clients       = $this->getIntegratedClients();
        $existingLists = $this->client()->query('/ip/firewall/address-list/print')->read();
        $existingIps   = array_column($existingLists, 'address');
        $syncedItems   = [];

        foreach ($clients as $c) {
            $ip = $c['ip_address'];
            if (in_array($ip, $existingIps)) {
                continue;
            }

            $query = (new Query('/ip/firewall/address-list/add'))
                ->equal('list', 'PELANGGAN_AKTIF')
                ->equal('address', $ip)
                ->equal('comment', $c['nama_klien']);

            $this->client()->query($query)->read();
            $syncedItems[] = $c;
        }

        return $syncedItems;
    }

    public function toggleClientStatusByIp(string $ipAddress, string $namaKlien, string $targetStatus): void
    {
        $lists   = $this->client()->query('/ip/firewall/address-list/print')->read();
        $foundId = null;

        foreach ($lists as $item) {
            if (($item['address'] ?? '') === $ipAddress) {
                $foundId = $item['.id'];
                break;
            }
        }

        if ($foundId) {
            $query = (new Query('/ip/firewall/address-list/set'))
                ->equal('.id', $foundId)
                ->equal('list', $targetStatus);
        } else {
            $query = (new Query('/ip/firewall/address-list/add'))
                ->equal('list', $targetStatus)
                ->equal('address', $ipAddress)
                ->equal('comment', $namaKlien);
        }

        $this->client()->query($query)->read();
    }

    public function setClientQueueLimit(string $ipAddress, string $namaKlien, string $maxLimit): void
    {
        $queues          = $this->client()->query('/queue/simple/print')->read();
        $existingQueueId = null;

        foreach ($queues as $q) {
            if (isset($q['target']) && explode('/', $q['target'])[0] === $ipAddress) {
                $existingQueueId = $q['.id'];
                break;
            }
        }

        if ($existingQueueId) {
            $query = (new Query('/queue/simple/set'))
                ->equal('.id', $existingQueueId)
                ->equal('max-limit', $maxLimit);
        } else {
            $cleanName = preg_replace('/[^A-Za-z0-9_-]/', '_', $namaKlien);
            $query     = (new Query('/queue/simple/add'))
                ->equal('name', 'SIM-' . $cleanName)
                ->equal('target', $ipAddress . '/32')
                ->equal('max-limit', $maxLimit)
                ->equal('comment', 'Paket ' . $maxLimit);
        }

        $this->client()->query($query)->read();
    }

    public function registerNewClient(array $data): void
    {
        $arpQuery = (new Query('/ip/arp/add'))
            ->equal('address', $data['ip_address'])
            ->equal('mac-address', $data['mac_address'])
            ->equal('interface', $data['interface'])
            ->equal('comment', $data['nama_klien']);
        $this->client()->query($arpQuery)->read();

        $listQuery = (new Query('/ip/firewall/address-list/add'))
            ->equal('list', 'PELANGGAN_AKTIF')
            ->equal('address', $data['ip_address'])
            ->equal('comment', $data['nama_klien']);
        $this->client()->query($listQuery)->read();

        if (!empty($data['max_limit'])) {
            $cleanName  = preg_replace('/[^A-Za-z0-9_-]/', '_', $data['nama_klien']);
            $queueQuery = (new Query('/queue/simple/add'))
                ->equal('name', 'SIM-' . $cleanName)
                ->equal('target', $data['ip_address'] . '/32')
                ->equal('max-limit', $data['max_limit'])
                ->equal('comment', 'Paket ' . $data['max_limit']);
            $this->client()->query($queueQuery)->read();
        }
    }

    public function deleteClient(string $ipAddress): void
    {
        $lists = $this->client()->query('/ip/firewall/address-list/print')->read();
        foreach ($lists as $item) {
            if (($item['address'] ?? '') === $ipAddress) {
                $this->client()->query((new Query('/ip/firewall/address-list/remove'))->equal('.id', $item['.id']))->read();
            }
        }

        $arps = $this->client()->query('/ip/arp/print')->read();
        foreach ($arps as $arp) {
            if (($arp['address'] ?? '') === $ipAddress && ($arp['dynamic'] ?? 'false') === 'false') {
                $this->client()->query((new Query('/ip/arp/remove'))->equal('.id', $arp['.id']))->read();
            }
        }

        $queues = $this->client()->query('/queue/simple/print')->read();
        foreach ($queues as $queue) {
            if (isset($queue['target']) && explode('/', $queue['target'])[0] === $ipAddress) {
                $this->client()->query((new Query('/queue/simple/remove'))->equal('.id', $queue['.id']))->read();
            }
        }
    }

    /**
     * Deteksi lokasi fisik router secara otomatis (Hardware GPS MikroTik atau IP Geolocation)
     */
    public function detectRouterLocation(?string $host = null): array
    {
        $targetHost = $host ?: env('MIKROTIK_HOST', '103.142.194.75');

        // 1. Coba baca modul hardware GPS internal MikroTik (/system/gps/print)
        try {
            $gpsData = $this->client()->query('/system/gps/print')->read();
            if (!empty($gpsData) && isset($gpsData[0]['latitude'], $gpsData[0]['longitude'])) {
                $latRaw = $gpsData[0]['latitude'];
                $lngRaw = $gpsData[0]['longitude'];
                $valid  = ($gpsData[0]['valid'] ?? 'true') === 'true';

                if ($valid && !empty($latRaw) && !empty($lngRaw)) {
                    $lat = $this->parseGpsCoordinate((string) $latRaw);
                    $lng = $this->parseGpsCoordinate((string) $lngRaw);

                    if ($lat !== null && $lng !== null) {
                        return [
                            'status'    => 'success',
                            'source'    => 'hardware_gps',
                            'latitude'  => $lat,
                            'longitude' => $lng,
                            'location'  => 'GPS Internal MikroTik (' . ($gpsData[0]['satellites'] ?? 'N/A') . ' Satelit)',
                        ];
                    }
                }
            }
        } catch (Throwable) {
            // Hardware GPS tidak tersedia
        }

        // 2. Jika bukan IP privat / loopback, coba IP Geolocation API
        if ($targetHost && !in_array($targetHost, ['127.0.0.1', 'localhost', '::1'])) {
            $isPrivate = filter_var(
                $targetHost,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
            ) === false;

            if (!$isPrivate) {
                try {
                    $context = stream_context_create([
                        'http' => [
                            'timeout' => 4,
                            'header'  => "User-Agent: MikroTik-GIS-Topology/1.0\r\n",
                        ],
                    ]);
                    $json = @file_get_contents("http://ip-api.com/json/{$targetHost}", false, $context);
                    if ($json) {
                        $data = json_decode($json, true);
                        if (($data['status'] ?? '') === 'success' && isset($data['lat'], $data['lon'])) {
                            return [
                                'status'    => 'success',
                                'source'    => 'ip_geolocation',
                                'latitude'  => (float) $data['lat'],
                                'longitude' => (float) $data['lon'],
                                'location'  => ($data['city'] ?? 'Kota') . ', ' . ($data['regionName'] ?? '') . ' (' . ($data['country'] ?? 'ID') . ')',
                                'isp'       => $data['isp'] ?? ($data['org'] ?? 'ISP'),
                            ];
                        }
                    }
                } catch (Throwable) {
                    // Fallback jika API gagal
                }
            }
        }

        return [
            'status'  => 'error',
            'message' => 'Tidak dapat mendeteksi koordinat otomatis (IP privat atau tidak ada GPS). Silakan gunakan GPS Browser.',
        ];
    }

    private function parseGpsCoordinate(string $coord): ?float
    {
        $coord = trim($coord);
        if (is_numeric($coord)) {
            return (float) $coord;
        }

        // Format: "S 06 07.123" atau "E 106 09.456"
        $parts = preg_split('/\s+/', $coord);
        if (count($parts) >= 3) {
            $hemisphere = strtoupper($parts[0]);
            $deg = (float) $parts[1];
            $min = (float) $parts[2];
            $val = $deg + ($min / 60.0);
            if ($hemisphere === 'S' || $hemisphere === 'W') {
                $val = -$val;
            }
            return round($val, 6);
        }

        return null;
    }
}