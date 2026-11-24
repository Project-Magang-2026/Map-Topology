<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class RootCauseService
{
    /**
     * Analisis Akar Masalah (Root Cause Analysis - RCA Engine)
     */
    public function analyze(Collection $nodes, array $clients, array $routerInterfaces, ?string $mikrotikError = null): array
    {
        $nodeMap = $nodes->keyBy('identifier');
        $incidents = [];

        // KASUS 1: Router Core API / Uplink Down
        if ($mikrotikError !== null) {
            $router = $nodes->firstWhere('type', 'router');
            $affectedClients = collect($clients)->pluck('nama_klien')->all();

            $incidents[] = [
                'id'             => 'RCA-CORE-01',
                'type'           => 'ROUTER_CORE_DOWN',
                'severity'       => 'critical',
                'title'          => 'Core Router Uplink / API Terputus',
                'culprit'        => $router['identifier'] ?? 'Core Router',
                'culprit_type'   => 'router',
                'impact_count'   => count($clients),
                'affected_ips'   => collect($clients)->pluck('ip_address')->all(),
                'affected_names' => $affectedClients,
                'message'        => "Koneksi ke MikroTik terputus: {$mikrotikError}. Seluruh OLT dan pelanggan offline massal.",
                'recommendation' => 'Periksa catu daya router core, kabel uplink ISP utama, dan port service API MikroTik.',
            ];

            return [
                'incidents'    => $incidents,
                'total_impact' => count($clients),
                'suppressed'   => max(0, count($clients) - 1),
            ];
        }

        // KASUS 2: OLT Interface Down
        $downOlts = [];
        foreach ($routerInterfaces as $ifaceName => $info) {
            if (!$info['healthy']) {
                $downOlts[] = $ifaceName;

                // Cari semua anak di bawah OLT ini (langsung maupun lewat ODP)
                $affectedNodes = $this->getDownstreamDescendants($ifaceName, $nodeMap);
                $affectedClients = $affectedNodes->where('type', 'client');

                $incidents[] = [
                    'id'             => 'RCA-OLT-' . strtoupper($ifaceName),
                    'type'           => 'OLT_INTERFACE_DOWN',
                    'severity'       => 'critical',
                    'title'          => "Interface OLT {$ifaceName} DOWN",
                    'culprit'        => $ifaceName,
                    'culprit_type'   => 'olt',
                    'impact_count'   => $affectedClients->count(),
                    'affected_ips'   => $affectedClients->pluck('identifier')->all(),
                    'affected_names' => $affectedClients->pluck('name')->all(),
                    'message'        => $info['disabled']
                        ? "Port OLT {$ifaceName} dalam status DISABLED."
                        : "Link fisik/SFP OLT {$ifaceName} terputus (No-Running).",
                    'recommendation' => 'Periksa modul SFP OLT, patchcord ODF, atau catu daya OLT terkait.',
                ];
            }
        }

        // KASUS 3: Feeder ODP Cut (Semua klien di dalam 1 ODP RTO bersamaan)
        $problemClientIps = collect($clients)->where('has_problem', true)->pluck('ip_address')->all();
        $odpNodes = $nodes->where('type', 'odp');

        foreach ($odpNodes as $odp) {
            // Jika parent OLT-nya sudah down, ODP ini sudah tercover oleh RCA OLT (suppress)
            if (in_array($odp['parent_identifier'], $downOlts)) {
                continue;
            }

            // Dapatkan semua klien di bawah ODP ini
            $childrenClients = $nodes->where('parent_identifier', $odp['identifier'])->where('type', 'client');
            if ($childrenClients->isEmpty()) {
                continue;
            }

            $downCount = $childrenClients->filter(fn($c) => in_array($c['identifier'], $problemClientIps))->count();
            $totalInOdp = $childrenClients->count();

            // Jika >= 2 klien dan 100% klien di ODP ini mati bersamaan, indikasi kuat kabel feeder/distribusi putus!
            if ($downCount >= 2 && $downCount === $totalInOdp) {
                $incidents[] = [
                    'id'             => 'RCA-ODP-' . strtoupper($odp['identifier']),
                    'type'           => 'ODP_FEEDER_CUT',
                    'severity'       => 'critical',
                    'title'          => "Kabel Feeder ke {$odp['name']} Putus Total",
                    'culprit'        => $odp['identifier'],
                    'culprit_type'   => 'odp',
                    'impact_count'   => $downCount,
                    'affected_ips'   => $childrenClients->pluck('identifier')->all(),
                    'affected_names' => $childrenClients->pluck('name')->all(),
                    'message'        => "Seluruh ({$downCount}/{$totalInOdp}) pelanggan di {$odp['name']} mengalami RTO serentak.",
                    'recommendation' => "Gunakan OTDR dari ODC/OLT menuju {$odp['name']} ({$odp['notes']}). Kemungkinan kabel optik putus tertabrak truk atau terkena dahan pohon.",
                ];
            }
        }

        // KASUS 4: Klien Terisolir / Drop Cable Tunggal (Isolated Outage)
        // Ambil klien RTO yang belum tercover oleh insiden OLT atau ODP
        $coveredIps = collect($incidents)->pluck('affected_ips')->flatten()->unique()->all();
        $isolatedClients = collect($clients)->where('has_problem', true)->filter(fn($c) => !in_array($c['ip_address'], $coveredIps));

        foreach ($isolatedClients as $ic) {
            $isIsolir = strtoupper($ic['status_list']) === 'ISOLIR';
            $incidents[] = [
                'id'             => 'RCA-CLIENT-' . str_replace('.', '-', $ic['ip_address']),
                'type'           => $isIsolir ? 'CLIENT_ISOLIR' : 'CLIENT_DROP_FAULT',
                'severity'       => $isIsolir ? 'warning' : 'warning',
                'title'          => $isIsolir ? "Pelanggan {$ic['nama_klien']} Terisolir" : "Drop Cable / ONT {$ic['nama_klien']} RTO",
                'culprit'        => $ic['ip_address'],
                'culprit_type'   => 'client',
                'impact_count'   => 1,
                'affected_ips'   => [$ic['ip_address']],
                'affected_names' => [$ic['nama_klien']],
                'message'        => $isIsolir
                    ? "Status pelanggan berada di Address List ISOLIR (Tagihan menunggak)."
                    : "Gangguan lokal pada rumah pelanggan atau kabel drop core tertekuk.",
                'recommendation' => $isIsolir
                    ? 'Buka isolir setelah konfirmasi pembayaran dari bagian billing.'
                    : "Periksa adaptor modem ONT pelanggan atau redaman di roset dinding (Target: >= -24 dBm).",
            ];
        }

        $totalImpact = collect($incidents)->sum('impact_count');
        $suppressedAlerts = max(0, count($problemClientIps) - count($incidents));

        return [
            'incidents'    => $incidents,
            'total_impact' => $totalImpact,
            'suppressed'   => $suppressedAlerts,
        ];
    }

    /**
     * Dapatkan semua node keturunan secara rekursif
     */
    private function getDownstreamDescendants(string $parentIdentifier, Collection $nodeMap): Collection
    {
        $descendants = collect();
        $directChildren = $nodeMap->where('parent_identifier', $parentIdentifier);

        foreach ($directChildren as $child) {
            $descendants->push($child);
            $subChildren = $this->getDownstreamDescendants($child['identifier'], $nodeMap);
            $descendants = $descendants->merge($subChildren);
        }

        return $descendants;
    }

    /**
     * Kirim Notifikasi Insiden RCA ke Bot Telegram
     */
    public function sendTelegramAlert(array $incident): bool
    {
        $botToken = env('TELEGRAM_BOT_TOKEN');
        $chatId   = env('TELEGRAM_CHAT_ID');

        if (empty($botToken) || empty($chatId)) {
            Log::info("Telegram alert di-skip: TELEGRAM_BOT_TOKEN atau TELEGRAM_CHAT_ID belum diset di .env", $incident);
            return false;
        }

        $icon = $incident['severity'] === 'critical' ? '🚨' : '⚠️';
        $affectedList = implode(', ', array_slice($incident['affected_names'] ?? [], 0, 5));
        if (count($incident['affected_names'] ?? []) > 5) {
            $affectedList .= ' dan ' . (count($incident['affected_names']) - 5) . ' lainnya';
        }

        $text  = "{$icon} *[NOC ROOT CAUSE ALERT]*\n\n";
        $text .= "*Insiden:* {$incident['title']}\n";
        $text .= "*Tipe:* `{$incident['type']}`\n";
        $text .= "*Penyebab Utama (Culprit):* `{$incident['culprit']}`\n";
        $text .= "*Dampak:* {$incident['impact_count']} Klien ({$affectedList})\n";
        $text .= "*Deskripsi:* {$incident['message']}\n\n";
        $text .= "💡 *SOP Teknisi:* {$incident['recommendation']}\n";
        $text .= "🕒 _Waktu Deteksi: " . date('Y-m-d H:i:s') . " WIB_";

        try {
            $response = Http::timeout(5)->post("https://api.telegram.org/bot{$botToken}/sendMessage", [
                'chat_id'    => $chatId,
                'text'       => $text,
                'parse_mode' => 'Markdown',
            ]);

            return $response->successful();
        } catch (Throwable $e) {
            Log::error("Gagal kirim notifikasi Telegram RCA: " . $e->getMessage());
            return false;
        }
    }
}
