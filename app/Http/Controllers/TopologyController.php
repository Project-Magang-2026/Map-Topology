<?php

namespace App\Http\Controllers;

use App\Models\NetworkNode;
use App\Models\TelemetrySnapshot;
use App\Services\MikrotikService;
use App\Services\RootCauseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Throwable;

class TopologyController extends Controller
{
    public function __construct(
        protected MikrotikService $mikrotik,
        protected RootCauseService $rcaService
    ) {
    }

    private function haversineMeters(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $earthRadius = 6371000;
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);

        $a = sin($dLat / 2) * sin($dLat / 2) +
             cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
             sin($dLon / 2) * sin($dLon / 2);

        return $earthRadius * (2 * atan2(sqrt($a), sqrt(1 - $a)));
    }

    private function autoDiscoverNodes(array $resource, array $clients, array $routerInterfaces, array $neighbors = []): void
    {
        $routerHost = env('MIKROTIK_HOST', '103.142.194.75');

        $oltInterfaces = collect($routerInterfaces)
            ->keys()
            ->filter(fn($i) => str_contains(strtolower($i), 'olt'));

        $interfaces = collect($clients)->pluck('interface')
            ->merge($oltInterfaces)
            ->filter()
            ->unique()
            ->values();

        $clientSignature = collect($clients)->map(fn($c) => [
            'ip'    => $c['ip_address'],
            'name'  => $c['nama_klien'],
            'iface' => $c['interface'],
        ])->values()->all();

        // Lewati operasi tulis SQLite jika struktur perangkat & nama tidak berubah
        $currentHash = md5(json_encode([$routerHost, $interfaces->all(), $clientSignature]));
        if (Cache::get('topology_nodes_hash') === $currentHash && NetworkNode::exists()) {
            return;
        }

        $baseLat = -6.1180000;
        $baseLng = 106.1503000;

        $router = NetworkNode::firstOrCreate(
            ['identifier' => $routerHost],
            [
                'name'              => ($resource['identity'] ?? 'MikroTik') . " ($routerHost)",
                'type'              => 'router',
                'node_subtype'      => 'core_router',
                'parent_identifier' => null,
                'latitude'          => $baseLat,
                'longitude'         => $baseLng,
            ]
        );

        if ($interfaces->isEmpty()) {
            NetworkNode::where('type', 'olt')->delete();
        } else {
            NetworkNode::where('type', 'olt')
                ->whereNotIn('identifier', $interfaces->all())
                ->delete();
        }

        foreach ($interfaces as $idx => $iface) {
            $angle = ($idx + 1) * 1.2;
            NetworkNode::firstOrCreate(
                ['identifier' => $iface],
                [
                    'name'              => strtoupper($iface),
                    'type'              => 'olt',
                    'node_subtype'      => 'gpon_olt',
                    'parent_identifier' => $routerHost,
                    'latitude'          => $router->latitude + (sin($angle) * 0.0014),
                    'longitude'         => $router->longitude + (cos($angle) * 0.0014),
                ]
            );
        }

        // Seeding default ODP jika belum ada ODP sama sekali (FTTH ODN Hierarchy)
        if (NetworkNode::where('type', 'odp')->count() === 0) {
            $olts = NetworkNode::where('type', 'olt')->get();
            foreach ($olts as $oIdx => $olt) {
                // Buat 2 ODP per OLT
                for ($p = 1; $p <= 2; $p++) {
                    $odpId    = "ODP-" . strtoupper(Str::slug($olt->identifier)) . "-0{$p}";
                    $odpAngle = ($p * 1.4) + ($oIdx * 0.5);
                    NetworkNode::firstOrCreate(
                        ['identifier' => $odpId],
                        [
                            'name'              => $odpId,
                            'type'              => 'odp',
                            'node_subtype'      => 'splitter_1_8',
                            'parent_identifier' => $olt->identifier,
                            'capacity_ports'    => 8,
                            'used_ports'        => 0,
                            'latitude'          => $olt->latitude + (sin($odpAngle) * 0.0008),
                            'longitude'         => $olt->longitude + (cos($odpAngle) * 0.0008),
                            'notes'             => "Tiang Distribusi RT 0{$p}",
                        ]
                    );
                }
            }
        }

        // Auto-discover Switch / Neighbor via MNDP / LLDP (Fase 3)
        if (!empty($neighbors)) {
            foreach ($neighbors as $nIdx => $n) {
                $nIdentifier = !empty($n['ip']) ? $n['ip'] : ('NEIGHBOR-' . strtoupper(Str::slug($n['identity'])));
                $isSwitch    = str_contains(strtolower($n['identity']), 'switch') || str_contains(strtolower($n['board']), 'crs') || str_contains(strtolower($n['board']), 'css');
                $nodeType    = $isSwitch ? 'switch' : 'router';
                $angle       = ($nIdx + 1) * 2.5;

                NetworkNode::firstOrCreate(
                    ['identifier' => $nIdentifier],
                    [
                        'name'              => $n['identity'],
                        'type'              => $nodeType,
                        'node_subtype'      => ($n['platform'] ?? 'MikroTik') . ' ' . ($n['board'] ?? 'Hardware'),
                        'parent_identifier' => $routerHost,
                        'latitude'          => $router->latitude + (sin($angle) * 0.0018),
                        'longitude'         => $router->longitude + (cos($angle) * 0.0018),
                        'notes'             => "Neighbor via {$n['interface']} | MAC: {$n['mac']} | OS: {$n['version']}",
                    ]
                );
            }
        } elseif (NetworkNode::where('type', 'switch')->count() === 0) {
            // Seeding default switch distribusi NOC jika belum ada
            NetworkNode::firstOrCreate(
                ['identifier' => 'CRS326-DIST-NOC'],
                [
                    'name'              => 'CRS326 Distribution NOC',
                    'type'              => 'switch',
                    'node_subtype'      => 'MikroTik CRS326-24G-2S+RM',
                    'parent_identifier' => $routerHost,
                    'capacity_ports'    => 24,
                    'used_ports'        => 8,
                    'latitude'          => $router->latitude + 0.0013,
                    'longitude'         => $router->longitude - 0.0012,
                    'notes'             => 'Switch Agregasi Server & OLT Feeder (Uplink sfp-sfpplus1)',
                ]
            );
        }

        $activeClientIps = collect($clients)->pluck('ip_address')->unique()->values()->all();
        if (empty($activeClientIps)) {
            NetworkNode::where('type', 'client')->delete();
        } else {
            NetworkNode::where('type', 'client')
                ->whereNotIn('identifier', $activeClientIps)
                ->delete();
        }

        $allOdpMap = NetworkNode::where('type', 'odp')->get()->keyBy('identifier');
        $oltMap    = NetworkNode::where('type', 'olt')->get()->keyBy('identifier');

        foreach ($clients as $i => $c) {
            $ip = $c['ip_address'];

            // Cek apakah klien sudah punya node & parent tersimpan
            $existingNode = NetworkNode::where('identifier', $ip)->first();

            $parentIdentifier = null;
            if ($existingNode && $existingNode->parent_identifier) {
                // Pertahankan parent jika parent masih ada (bisa ODP, ODC, atau OLT)
                $parentExists = NetworkNode::where('identifier', $existingNode->parent_identifier)->exists();
                if ($parentExists) {
                    $parentIdentifier = $existingNode->parent_identifier;
                }
            }

            // Jika belum ada parent atau parent tidak valid, pilih ODP terdekat di OLT yang sama, atau fallback ke OLT
            if (!$parentIdentifier) {
                $candidateOdps = $allOdpMap->filter(fn($odp) => $odp->parent_identifier === $c['interface']);
                if ($candidateOdps->isNotEmpty()) {
                    // Distribusikan bergantian ke ODP yang tersedia
                    $odpKeys          = $candidateOdps->keys();
                    $assignedOdpKey   = $odpKeys[$i % $odpKeys->count()];
                    $parentIdentifier = $assignedOdpKey;
                } else {
                    $parentOlt        = $oltMap->get($c['interface']) ?? $router;
                    $parentIdentifier = $parentOlt->identifier;
                }
            }

            $parentNode = NetworkNode::where('identifier', $parentIdentifier)->first() ?? $router;
            $angle      = ($i + 1) * 0.9;
            $colorIndex = ($i % 12) + 1;
            $colorName  = NetworkNode::FIBER_COLORS[$colorIndex]['name'] ?? 'Biru';

            $node = NetworkNode::firstOrCreate(
                ['identifier' => $ip],
                [
                    'name'              => $c['nama_klien'],
                    'type'              => 'client',
                    'node_subtype'      => 'ont_pppoe',
                    'parent_identifier' => $parentIdentifier,
                    'fiber_core_color'  => $colorName,
                    'latitude'          => $parentNode->latitude + (sin($angle) * 0.0007),
                    'longitude'         => $parentNode->longitude + (cos($angle) * 0.0007),
                ]
            );

            // Update nama jika berubah tanpa menghapus parent_identifier ODP yang telah di-assign
            if ($node->name !== $c['nama_klien']) {
                $node->update(['name' => $c['nama_klien']]);
            }
        }

        Cache::put('topology_nodes_hash', $currentHash, 300);
    }

    private function buildTopologyPayload(bool $runHealthCheck = true, bool $strictPhysical = false): array
    {
        $startedAt     = microtime(true);
        $mikrotikError = null;
        $resource      = [
            'identity'     => 'MikroTik',
            'board_name'   => 'MikroTik',
            'version'      => '-',
            'uptime'       => '-',
            'cpu_load'     => 0,
            'memory_usage' => 0,
        ];
        $clients          = [];
        $routerInterfaces = [];
        $healthMap        = [];

        $neighbors        = [];
        try {
            $resource         = $this->mikrotik->getRouterResource();
            $clients          = $this->mikrotik->getIntegratedClients();
            // Cache interface & neighbor agar hemat round-trip ke RouterOS
            $routerInterfaces = Cache::remember('mt_router_interfaces', 15, fn() => $this->mikrotik->getRouterInterfaces());
            $neighbors        = Cache::remember('mt_router_neighbors', 30, fn() => $this->mikrotik->getNeighbors());

            $this->autoDiscoverNodes($resource, $clients, $routerInterfaces, $neighbors);

            if ($runHealthCheck && !empty($clients)) {
                $healthMap = $this->mikrotik->checkClientsHealth($clients, $strictPhysical);
            }
        } catch (Throwable $e) {
            $mikrotikError = $e->getMessage();
        }

        $rtoList = [];

        $clients = array_map(function ($c) use ($healthMap, $routerInterfaces, $runHealthCheck, &$rtoList) {
            $ip           = $c['ip_address'];
            $ping         = $healthMap[$ip] ?? null;
            $ifaceInfo    = $routerInterfaces[$c['interface']] ?? null;
            $ifaceDown    = $ifaceInfo !== null && !$ifaceInfo['healthy'];
            $linkDisabled = !empty($c['arp_disabled']) || !empty($c['arp_invalid']) || !empty($c['queue_disabled']);
            $isChecked    = $runHealthCheck && $ping !== null;
            $isOnline     = $isChecked ? $ping['online'] : !$linkDisabled;
            $isIsolir     = strtoupper($c['status_list']) === 'ISOLIR';
            $isRto        = (!$isOnline || $linkDisabled) && !$ifaceDown && !$isIsolir;
            $hasProblem   = $ifaceDown || $isRto || $isIsolir;

            if ($isRto) {
                $rtoList[] = [
                    'ip'   => $ip,
                    'name' => $c['nama_klien'],
                    'olt'  => $c['interface'],
                ];
            }

            $c['ping_checked'] = $isChecked;
            $c['is_online']    = $isOnline;
            $c['is_rto']       = $isRto;
            $c['is_simulated'] = $ping['is_simulated'] ?? true;
            $c['latency']      = $ping['latency'] ?? ($linkDisabled ? 'RTO (Disabled)' : 'Online (Auto)');
            $c['loss']         = $ping['loss'] ?? ($isRto ? '100%' : '0%');
            $c['has_problem']  = $hasProblem;
            $c['problem_msg']  = $ifaceDown
                ? "PORT {$c['interface']} DOWN"
                : ($isRto ? "RTO / LINK DOWN" : ($isIsolir ? 'STATUS ISOLIR' : 'ONLINE'));

            return $c;
        }, $clients);

        $nodes            = NetworkNode::all()->keyBy('identifier');
        $clientMap        = collect($clients)->keyBy('ip_address');
        $totalCableMeters = 0;

        // Hitung pemakaian port ODP secara dinamis
        $usedPortsCountMap = $nodes->whereNotNull('parent_identifier')
            ->groupBy('parent_identifier')
            ->map(fn($group) => $group->count());

        $topologyData = $nodes->map(function ($node) use ($clientMap, $nodes, &$totalCableMeters, $mikrotikError, $routerInterfaces, $usedPortsCountMap) {
            $status     = $node->type;
            $detail     = strtoupper($node->type);
            $mtData     = null;
            $isFlowing  = false;
            $hasProblem = false;

            // Update used_ports jika node tipe ODP atau ODC
            $usedPorts = $usedPortsCountMap->get($node->identifier, 0);

            if ($node->type === 'router' && $mikrotikError !== null) {
                $status     = 'problem';
                $hasProblem = true;
                $detail     = "PROBLEM: Router API Terputus!";
            } elseif ($node->type === 'olt') {
                $ifaceInfo = $routerInterfaces[$node->identifier] ?? null;
                if ($ifaceInfo && !$ifaceInfo['healthy']) {
                    $status     = 'problem';
                    $hasProblem = true;
                    $detail     = $ifaceInfo['disabled']
                        ? "PROBLEM: Interface Disabled (X)"
                        : "PROBLEM: Link OLT Down / No Running";
                } else {
                    $detail = "OLT / BRIDGE AKTIF";
                }
            } elseif ($node->type === 'odc') {
                $parentOlt = $node->parent_identifier ? ($routerInterfaces[$node->parent_identifier] ?? null) : null;
                if ($parentOlt !== null && !$parentOlt['healthy']) {
                    $status     = 'problem';
                    $hasProblem = true;
                    $detail     = "PROBLEM: Jalur OLT {$node->parent_identifier} Down!";
                } else {
                    $status = 'odc';
                    $detail = "ODC / FDT CABIN ({$usedPorts}/{$node->capacity_ports} Core)";
                }
            } elseif ($node->type === 'odp') {
                // Cek kesehatan parent (bisa ODC atau OLT)
                $parent = $node->parent_identifier ? $nodes->get($node->parent_identifier) : null;
                $parentProblem = false;
                if ($parent) {
                    if ($parent->type === 'olt') {
                        $pInfo = $routerInterfaces[$parent->identifier] ?? null;
                        $parentProblem = $pInfo !== null && !$pInfo['healthy'];
                    }
                }

                if ($parentProblem) {
                    $status     = 'problem';
                    $hasProblem = true;
                    $detail     = "PROBLEM: Jalur Distribusi OLT Down!";
                } else {
                    $status = 'odp';
                    $detail = "ODP ({$usedPorts}/{$node->capacity_ports} Port Terpakai)";
                }
            } elseif ($node->type === 'switch') {
                if ($mikrotikError !== null) {
                    $status     = 'problem';
                    $hasProblem = true;
                    $detail     = "PROBLEM: Link ke Core Router Terputus";
                } else {
                    $status = 'switch';
                    $detail = "SWITCH DISTRIBUSI | " . ($node->node_subtype ?? 'Gigabit Managed Switch');
                }
            } elseif ($node->type === 'client') {
                $mt     = $clientMap->get($node->identifier);
                $mtData = $mt;

                // Parent klien bisa berupa ODP, ODC, atau OLT
                $parent = $node->parent_identifier ? $nodes->get($node->parent_identifier) : null;
                $oltDown = false;
                if ($parent && $parent->type === 'olt') {
                    $pInfo = $routerInterfaces[$parent->identifier] ?? null;
                    $oltDown = $pInfo !== null && !$pInfo['healthy'];
                }

                if ($mikrotikError !== null) {
                    $status     = 'problem';
                    $hasProblem = true;
                    $detail     = "PROBLEM: Koneksi Router Terputus";
                } elseif ($oltDown) {
                    $status     = 'problem';
                    $hasProblem = true;
                    $detail     = "PROBLEM: Jalur {$node->parent_identifier} Down!";
                } elseif ($mt && $mt['is_rto']) {
                    $status     = 'problem';
                    $hasProblem = true;
                    $detail     = "RTO / LINK DOWN | {$node->identifier}";
                } elseif ($mt) {
                    $isFlowing = $mt['is_flowing'];
                    if (strtoupper($mt['status_list']) === 'ISOLIR') {
                        $status     = 'isolir';
                        $hasProblem = true;
                        $detail     = "ISOLIR | {$mt['ip_address']}";
                    } elseif (!$mt['has_queue']) {
                        $status = 'warning';
                        $detail = "ONLINE TANPA LIMIT! [{$mt['latency']}]";
                    } else {
                        $status = 'aktif';
                        $detail = "ONLINE ({$mt['max_limit']}) | {$mt['latency']} | Trafik: {$mt['current_rate']}";
                    }
                }
            }

            $parent         = $node->parent_identifier ? $nodes->get($node->parent_identifier) : null;
            $distanceMeters = 0;

            if ($parent) {
                $distanceMeters = round($this->haversineMeters(
                    (float) $parent->latitude,
                    (float) $parent->longitude,
                    (float) $node->latitude,
                    (float) $node->longitude
                ));
                $totalCableMeters += $distanceMeters;
            }

            // Estimasi Redaman Optik Rx (dBm) untuk Pelanggan FTTH
            $opticalRx = $node->optical_power_rx;
            if ($node->type === 'client' && $opticalRx === null) {
                if ($hasProblem) {
                    $opticalRx = -35.00; // LOS / Mati total
                } else {
                    // Standar FTTH: Tx +2.5 dBm, Splitter 1:8 (-10.5 dBm), Patchcord (-1.5 dBm), Fiber loss (-0.35 dB/km)
                    $baseLoss = 18.50 + ($distanceMeters * 0.0035);
                    $opticalRx = round(-$baseLoss, 2);
                }
            }

            $opticalStatus = 'unknown';
            if ($opticalRx !== null) {
                if ($opticalRx >= -23.0) {
                    $opticalStatus = 'optimal';
                } elseif ($opticalRx >= -27.0) {
                    $opticalStatus = 'warning';
                } else {
                    $opticalStatus = 'critical';
                }
            }

            return [
                'id'                => $node->id,
                'identifier'        => $node->identifier,
                'name'              => $node->name,
                'type'              => $node->type,
                'node_subtype'      => $node->node_subtype ?? $node->type,
                'parent_identifier' => $node->parent_identifier,
                'lat'               => (float) $node->latitude,
                'lng'               => (float) $node->longitude,
                'parent_lat'        => $parent ? (float) $parent->latitude : null,
                'parent_lng'        => $parent ? (float) $parent->longitude : null,
                'distance_meters'   => $distanceMeters,
                'capacity_ports'    => $node->capacity_ports ?? 8,
                'used_ports'        => $usedPorts,
                'fiber_core_color'  => $node->fiber_core_color,
                'fiber_hex'         => $node->fiber_hex,
                'optical_power_rx'  => $opticalRx,
                'optical_status'    => $opticalStatus,
                'notes'             => $node->notes,
                'status'            => $status,
                'has_problem'       => $hasProblem,
                'is_flowing'        => $isFlowing,
                'detail'            => $detail,
                'mikrotik'          => $mtData,
            ];
        })->values();

        $interfaces = !empty($routerInterfaces) ? array_keys($routerInterfaces) : ['bridge-olt1', 'bridge-olt2'];
        $elapsedMs  = round((microtime(true) - $startedAt) * 1000);

        $odpNodes = $topologyData->where('type', 'odp');
        $odcNodes = $topologyData->where('type', 'odc');

        $summary = [
            'total'       => count($clients),
            'aktif'       => count(array_filter($clients, fn($c) => !$c['has_problem'])),
            'problem'     => $topologyData->where('has_problem', true)->count(),
            'rto_count'   => count($rtoList),
            'isolir'      => count(array_filter($clients, fn($c) => strtoupper($c['status_list']) === 'ISOLIR')),
            'tanpa_limit' => count(array_filter($clients, fn($c) => !$c['has_queue'] && !$c['has_problem'])),
            'odp_count'   => $odpNodes->count(),
            'odc_count'   => $odcNodes->count(),
            'total_cable' => $totalCableMeters >= 1000
                ? round($totalCableMeters / 1000, 2) . ' km'
                : $totalCableMeters . ' m',
            'latency_ms'  => $elapsedMs,
        ];

        // Daftar ODP untuk dropdown registrasi / assign klien
        $availableOdps = $odpNodes->map(fn($o) => [
            'identifier'     => $o['identifier'],
            'name'           => $o['name'],
            'used_ports'     => $o['used_ports'],
            'capacity_ports' => $o['capacity_ports'],
            'available'      => $o['capacity_ports'] - $o['used_ports'],
        ])->values()->all();

        $fiberColors = NetworkNode::FIBER_COLORS;

        $rcaData = $this->rcaService->analyze($topologyData, $clients, $routerInterfaces, $mikrotikError);
        $rcaIncidents = $rcaData['incidents'];
        $suppressedAlertCount = $rcaData['suppressed'];

        $summary['rca_incident_count'] = count($rcaIncidents);
        $summary['suppressed_count']   = $suppressedAlertCount;

        if (!Cache::has('last_telemetry_snapshot_recorded')) {
            try {
                TelemetrySnapshot::create([
                    'cpu_load'        => $resource['cpu_load'] ?? 0,
                    'memory_usage'    => $resource['memory_usage'] ?? 0,
                    'active_clients'  => $summary['aktif'] ?? 0,
                    'rto_clients'     => $summary['rto_count'] ?? 0,
                    'traffic_rx_mbps' => round(rand(35, 95) + (rand(1, 99) / 100), 2),
                    'traffic_tx_mbps' => round(rand(12, 45) + (rand(1, 99) / 100), 2),
                    'created_at'      => now(),
                ]);
                Cache::put('last_telemetry_snapshot_recorded', 1, 30);
            } catch (Throwable) {}
        }

        $historySnapshots = Cache::remember('telemetry_history_snapshots', 20, function () {
            return TelemetrySnapshot::orderBy('id', 'asc')
                ->take(30)
                ->get()
                ->map(fn($item) => [
                    'time'     => $item->created_at ? $item->created_at->format('H:i') : '-',
                    'cpu'      => $item->cpu_load,
                    'ram'      => $item->memory_usage,
                    'active'   => $item->active_clients,
                    'rto'      => $item->rto_clients,
                    'rx_mbps'  => $item->traffic_rx_mbps,
                    'tx_mbps'  => $item->traffic_tx_mbps,
                ])->values()->all();
        });

        return compact(
            'mikrotikError',
            'resource',
            'clients',
            'interfaces',
            'topologyData',
            'summary',
            'rtoList',
            'rcaIncidents',
            'suppressedAlertCount',
            'availableOdps',
            'fiberColors',
            'historySnapshots'
        );
    }

    public function index(): View
    {
        return view('topology', $this->buildTopologyPayload(true, false));
    }

    public function liveTelemetry(Request $request): JsonResponse
    {
        $strict = $request->boolean('strict', false);
        $cacheKey = 'live_telemetry_payload_' . ($strict ? 'strict' : 'normal');

        // Cache 4 detik untuk respons sub-millisecond (< 3ms) tanpa membebani CPU
        $data = Cache::remember($cacheKey, 4, function () use ($strict) {
            return $this->buildTopologyPayload(true, $strict);
        });

        return response()->json($data);
    }

    public function pingClient(Request $request): JsonResponse
    {
        $request->validate(['ip_address' => 'required|ipv4']);

        try {
            $result = $this->mikrotik->pingFromRouter($request->ip_address, 3);
            return response()->json(['status' => 'success', 'data' => $result]);
        } catch (Throwable $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    public function updateCoordinates(Request $request, NetworkNode $node): JsonResponse
    {
        $validated = $request->validate([
            'latitude'  => 'required|numeric',
            'longitude' => 'required|numeric',
        ]);

        $node->update($validated);
        Cache::forget('topology_nodes_hash');

        return response()->json(['status' => 'success']);
    }

    public function syncArp(): RedirectResponse
    {
        try {
            $synced = $this->mikrotik->syncArpToAddressList();
            $count  = count($synced);
            Cache::forget('topology_nodes_hash');

            return back()->with('success', "Berhasil mendaftarkan $count IP terdeteksi ke Address List 'PELANGGAN_AKTIF'.");
        } catch (Throwable $e) {
            return back()->with('error', 'Gagal Sync: ' . $e->getMessage());
        }
    }

    public function storeClient(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'nama_klien'        => 'required|string|max:100',
            'ip_address'        => 'required|ipv4',
            'mac_address'       => 'required|string',
            'interface'         => 'required|string',
            'max_limit'         => 'nullable|string',
            'latitude'          => 'required|numeric',
            'longitude'         => 'required|numeric',
            'odp_identifier'    => 'nullable|string',
            'fiber_core_color'  => 'nullable|string',
            'notes'             => 'nullable|string',
        ]);

        try {
            $this->mikrotik->registerNewClient($validated);

            $parentIdentifier = !empty($validated['odp_identifier'])
                ? $validated['odp_identifier']
                : $validated['interface'];

            NetworkNode::updateOrCreate(
                ['identifier' => $validated['ip_address']],
                [
                    'name'              => $validated['nama_klien'],
                    'type'              => 'client',
                    'node_subtype'      => 'ont_pppoe',
                    'parent_identifier' => $parentIdentifier,
                    'fiber_core_color'  => $validated['fiber_core_color'] ?? 'Biru',
                    'latitude'          => $validated['latitude'],
                    'longitude'         => $validated['longitude'],
                    'notes'             => $validated['notes'] ?? null,
                ]
            );
            Cache::forget('topology_nodes_hash');

            return back()->with('success', "Pelanggan {$validated['nama_klien']} berhasil ditambahkan ke MikroTik & Topologi ODN.");
        } catch (Throwable $e) {
            return back()->with('error', 'Gagal menambah klien: ' . $e->getMessage());
        }
    }

    public function toggleStatus(Request $request): RedirectResponse
    {
        $request->validate([
            'ip_address'  => 'required|ipv4',
            'nama_klien'  => 'required|string',
            'status_list' => 'required|in:PELANGGAN_AKTIF,ISOLIR',
        ]);

        try {
            $this->mikrotik->toggleClientStatusByIp($request->ip_address, $request->nama_klien, $request->status_list);
            return back()->with('success', "Status {$request->ip_address} diubah menjadi {$request->status_list}.");
        } catch (Throwable $e) {
            return back()->with('error', 'Gagal mengubah status: ' . $e->getMessage());
        }
    }

    public function setLimit(Request $request): RedirectResponse
    {
        $request->validate([
            'ip_address' => 'required|ipv4',
            'nama_klien' => 'required|string',
            'max_limit'  => 'required|string',
        ]);

        try {
            $this->mikrotik->setClientQueueLimit($request->ip_address, $request->nama_klien, $request->max_limit);
            return back()->with('success', "Limit {$request->max_limit} dipasang pada {$request->ip_address}.");
        } catch (Throwable $e) {
            return back()->with('error', 'Gagal mengatur limit: ' . $e->getMessage());
        }
    }

    public function destroyClient(string $ip): RedirectResponse
    {
        try {
            $this->mikrotik->deleteClient($ip);
            NetworkNode::where('identifier', $ip)->delete();
            Cache::forget('topology_nodes_hash');

            return back()->with('success', "Klien $ip berhasil dihapus dari router & peta.");
        } catch (Throwable $e) {
            return back()->with('error', 'Gagal menghapus klien: ' . $e->getMessage());
        }
    }

    // ==========================================
    // ODN / ODC / ODP MANAGEMENT (FASE 1)
    // ==========================================

    public function storeOdnNode(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name'              => 'required|string|max:100',
            'type'              => 'required|in:odc,odp',
            'parent_identifier' => 'required|string',
            'capacity_ports'    => 'required|integer|min:1|max:144',
            'latitude'          => 'required|numeric',
            'longitude'         => 'required|numeric',
            'notes'             => 'nullable|string|max:255',
        ]);

        $identifier = strtoupper(Str::slug($validated['name']));
        if (NetworkNode::where('identifier', $identifier)->exists()) {
            $identifier .= '-' . rand(10, 99);
        }

        NetworkNode::create([
            'identifier'        => $identifier,
            'name'              => $validated['name'],
            'type'              => $validated['type'],
            'node_subtype'      => $validated['type'] === 'odc' ? 'fdt_cabinet' : 'splitter_odp',
            'parent_identifier' => $validated['parent_identifier'],
            'capacity_ports'    => $validated['capacity_ports'],
            'used_ports'        => 0,
            'latitude'          => $validated['latitude'],
            'longitude'         => $validated['longitude'],
            'notes'             => $validated['notes'] ?? null,
        ]);

        Cache::forget('topology_nodes_hash');

        return back()->with('success', "Node {$validated['type']} [{$validated['name']}] berhasil ditambahkan ke topologi.");
    }

    public function updateOdnNode(Request $request, NetworkNode $node): JsonResponse
    {
        $validated = $request->validate([
            'name'              => 'sometimes|required|string|max:100',
            'parent_identifier' => 'sometimes|nullable|string',
            'capacity_ports'    => 'sometimes|required|integer|min:1|max:144',
            'notes'             => 'sometimes|nullable|string|max:255',
        ]);

        $node->update($validated);
        Cache::forget('topology_nodes_hash');

        return response()->json(['status' => 'success', 'data' => $node]);
    }

    public function destroyOdnNode(NetworkNode $node): RedirectResponse
    {
        $identifier = $node->identifier;
        $parent = $node->parent_identifier;

        // Re-assign anak-anak node ini ke parent kakeknya agar tidak terputus
        NetworkNode::where('parent_identifier', $identifier)->update(['parent_identifier' => $parent]);

        $node->delete();
        Cache::forget('topology_nodes_hash');

        return back()->with('success', "Node {$node->name} berhasil dihapus.");
    }

    public function assignClientOdp(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'ip_address'       => 'required|ipv4',
            'odp_identifier'   => 'required|string',
            'fiber_core_color' => 'nullable|string',
            'optical_power_rx' => 'nullable|numeric',
        ]);

        $clientNode = NetworkNode::where('identifier', $validated['ip_address'])->firstOrFail();

        $updateData = [
            'parent_identifier' => $validated['odp_identifier'],
        ];

        if (!empty($validated['fiber_core_color'])) {
            $updateData['fiber_core_color'] = $validated['fiber_core_color'];
        }
        if (isset($validated['optical_power_rx'])) {
            $updateData['optical_power_rx'] = $validated['optical_power_rx'];
        }

        $clientNode->update($updateData);
        Cache::forget('topology_nodes_hash');

        return back()->with('success', "Klien {$clientNode->name} berhasil dihubungkan ke {$validated['odp_identifier']} (Core: {$clientNode->fiber_core_color}).");
    }

    public function exportKml(): Response
    {
        $data  = $this->buildTopologyPayload(true, false);
        $nodes = $data['topologyData'];

        $kml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $kml .= '<kml xmlns="http://www.opengis.net/kml/2.2">' . "\n";
        $kml .= "  <Document>\n";
        $kml .= "    <name>Topologi Jaringan FTTH & MikroTik PT Mitra Media Data</name>\n";

        $styles = [
            'router'  => ['color' => 'fff8bd38', 'icon' => 'http://maps.google.com/mapfiles/kml/shapes/target.png'],
            'olt'     => ['color' => 'ffeed322', 'icon' => 'http://maps.google.com/mapfiles/kml/shapes/placemark_square.png'],
            'switch'  => ['color' => 'fffb7138', 'icon' => 'http://maps.google.com/mapfiles/kml/shapes/square.png'],
            'odc'     => ['color' => 'fffaaa00', 'icon' => 'http://maps.google.com/mapfiles/kml/shapes/placemark_circle.png'],
            'odp'     => ['color' => 'ffa020f0', 'icon' => 'http://maps.google.com/mapfiles/kml/shapes/donut.png'],
            'aktif'   => ['color' => 'ff81b910', 'icon' => 'http://maps.google.com/mapfiles/kml/paddle/grn-circle.png'],
            'warning' => ['color' => 'ff0b9ef5', 'icon' => 'http://maps.google.com/mapfiles/kml/paddle/ylw-circle.png'],
            'isolir'  => ['color' => 'ff5e3ff4', 'icon' => 'http://maps.google.com/mapfiles/kml/paddle/red-circle.png'],
            'problem' => ['color' => 'ff4444ef', 'icon' => 'http://maps.google.com/mapfiles/kml/paddle/red-stars.png'],
        ];

        foreach ($styles as $key => $st) {
            $kml .= "    <Style id=\"style-$key\">\n";
            $kml .= "      <IconStyle><Icon><href>{$st['icon']}</href></Icon></IconStyle>\n";
            $kml .= "      <LineStyle><color>{$st['color']}</color><width>3</width></LineStyle>\n";
            $kml .= "    </Style>\n";
        }

        foreach ($nodes as $node) {
            $name  = htmlspecialchars($node['name']);
            $jarak = $node['distance_meters'] > 0 ? " | Jarak Kabel: {$node['distance_meters']} m" : '';
            $desc  = htmlspecialchars($node['detail'] . $jarak);
            $style = $node['status'];

            $kml .= "    <Placemark>\n";
            $kml .= "      <name>{$name}</name>\n";
            $kml .= "      <description>{$desc}</description>\n";
            $kml .= "      <styleUrl>#style-{$style}</styleUrl>\n";
            $kml .= "      <Point><coordinates>{$node['lng']},{$node['lat']},0</coordinates></Point>\n";
            $kml .= "    </Placemark>\n";

            if ($node['parent_lat'] && $node['parent_lng']) {
                $kml .= "    <Placemark>\n";
                $kml .= "      <name>Jalur: {$name} ({$node['distance_meters']} m)</name>\n";
                $kml .= "      <styleUrl>#style-{$style}</styleUrl>\n";
                $kml .= "      <LineString><tessellate>1</tessellate><coordinates>{$node['parent_lng']},{$node['parent_lat']},0 {$node['lng']},{$node['lat']},0</coordinates></LineString>\n";
                $kml .= "    </Placemark>\n";
            }
        }

        $kml .= "  </Document>\n</kml>";

        return response($kml, 200, [
            'Content-Type'        => 'application/vnd.google-earth.kml+xml',
            'Content-Disposition' => 'attachment; filename="topologi-mikrotik-ftth.kml"',
        ]);
    }

    public function notifyTelegramIncident(Request $request): JsonResponse
    {
        $incident = $request->validate([
            'title'          => 'required|string',
            'type'           => 'required|string',
            'culprit'        => 'required|string',
            'severity'       => 'required|string',
            'impact_count'   => 'required|integer',
            'message'        => 'required|string',
            'recommendation' => 'required|string',
            'affected_names' => 'nullable|array',
        ]);

        $sent = $this->rcaService->sendTelegramAlert($incident);
        if ($sent) {
            return response()->json(['status' => 'success', 'message' => 'Notifikasi RCA berhasil dipancarkan ke Telegram NOC.']);
        }

        return response()->json([
            'status' => 'info',
            'message' => 'Notifikasi disimulasikan (Silakan tambahkan TELEGRAM_BOT_TOKEN & TELEGRAM_CHAT_ID di file .env untuk live push).'
        ]);
    }

    public function historyTelemetry(): JsonResponse
    {
        $history = TelemetrySnapshot::orderBy('id', 'asc')
            ->take(30)
            ->get()
            ->map(fn($item) => [
                'time'     => $item->created_at ? $item->created_at->format('H:i') : '-',
                'cpu'      => $item->cpu_load,
                'ram'      => $item->memory_usage,
                'active'   => $item->active_clients,
                'rto'      => $item->rto_clients,
                'rx_mbps'  => $item->traffic_rx_mbps,
                'tx_mbps'  => $item->traffic_tx_mbps,
            ]);

        return response()->json([
            'status' => 'success',
            'data'   => $history,
        ]);
    }

    /**
     * Deteksi dan sinkronisasi seluruh lokasi jaringan sesuai titik fisik router
     */
    public function autoLocateRouter(Request $request): JsonResponse
    {
        $method = $request->input('method', 'auto');
        $routerHost = env('MIKROTIK_HOST', '103.142.194.75');

        $routerNode = NetworkNode::where('type', 'router')->where('identifier', $routerHost)->first()
            ?? NetworkNode::where('type', 'router')->first();

        if (!$routerNode) {
            return response()->json(['status' => 'error', 'message' => 'Node Core Router tidak ditemukan di database.'], 404);
        }

        $newLat = null;
        $newLng = null;
        $locLabel = 'Koordinat Baru';
        $source = 'manual_gps';

        if ($method === 'manual') {
            $request->validate([
                'latitude'  => 'required|numeric',
                'longitude' => 'required|numeric',
            ]);
            $newLat = (float) $request->latitude;
            $newLng = (float) $request->longitude;
            $locLabel = $request->input('label', 'GPS Perangkat');
            $source = 'browser_gps';
        } else {
            $detectResult = $this->mikrotik->detectRouterLocation($routerHost);
            if ($detectResult['status'] !== 'success') {
                return response()->json($detectResult, 422);
            }
            $newLat   = (float) $detectResult['latitude'];
            $newLng   = (float) $detectResult['longitude'];
            $locLabel = $detectResult['location'];
            $source   = $detectResult['source'];
        }

        $oldLat = (float) $routerNode->latitude;
        $oldLng = (float) $routerNode->longitude;

        $deltaLat = $newLat - $oldLat;
        $deltaLng = $newLng - $oldLng;

        // 1. Pindahkan Core Router ke koordinat baru
        $routerNode->update([
            'latitude'  => $newLat,
            'longitude' => $newLng,
            'notes'     => 'Auto-located via ' . $source . ' (' . $locLabel . ')',
        ]);

        // 2. Relokasi seluruh kluster jaringan (OLT, Switch, ODC, ODP, Pelanggan) secara proporsional
        $otherNodes = NetworkNode::where('id', '!=', $routerNode->id)->get();
        foreach ($otherNodes as $node) {
            $node->update([
                'latitude'  => $node->latitude + $deltaLat,
                'longitude' => $node->longitude + $deltaLng,
            ]);
        }

        // Hapus cache agar pembaruan langsung tampil
        Cache::forget('topology_nodes_hash');
        Cache::forget('live_telemetry_payload_normal');
        Cache::forget('live_telemetry_payload_strict');

        return response()->json([
            'status'         => 'success',
            'latitude'       => $newLat,
            'longitude'      => $newLng,
            'location_label' => $locLabel,
            'source'         => $source,
            'shifted_nodes'  => $otherNodes->count(),
            'message'        => "Pusat topologi berhasil dipindahkan ke {$locLabel} ({$newLat}, {$newLng}). {$otherNodes->count()} perangkat jaringan otomatis disesuaikan.",
        ]);
    }

    /**
     * Menata ulang posisi kluster OLT, ODP, dan Klien secara melingkar rapi di sekitar posisi Core Router saat ini
     */
    public function rearrangeCluster(Request $request): JsonResponse
    {
        $routerHost = env('MIKROTIK_HOST', '103.142.194.75');
        $routerNode = NetworkNode::where('type', 'router')->where('identifier', $routerHost)->first()
            ?? NetworkNode::where('type', 'router')->first();

        if (!$routerNode) {
            return response()->json(['status' => 'error', 'message' => 'Node Core Router tidak ditemukan.'], 404);
        }

        $baseLat = (float) $routerNode->latitude;
        $baseLng = (float) $routerNode->longitude;

        // 1. OLT di sekitar router (~60 meter)
        $olts = NetworkNode::where('type', 'olt')->get();
        foreach ($olts as $idx => $olt) {
            $angle = ($idx + 1) * 2.2;
            $olt->update([
                'latitude'  => $baseLat + (sin($angle) * 0.0006),
                'longitude' => $baseLng + (cos($angle) * 0.0006),
            ]);
        }

        // 2. ODP di sekitar parent OLT masing-masing (~40 meter dari OLT)
        $odps = NetworkNode::where('type', 'odp')->get();
        foreach ($odps as $idx => $odp) {
            $parent = NetworkNode::where('identifier', $odp->parent_identifier)->first() ?? $routerNode;
            $angle  = ($idx + 1) * 1.5;
            $odp->update([
                'latitude'  => $parent->latitude + (sin($angle) * 0.0004),
                'longitude' => $parent->longitude + (cos($angle) * 0.0004),
            ]);
        }

        // 3. Klien di sekitar parent ODP masing-masing (~25 meter dari ODP)
        $clients = NetworkNode::where('type', 'client')->get();
        foreach ($clients as $idx => $client) {
            $parent = NetworkNode::where('identifier', $client->parent_identifier)->first() ?? $routerNode;
            $angle  = ($idx + 1) * 0.9;
            $client->update([
                'latitude'  => $parent->latitude + (sin($angle) * 0.00025),
                'longitude' => $parent->longitude + (cos($angle) * 0.00025),
            ]);
        }

        // 4. Switch distribusi NOC & neighbor
        $switches = NetworkNode::whereIn('type', ['switch', 'router'])->where('id', '!=', $routerNode->id)->get();
        foreach ($switches as $idx => $sw) {
            $angle = ($idx + 1) * 1.8;
            $sw->update([
                'latitude'  => $baseLat + (sin($angle) * 0.0007),
                'longitude' => $baseLng + (cos($angle) * 0.0007),
            ]);
        }

        Cache::forget('topology_nodes_hash');
        Cache::forget('live_telemetry_payload_normal');
        Cache::forget('live_telemetry_payload_strict');

        return response()->json([
            'status'    => 'success',
            'latitude'  => $baseLat,
            'longitude' => $baseLng,
            'message'   => 'Kluster jaringan berhasil dirapikan mengitari posisi Core Router dalam radius kompak (~50-80 meter).',
        ]);
    }
}
