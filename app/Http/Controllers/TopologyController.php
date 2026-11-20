<?php

namespace App\Http\Controllers;

use App\Models\NetworkNode;
use App\Services\MikrotikService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;
use Throwable;

class TopologyController extends Controller
{
    public function __construct(protected MikrotikService $mikrotik)
    {
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

    private function autoDiscoverNodes(array $resource, array $clients, array $routerInterfaces): void
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
                    'parent_identifier' => $routerHost,
                    'latitude'          => $router->latitude + (sin($angle) * 0.0014),
                    'longitude'         => $router->longitude + (cos($angle) * 0.0014),
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

        $oltMap = NetworkNode::where('type', 'olt')->get()->keyBy('identifier');

        foreach ($clients as $i => $c) {
            $ip        = $c['ip_address'];
            $parentOlt = $oltMap->get($c['interface']) ?? $router;
            $angle     = ($i + 1) * 0.9;

            $node = NetworkNode::firstOrCreate(
                ['identifier' => $ip],
                [
                    'name'              => $c['nama_klien'],
                    'type'              => 'client',
                    'parent_identifier' => $parentOlt->identifier,
                    'latitude'          => $parentOlt->latitude + (sin($angle) * 0.0011),
                    'longitude'         => $parentOlt->longitude + (cos($angle) * 0.0011),
                ]
            );

            if ($node->name !== $c['nama_klien'] || $node->parent_identifier !== $parentOlt->identifier) {
                $node->update([
                    'name'              => $c['nama_klien'],
                    'parent_identifier' => $parentOlt->identifier,
                ]);
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

        try {
            $resource         = $this->mikrotik->getRouterResource();
            $clients          = $this->mikrotik->getIntegratedClients();
            $routerInterfaces = $this->mikrotik->getRouterInterfaces();

            $this->autoDiscoverNodes($resource, $clients, $routerInterfaces);

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

        $topologyData = $nodes->map(function ($node) use ($clientMap, $nodes, &$totalCableMeters, $mikrotikError, $routerInterfaces) {
            $status     = $node->type;
            $detail     = strtoupper($node->type);
            $mtData     = null;
            $isFlowing  = false;
            $hasProblem = false;

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
            } elseif ($node->type === 'client') {
                $mt     = $clientMap->get($node->identifier);
                $mtData = $mt;

                $parentOlt = $node->parent_identifier ? ($routerInterfaces[$node->parent_identifier] ?? null) : null;
                $oltDown   = $parentOlt !== null && !$parentOlt['healthy'];

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

            return [
                'id'                => $node->id,
                'identifier'        => $node->identifier,
                'name'              => $node->name,
                'type'              => $node->type,
                'parent_identifier' => $node->parent_identifier,
                'lat'               => (float) $node->latitude,
                'lng'               => (float) $node->longitude,
                'parent_lat'        => $parent ? (float) $parent->latitude : null,
                'parent_lng'        => $parent ? (float) $parent->longitude : null,
                'distance_meters'   => $distanceMeters,
                'status'            => $status,
                'has_problem'       => $hasProblem,
                'is_flowing'        => $isFlowing,
                'detail'            => $detail,
                'mikrotik'          => $mtData,
            ];
        })->values();

        $interfaces = !empty($routerInterfaces) ? array_keys($routerInterfaces) : ['bridge-olt1', 'bridge-olt2'];
        $elapsedMs  = round((microtime(true) - $startedAt) * 1000);

        $summary = [
            'total'       => count($clients),
            'aktif'       => count(array_filter($clients, fn($c) => !$c['has_problem'])),
            'problem'     => $topologyData->where('has_problem', true)->count(),
            'rto_count'   => count($rtoList),
            'isolir'      => count(array_filter($clients, fn($c) => strtoupper($c['status_list']) === 'ISOLIR')),
            'tanpa_limit' => count(array_filter($clients, fn($c) => !$c['has_queue'] && !$c['has_problem'])),
            'total_cable' => $totalCableMeters >= 1000
                ? round($totalCableMeters / 1000, 2) . ' km'
                : $totalCableMeters . ' m',
            'latency_ms'  => $elapsedMs,
        ];

        return compact('mikrotikError', 'resource', 'clients', 'interfaces', 'topologyData', 'summary', 'rtoList');
    }

    public function index(): View
    {
        return view('topology', $this->buildTopologyPayload(true, false));
    }

    public function liveTelemetry(Request $request): JsonResponse
    {
        $strict = $request->boolean('strict', false);
        return response()->json($this->buildTopologyPayload(true, $strict));
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
            'nama_klien'  => 'required|string|max:100',
            'ip_address'  => 'required|ipv4',
            'mac_address' => 'required|string',
            'interface'   => 'required|string',
            'max_limit'   => 'nullable|string',
            'latitude'    => 'required|numeric',
            'longitude'   => 'required|numeric',
        ]);

        try {
            $this->mikrotik->registerNewClient($validated);

            NetworkNode::updateOrCreate(
                ['identifier' => $validated['ip_address']],
                [
                    'name'              => $validated['nama_klien'],
                    'type'              => 'client',
                    'parent_identifier' => $validated['interface'],
                    'latitude'          => $validated['latitude'],
                    'longitude'         => $validated['longitude'],
                ]
            );
            Cache::forget('topology_nodes_hash');

            return back()->with('success', "Pelanggan {$validated['nama_klien']} berhasil ditambahkan ke MikroTik & Peta.");
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

    public function exportKml(): Response
    {
        $data  = $this->buildTopologyPayload(true, false);
        $nodes = $data['topologyData'];

        $kml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $kml .= '<kml xmlns="http://www.opengis.net/kml/2.2">' . "\n";
        $kml .= "  <Document>\n";
        $kml .= "    <name>Topologi Jaringan PT Mitra Media Data (103.142.194.75)</name>\n";

        $styles = [
            'router'  => ['color' => 'fff8bd38', 'icon' => 'http://maps.google.com/mapfiles/kml/shapes/target.png'],
            'olt'     => ['color' => 'ffeed322', 'icon' => 'http://maps.google.com/mapfiles/kml/shapes/placemark_square.png'],
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
            'Content-Disposition' => 'attachment; filename="topologi-mikrotik-mmd.kml"',
        ]);
    }
}