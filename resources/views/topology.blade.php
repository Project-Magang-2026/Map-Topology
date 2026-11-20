<!DOCTYPE html>
<html lang="id" class="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>GIS Network Command Center — PT Mitra Media Data</title>

    <!-- Fonts: Plus Jakarta Sans & JetBrains Mono -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;500;600;700&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">

    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    fontFamily: {
                        sans: ['"Plus Jakarta Sans"', 'system-ui', 'sans-serif'],
                        mono: ['"JetBrains Mono"', 'monospace'],
                    }
                }
            }
        }
    </script>

    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>

    <style>
        html, body {
            height: 100%;
            margin: 0;
            overflow: hidden;
            background-color: #060913;
            font-family: 'Plus Jakarta Sans', system-ui, sans-serif;
        }

        #map {
            position: fixed;
            inset: 0;
            width: 100vw;
            height: 100vh;
            z-index: 1;
            background: #060913;
        }

        /* Custom Scrollbar */
        ::-webkit-scrollbar { width: 5px; height: 5px; }
        ::-webkit-scrollbar-track { background: rgba(15, 23, 42, 0.3); }
        ::-webkit-scrollbar-thumb { background: rgba(100, 116, 139, 0.4); border-radius: 9999px; }
        ::-webkit-scrollbar-thumb:hover { background: rgba(148, 163, 184, 0.6); }

        /* Glass Surface */
        .glass-panel {
            background: rgba(10, 15, 28, 0.82);
            backdrop-filter: blur(18px);
            -webkit-backdrop-filter: blur(18px);
            border: 1px solid rgba(255, 255, 255, 0.09);
            box-shadow: 0 20px 40px -15px rgba(0, 0, 0, 0.75), inset 0 1px 0 0 rgba(255, 255, 255, 0.06);
        }

        .glass-card {
            background: rgba(15, 23, 42, 0.65);
            border: 1px solid rgba(255, 255, 255, 0.07);
            transition: all 0.2s cubic-bezier(0.16, 1, 0.3, 1);
        }
        .glass-card:hover {
            background: rgba(20, 30, 52, 0.85);
            border-color: rgba(56, 189, 248, 0.3);
        }

        /* Leaflet Dark Theme Overrides */
        .leaflet-popup-content-wrapper, .leaflet-popup-tip {
            background: rgba(10, 15, 28, 0.94) !important;
            backdrop-filter: blur(16px);
            color: #f1f5f9 !important;
            border: 1px solid rgba(255, 255, 255, 0.12);
            border-radius: 14px !important;
            box-shadow: 0 20px 35px rgba(0, 0, 0, 0.8) !important;
        }
        .leaflet-popup-content { margin: 12px 14px !important; }
        .leaflet-popup-close-button { color: #94a3b8 !important; top: 8px !important; right: 10px !important; }
        .leaflet-popup-close-button:hover { color: #ffffff !important; }

        .leaflet-control-zoom, .leaflet-control-layers {
            border: 1px solid rgba(255, 255, 255, 0.1) !important;
            border-radius: 12px !important;
            overflow: hidden;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.6) !important;
        }
        .leaflet-control-zoom a, .leaflet-control-layers-toggle {
            background-color: rgba(10, 15, 28, 0.85) !important;
            backdrop-filter: blur(12px);
            color: #e2e8f0 !important;
            border-bottom: 1px solid rgba(255, 255, 255, 0.08) !important;
        }
        .leaflet-control-zoom a:hover {
            background-color: rgba(30, 41, 59, 0.95) !important;
            color: #38bdf8 !important;
        }
        .leaflet-control-layers-expanded {
            background: rgba(10, 15, 28, 0.92) !important;
            backdrop-filter: blur(16px);
            color: #e2e8f0 !important;
            padding: 10px 14px !important;
            font-size: 12px;
        }
        .leaflet-control-attribution {
            background: rgba(6, 9, 19, 0.7) !important;
            color: #64748b !important;
            font-size: 10px;
        }

        /* Cable & Node Tooltips */
        .cable-tooltip {
            background: rgba(10, 15, 28, 0.88);
            backdrop-filter: blur(6px);
            border: 1px solid rgba(56, 189, 248, 0.25);
            color: #bae6fd;
            font-family: 'JetBrains Mono', monospace;
            font-size: 10px;
            font-weight: 500;
            padding: 2px 6px;
            border-radius: 6px;
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.5);
        }
        .cable-tooltip-problem {
            background: rgba(127, 29, 29, 0.95);
            border: 1px solid #f87171;
            color: #fee2e2;
            font-family: 'JetBrains Mono', monospace;
            font-size: 10px;
            font-weight: 700;
            padding: 2px 7px;
            border-radius: 6px;
            box-shadow: 0 0 14px rgba(239, 68, 68, 0.75);
        }
        .node-name-tooltip {
            background: rgba(10, 15, 28, 0.9) !important;
            backdrop-filter: blur(8px);
            border: 1px solid rgba(255, 255, 255, 0.14) !important;
            color: #f8fafc !important;
            font-family: 'Plus Jakarta Sans', sans-serif;
            font-size: 11px;
            font-weight: 600;
            padding: 2px 8px;
            border-radius: 6px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.65);
        }
        .node-name-tooltip::before { display: none !important; }

        @keyframes radar-pulse {
            0% { box-shadow: 0 0 0 0 rgba(239, 68, 68, 0.9); }
            70% { box-shadow: 0 0 0 16px rgba(239, 68, 68, 0); }
            100% { box-shadow: 0 0 0 0 rgba(239, 68, 68, 0); }
        }
        .pin-problem {
            animation: radar-pulse 1.25s infinite;
        }
    </style>
</head>
<body class="text-slate-100 antialiased selection:bg-cyan-500/30 selection:text-cyan-200">

    <!-- Full-Bleed Satellite Map -->
    <div id="map"></div>

    <!-- Top Floating Command Bar -->
    <header class="fixed top-3 left-3 right-3 z-30 glass-panel rounded-2xl px-4 py-2.5 flex flex-wrap items-center justify-between gap-3">
        <!-- Brand & Live Telemetry Metrics -->
        <div class="flex items-center gap-3.5">
            <div class="relative flex items-center justify-center w-10 h-10 rounded-xl bg-gradient-to-br from-cyan-500/20 to-blue-600/20 border border-cyan-400/30">
                <svg class="w-5 h-5 text-cyan-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M3.055 11H5a2 2 0 012 2v1a2 2 0 002 2 2 2 0 012 2v2.945M8 3.935V5.5A2.5 2.5 0 0010.5 8h.5a2 2 0 012 2 2 2 0 104 0 2 2 0 012-2h1.064M15 20.488V18a2 2 0 012-2h3.064M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
                <span id="status-dot" class="absolute -top-1 -right-1 w-3 h-3 rounded-full border-2 border-slate-950 {{ ($mikrotikError || $summary['problem'] > 0) ? 'bg-red-500 animate-ping' : 'bg-emerald-400' }}"></span>
            </div>

            <div>
                <div class="flex items-center gap-2">
                    <h1 class="text-sm font-bold tracking-tight text-white">PT Mitra Media Data <span class="text-slate-400 font-medium">• GIS NOC</span></h1>
                    <span class="hidden sm:inline-flex items-center gap-1 px-2 py-0.5 rounded-md bg-slate-800/90 border border-white/10 font-mono text-[11px] text-cyan-300">
                        103.142.194.75
                    </span>
                    <span id="alert-badge" class="{{ ($mikrotikError || $summary['problem'] > 0) ? '' : 'hidden' }} inline-flex items-center gap-1 bg-red-500/20 border border-red-500/60 text-red-300 text-[11px] font-bold px-2.5 py-0.5 rounded-full animate-pulse">
                        <span class="w-1.5 h-1.5 rounded-full bg-red-400"></span>
                        RTO / PROBLEM (<span id="badge-problem-count">{{ $summary['problem'] }}</span>)
                    </span>
                </div>

                <!-- Compact Telemetry Pills -->
                <div class="flex flex-wrap items-center gap-x-3 gap-y-1 text-[11px] text-slate-400 mt-0.5">
                    <span class="inline-flex items-center gap-1">
                        CPU <strong id="stat-cpu" class="text-slate-200 font-mono">{{ $resource['cpu_load'] }}%</strong>
                    </span>
                    <span class="text-slate-700">•</span>
                    <span class="inline-flex items-center gap-1">
                        RAM <strong id="stat-ram" class="text-slate-200 font-mono">{{ $resource['memory_usage'] }}%</strong>
                    </span>
                    <span class="text-slate-700">•</span>
                    <span class="inline-flex items-center gap-1">
                        Online <strong id="stat-aktif" class="text-emerald-400 font-mono font-semibold">{{ $summary['aktif'] }}</strong>
                    </span>
                    <span class="text-slate-700">•</span>
                    <span class="inline-flex items-center gap-1">
                        Problem <strong id="stat-problem" class="text-red-400 font-mono font-bold">{{ $summary['problem'] }}</strong>
                    </span>
                    <span class="text-slate-700">•</span>
                    <span class="inline-flex items-center gap-1">
                        Kabel <strong id="stat-cable" class="text-sky-400 font-mono font-semibold">{{ $summary['total_cable'] }}</strong>
                    </span>
                </div>
            </div>
        </div>

        <!-- Action Controls -->
        <div class="flex items-center gap-2">
            <button id="btn-mode" onclick="toggleDetectionMode()" class="inline-flex items-center gap-1.5 bg-sky-500/15 hover:bg-sky-500/25 border border-sky-400/30 text-sky-300 text-xs font-semibold px-3 py-1.5 rounded-xl transition" title="Beralih antara Mode Auto Hybrid dan Mode Fisik Strict">
                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6V4m0 2a2 2 0 100 4m0-4a2 2 0 110 4m-6 8a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4m6 6v10m6-2a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4" /></svg>
                <span id="mode-label">Auto Hybrid</span>
            </button>

            <button id="btn-sound" onclick="toggleSoundAlarm()" class="inline-flex items-center gap-1.5 bg-slate-800/80 hover:bg-slate-700/80 border border-white/10 text-slate-200 text-xs font-semibold px-3 py-1.5 rounded-xl transition" title="Toggle Alarm Suara RTO">
                <span id="sound-icon">🔔</span>
                <span id="sound-label" class="hidden md:inline">Alarm ON</span>
            </button>

            <button id="btn-live" onclick="toggleLivePolling()" class="inline-flex items-center gap-1.5 bg-emerald-500/15 hover:bg-emerald-500/25 border border-emerald-400/40 text-emerald-300 text-xs font-semibold px-3 py-1.5 rounded-xl transition">
                <span class="w-2 h-2 rounded-full bg-emerald-400 animate-pulse"></span>
                <span>3s (<span id="stream-ms" class="font-mono">{{ $summary['latency_ms'] ?? 0 }}</span>ms)</span>
            </button>

            <a href="{{ route('topology.kml') }}" class="inline-flex items-center gap-1.5 bg-indigo-600/90 hover:bg-indigo-500 text-white text-xs font-semibold px-3 py-1.5 rounded-xl shadow-lg shadow-indigo-950/50 transition">
                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" /></svg>
                <span>.KML</span>
            </a>

            <button id="btn-sidebar" onclick="toggleSidebar()" class="inline-flex items-center gap-1.5 bg-slate-800/90 hover:bg-slate-700 border border-white/15 text-white text-xs font-semibold px-3.5 py-1.5 rounded-xl transition">
                <svg class="w-4 h-4 text-cyan-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h7" /></svg>
                <span>Panel NOC</span>
            </button>
        </div>
    </header>

    <!-- Floating Left Search & GPS Bar -->
    <div class="fixed top-20 left-3 z-20 flex items-center gap-1.5 glass-panel p-1.5 rounded-xl">
        <div class="relative flex items-center">
            <svg class="w-3.5 h-3.5 text-slate-400 absolute left-2.5 pointer-events-none" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" /></svg>
            <input type="text" id="map-search-input" placeholder="Cari wilayah, jalan, desa..." onkeydown="if(event.key==='Enter') searchLocation()" class="bg-slate-950/70 border border-white/10 rounded-lg pl-8 pr-3 py-1.5 text-xs text-white placeholder-slate-500 w-52 focus:outline-none focus:border-cyan-400 transition">
        </div>
        <button type="button" onclick="searchLocation()" class="bg-cyan-600 hover:bg-cyan-500 text-white text-xs font-semibold px-3 py-1.5 rounded-lg transition">
            Cari
        </button>
        <button type="button" onclick="locateMyGps()" class="bg-slate-800/90 hover:bg-slate-700 text-slate-200 text-xs font-medium px-2.5 py-1.5 rounded-lg border border-white/10 transition" title="Arahkan Peta ke Lokasi GPS Anda">
            📍 GPS
        </button>
    </div>

    <!-- Floating Bottom-Left Compact Legend -->
    <div class="fixed bottom-4 left-14 z-20 glass-panel px-3.5 py-2.5 rounded-xl text-[11px] space-y-1.5 max-w-xs">
        <div class="font-bold text-slate-300 uppercase tracking-wider text-[10px] mb-1">Status Topologi GIS</div>
        <div class="grid grid-cols-2 gap-x-4 gap-y-1">
            <div class="flex items-center gap-2"><span class="w-2.5 h-2.5 rounded-full bg-sky-400 shadow-[0_0_8px_#38bdf8]"></span> Core Router</div>
            <div class="flex items-center gap-2"><span class="w-2.5 h-2.5 rounded-full bg-cyan-400 shadow-[0_0_8px_#22d3ee]"></span> OLT / Bridge</div>
            <div class="flex items-center gap-2"><span class="w-2.5 h-2.5 rounded-full bg-emerald-400 shadow-[0_0_8px_#34d399]"></span> Online Normal</div>
            <div class="flex items-center gap-2"><span class="w-2.5 h-2.5 rounded-full bg-amber-400 shadow-[0_0_8px_#fbbf24]"></span> Tanpa Limit</div>
        </div>
        <div class="flex items-center gap-2 text-red-300 font-semibold pt-0.5 border-t border-white/10">
            <span class="w-2.5 h-2.5 rounded-full bg-red-500 animate-ping"></span>
            <span>RTO / Link Down / Isolir</span>
        </div>
    </div>

    <!-- Floating RTO Alert Panel (Positioned Cleanly Above Bottom-Left / Center-Left) -->
    <div id="rto-alert-panel" class="hidden fixed bottom-36 left-3 z-20 w-80 bg-red-950/90 backdrop-blur-xl border border-red-500/70 text-red-100 p-3 rounded-2xl text-xs shadow-[0_15px_35px_rgba(127,29,29,0.75)]">
        <div class="flex items-center justify-between mb-2">
            <span class="font-bold text-red-200 flex items-center gap-2 text-[11px] tracking-wide">
                <span class="w-2.5 h-2.5 rounded-full bg-red-500 animate-ping inline-block"></span>
                PERINGATAN RTO (<span id="rto-panel-count">0</span>)
            </span>
            <div class="flex items-center gap-2">
                <span id="rto-scan-time" class="text-[10px] text-red-300/80 font-mono"></span>
                <button type="button" onclick="document.getElementById('rto-alert-list').classList.toggle('hidden')" class="bg-red-900/90 hover:bg-red-800 px-2 py-0.5 rounded-md text-[10px] font-bold text-red-200 border border-red-500/40">
                    ±
                </button>
            </div>
        </div>
        <div id="rto-alert-list" class="space-y-1.5 text-[11px] max-h-40 overflow-y-auto pr-1"></div>
    </div>

    <!-- Floating Toast & Server Notifications -->
    <div class="fixed top-20 left-1/2 -translate-x-1/2 z-30 space-y-2 w-full max-w-md px-4 pointer-events-none">
        @if($mikrotikError)
            <div class="bg-red-950/95 backdrop-blur-xl border border-red-500 text-red-200 px-4 py-2.5 rounded-xl text-xs shadow-2xl pointer-events-auto">
                <strong>MikroTik API Error:</strong> {{ $mikrotikError }}
            </div>
        @endif
        @if(session('success'))
            <div class="bg-emerald-950/95 backdrop-blur-xl border border-emerald-500 text-emerald-200 px-4 py-2.5 rounded-xl text-xs shadow-2xl pointer-events-auto">
                {{ session('success') }}
            </div>
        @endif
        @if(session('error'))
            <div class="bg-red-950/95 backdrop-blur-xl border border-red-500 text-red-200 px-4 py-2.5 rounded-xl text-xs shadow-2xl pointer-events-auto">
                {{ session('error') }}
            </div>
        @endif
        <div id="toast-box" class="hidden glass-panel border-cyan-400/50 text-cyan-200 px-4 py-2 rounded-xl text-xs text-center shadow-2xl"></div>
    </div>

    <!-- Floating Right NOC Command Drawer -->
    <aside id="sidebar" class="fixed top-20 right-3 bottom-3 w-[390px] max-w-[calc(100vw-24px)] glass-panel rounded-2xl z-20 flex flex-col overflow-hidden transition-all duration-300">

        <!-- Router Hardware Telemetry Strip -->
        <div class="p-3.5 border-b border-white/10 bg-slate-950/40">
            <div class="flex items-center justify-between text-xs mb-2">
                <div class="flex items-center gap-2">
                    <span class="px-2 py-0.5 rounded bg-cyan-500/15 border border-cyan-400/30 text-cyan-300 font-mono text-[10px] font-bold">CORE</span>
                    <span class="font-bold text-white truncate max-w-[170px]">{{ $resource['identity'] }}</span>
                </div>
                <span class="font-mono text-[10px] text-slate-400">v{{ $resource['version'] }} • Up {{ $resource['uptime'] }}</span>
            </div>

            <div class="grid grid-cols-2 gap-2.5 text-[11px]">
                <div class="bg-slate-900/80 border border-white/5 rounded-lg px-2.5 py-1.5">
                    <div class="flex justify-between text-slate-400 mb-1">
                        <span>CPU Load</span>
                        <span id="drawer-cpu" class="font-mono text-white font-semibold">{{ $resource['cpu_load'] }}%</span>
                    </div>
                    <div class="w-full h-1.5 bg-slate-800 rounded-full overflow-hidden">
                        <div id="bar-cpu" class="h-full bg-gradient-to-r from-cyan-400 to-blue-500 transition-all duration-500" style="width: {{ min(100, $resource['cpu_load']) }}%"></div>
                    </div>
                </div>
                <div class="bg-slate-900/80 border border-white/5 rounded-lg px-2.5 py-1.5">
                    <div class="flex justify-between text-slate-400 mb-1">
                        <span>Memory</span>
                        <span id="drawer-ram" class="font-mono text-white font-semibold">{{ $resource['memory_usage'] }}%</span>
                    </div>
                    <div class="w-full h-1.5 bg-slate-800 rounded-full overflow-hidden">
                        <div id="bar-ram" class="h-full bg-gradient-to-r from-emerald-400 to-teal-500 transition-all duration-500" style="width: {{ min(100, $resource['memory_usage']) }}%"></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Drawer Navigation Tabs -->
        <div class="grid grid-cols-2 gap-1 p-2 border-b border-white/10 bg-slate-950/30 text-xs font-semibold">
            <button type="button" id="tab-btn-monitor" onclick="switchDrawerTab('monitor')" class="py-1.5 px-3 rounded-xl bg-cyan-500/20 text-cyan-300 border border-cyan-400/30 transition">
                Monitoring (<span id="client-count-label">{{ count($clients) }}</span>)
            </button>
            <button type="button" id="tab-btn-register" onclick="switchDrawerTab('register')" class="py-1.5 px-3 rounded-xl text-slate-400 hover:text-white hover:bg-white/5 transition">
                + Registrasi Klien
            </button>
        </div>

        <!-- TAB 1: Live Device Monitoring -->
        <div id="tab-panel-monitor" class="flex-1 flex flex-col overflow-hidden">
            <!-- Search & Status Filter Bar -->
            <div class="p-3 border-b border-white/10 space-y-2 bg-slate-950/20">
                <div class="relative">
                    <svg class="w-3.5 h-3.5 text-slate-400 absolute left-3 top-1/2 -translate-y-1/2 pointer-events-none" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" /></svg>
                    <input type="text" id="client-filter-input" oninput="applyClientFilter()" placeholder="Filter nama pelanggan atau IP..." class="w-full bg-slate-950/80 border border-white/10 rounded-xl pl-8 pr-3 py-1.5 text-xs text-white placeholder-slate-500 focus:outline-none focus:border-cyan-400">
                </div>
                <div class="flex gap-1 text-[11px] font-medium">
                    <button type="button" data-filter="all" onclick="setStatusFilter('all')" class="filter-pill flex-1 py-1 rounded-lg bg-cyan-500/20 text-cyan-300 border border-cyan-400/30">Semua</button>
                    <button type="button" data-filter="online" onclick="setStatusFilter('online')" class="filter-pill flex-1 py-1 rounded-lg bg-slate-900/70 text-slate-400 border border-white/5 hover:text-white">Online</button>
                    <button type="button" data-filter="problem" onclick="setStatusFilter('problem')" class="filter-pill flex-1 py-1 rounded-lg bg-slate-900/70 text-slate-400 border border-white/5 hover:text-white">Problem</button>
                    <button type="button" data-filter="nolimit" onclick="setStatusFilter('nolimit')" class="filter-pill flex-1 py-1 rounded-lg bg-slate-900/70 text-slate-400 border border-white/5 hover:text-white">No Limit</button>
                </div>
            </div>

            <!-- Scrollable Client Cards -->
            <div id="client-list-container" class="flex-1 overflow-y-auto p-3 space-y-2.5"></div>
        </div>

        <!-- TAB 2: Register New Client Form -->
        <div id="tab-panel-register" class="hidden flex-1 overflow-y-auto p-4 space-y-4">
            <div class="bg-cyan-500/10 border border-cyan-400/25 rounded-xl p-3 text-[11px] text-cyan-200 leading-relaxed">
                <strong>Tips GIS:</strong> Klik langsung pada atap rumah pelanggan di peta satelit untuk mengisi koordinat <em>Latitude</em> & <em>Longitude</em> secara otomatis.
            </div>

            <form action="{{ route('mikrotik.store') }}" method="POST" class="space-y-3 text-xs">
                @csrf
                <div>
                    <label class="block text-[11px] font-semibold text-slate-300 mb-1">Nama Pelanggan / PPPoE</label>
                    <input type="text" name="nama_klien" placeholder="Contoh: Rumah_Pak_Hendra_20M" required class="w-full bg-slate-950/80 border border-white/10 rounded-xl px-3 py-2 text-white placeholder-slate-500 focus:outline-none focus:border-cyan-400">
                </div>

                <div class="grid grid-cols-2 gap-2.5">
                    <div>
                        <label class="block text-[11px] font-semibold text-slate-300 mb-1">IP Address</label>
                        <input type="text" name="ip_address" placeholder="10.10.1.30" required class="w-full bg-slate-950/80 border border-white/10 rounded-xl px-3 py-2 text-white font-mono placeholder-slate-500 focus:outline-none focus:border-cyan-400">
                    </div>
                    <div>
                        <label class="block text-[11px] font-semibold text-slate-300 mb-1">MAC Address</label>
                        <input type="text" name="mac_address" placeholder="02:00:00:00:01:30" required class="w-full bg-slate-950/80 border border-white/10 rounded-xl px-3 py-2 text-white font-mono placeholder-slate-500 focus:outline-none focus:border-cyan-400">
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-2.5">
                    <div>
                        <label class="block text-[11px] font-semibold text-slate-300 mb-1">Interface / OLT</label>
                        <select name="interface" class="w-full bg-slate-950/80 border border-white/10 rounded-xl px-2.5 py-2 text-white focus:outline-none focus:border-cyan-400">
                            @foreach($interfaces as $iface)
                                <option value="{{ $iface }}">{{ $iface }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block text-[11px] font-semibold text-slate-300 mb-1">Paket Bandwidth</label>
                        <select name="max_limit" class="w-full bg-slate-950/80 border border-white/10 rounded-xl px-2.5 py-2 text-white focus:outline-none focus:border-cyan-400">
                            <option value="10M/10M">10 Mbps (10M/10M)</option>
                            <option value="20M/20M">20 Mbps (20M/20M)</option>
                            <option value="50M/50M">50 Mbps (50M/50M)</option>
                            <option value="">Tanpa Limit</option>
                        </select>
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-2.5">
                    <div>
                        <label class="block text-[11px] font-semibold text-slate-300 mb-1">Latitude</label>
                        <input type="text" id="lat" name="latitude" value="-6.120800" required class="w-full bg-slate-950/80 border border-white/10 rounded-xl px-3 py-2 text-cyan-300 font-mono focus:outline-none focus:border-cyan-400">
                    </div>
                    <div>
                        <label class="block text-[11px] font-semibold text-slate-300 mb-1">Longitude</label>
                        <input type="text" id="lng" name="longitude" value="106.152500" required class="w-full bg-slate-950/80 border border-white/10 rounded-xl px-3 py-2 text-cyan-300 font-mono focus:outline-none focus:border-cyan-400">
                    </div>
                </div>

                <button type="submit" class="w-full bg-gradient-to-r from-emerald-500 to-teal-600 hover:from-emerald-400 hover:to-teal-500 text-white font-bold py-2.5 rounded-xl shadow-lg shadow-emerald-950/60 transition">
                    Simpan ke MikroTik & Peta GIS
                </button>
            </form>
        </div>
    </aside>

    <script>
        let nodes = @json($topologyData);
        let latestClients = @json($clients);
        const csrfToken = document.querySelector('meta[name="csrf-token"]').content;
        const limitRoute = '{{ route("mikrotik.limit") }}';
        const statusRoute = '{{ route("mikrotik.status") }}';
        const baseClientsUrl = '{{ url("/clients") }}';

        let isDragging = false;
        let livePollingEnabled = true;
        let soundAlarmEnabled = true;
        let strictPhysicalMode = false;
        let isScanning = false;
        let knownRtoIps = new Set();
        let lastClientListFingerprint = '';
        let currentStatusFilter = 'all';

        const googleHybrid = L.tileLayer('https://mt1.google.com/vt/lyrs=y&x={x}&y={y}&z={z}', {
            maxZoom: 21,
            attribution: 'Map data &copy; Google Satellite'
        });

        const googleSatOnly = L.tileLayer('https://mt1.google.com/vt/lyrs=s&x={x}&y={y}&z={z}', {
            maxZoom: 21,
            attribution: 'Map data &copy; Google Satellite'
        });

        const cartoDark = L.tileLayer('https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png', {
            maxZoom: 20,
            attribution: '&copy; OpenStreetMap &copy; CARTO'
        });

        const initialCenter = nodes.length > 0 ? [nodes[0].lat, nodes[0].lng] : [-6.1185, 106.1520];

        const map = L.map('map', {
            center: initialCenter,
            zoom: 16,
            zoomControl: false,
            layers: [googleHybrid]
        });

        L.control.zoom({ position: 'bottomleft' }).addTo(map);

        L.control.layers({
            'Google Earth Hybrid (Satelit + Jalan)': googleHybrid,
            'Google Earth Pure Satellite': googleSatOnly,
            'NOC Dark Vector Map': cartoDark
        }, null, { position: 'bottomleft' }).addTo(map);

        const colors = {
            router: '#38bdf8',
            olt: '#22d3ee',
            aktif: '#10b981',
            warning: '#f59e0b',
            isolir: '#ef4444',
            problem: '#ef4444'
        };

        const markerMap = {};
        const polylines = [];

        function escapeHtml(str) {
            return String(str ?? '')
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;');
        }

        function switchDrawerTab(tab) {
            const monitorPanel = document.getElementById('tab-panel-monitor');
            const registerPanel = document.getElementById('tab-panel-register');
            const btnMonitor = document.getElementById('tab-btn-monitor');
            const btnRegister = document.getElementById('tab-btn-register');

            if (tab === 'monitor') {
                monitorPanel.classList.remove('hidden');
                registerPanel.classList.add('hidden');
                btnMonitor.className = 'py-1.5 px-3 rounded-xl bg-cyan-500/20 text-cyan-300 border border-cyan-400/30 transition';
                btnRegister.className = 'py-1.5 px-3 rounded-xl text-slate-400 hover:text-white hover:bg-white/5 transition';
            } else {
                monitorPanel.classList.add('hidden');
                registerPanel.classList.remove('hidden');
                btnRegister.className = 'py-1.5 px-3 rounded-xl bg-cyan-500/20 text-cyan-300 border border-cyan-400/30 transition';
                btnMonitor.className = 'py-1.5 px-3 rounded-xl text-slate-400 hover:text-white hover:bg-white/5 transition';
            }
        }

        function setStatusFilter(filter) {
            currentStatusFilter = filter;
            document.querySelectorAll('.filter-pill').forEach(btn => {
                if (btn.getAttribute('data-filter') === filter) {
                    btn.className = 'filter-pill flex-1 py-1 rounded-lg bg-cyan-500/20 text-cyan-300 border border-cyan-400/30';
                } else {
                    btn.className = 'filter-pill flex-1 py-1 rounded-lg bg-slate-900/70 text-slate-400 border border-white/5 hover:text-white';
                }
            });
            lastClientListFingerprint = '';
            renderSidebarClients(latestClients);
        }

        function applyClientFilter() {
            lastClientListFingerprint = '';
            renderSidebarClients(latestClients);
        }

        function playRtoBeep() {
            if (!soundAlarmEnabled) return;
            try {
                const ctx = new (window.AudioContext || window.webkitAudioContext)();
                const osc = ctx.createOscillator();
                const gain = ctx.createGain();
                osc.type = 'sine';
                osc.frequency.setValueAtTime(880, ctx.currentTime);
                gain.gain.setValueAtTime(0.12, ctx.currentTime);
                gain.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + 0.35);
                osc.connect(gain);
                gain.connect(ctx.destination);
                osc.start();
                osc.stop(ctx.currentTime + 0.35);
            } catch (e) {}
        }

        function showToast(msg, isAlert = false) {
            const box = document.getElementById('toast-box');
            box.textContent = msg;
            box.className = isAlert
                ? 'bg-red-950/95 backdrop-blur-xl border border-red-500 text-red-100 font-bold px-4 py-2.5 rounded-xl text-xs text-center shadow-2xl'
                : 'glass-panel border-cyan-400/50 text-cyan-200 px-4 py-2.5 rounded-xl text-xs text-center shadow-2xl';
            box.classList.remove('hidden');
            setTimeout(() => box.classList.add('hidden'), 3000);
        }

        function renderSidebarClients(clients) {
            latestClients = clients || [];
            const container = document.getElementById('client-list-container');
            document.getElementById('client-count-label').textContent = latestClients.length;

            const keyword = (document.getElementById('client-filter-input')?.value || '').trim().toLowerCase();

            const filtered = latestClients.filter(c => {
                const matchesSearch = !keyword
                    || String(c.nama_klien).toLowerCase().includes(keyword)
                    || String(c.ip_address).toLowerCase().includes(keyword)
                    || String(c.interface).toLowerCase().includes(keyword);

                if (!matchesSearch) return false;
                if (currentStatusFilter === 'online') return !c.has_problem;
                if (currentStatusFilter === 'problem') return Boolean(c.has_problem);
                if (currentStatusFilter === 'nolimit') return !c.has_queue && !c.has_problem;
                return true;
            });

            if (filtered.length === 0) {
                container.innerHTML = `
                    <div class="text-xs text-slate-400 text-center py-8 border border-dashed border-white/10 rounded-2xl bg-slate-950/30">
                        ${latestClients.length === 0
                            ? 'Belum ada perangkat pelanggan terdeteksi di MikroTik.'
                            : 'Tidak ada perangkat yang cocok dengan filter pencarian.'}
                    </div>`;
                lastClientListFingerprint = 'empty';
                return;
            }

            const fingerprint = filtered.map(c => `${c.ip_address}|${c.nama_klien}|${c.interface}|${c.status_list}|${c.has_queue}|${c.max_limit}`).join(';');
            if (fingerprint === lastClientListFingerprint) {
                filtered.forEach(c => {
                    const slug = c.ip_address.replace(/\./g, '-');
                    const rateEl = document.getElementById('rate-' + slug);
                    const latEl = document.getElementById('lat-' + slug);
                    const cardEl = document.getElementById('card-' + slug);
                    const badgeEl = document.getElementById('badge-' + slug);
                    const dotEl = document.getElementById('dot-' + slug);

                    if (rateEl) rateEl.textContent = c.current_rate;
                    if (latEl) {
                        latEl.textContent = c.latency;
                        latEl.className = c.is_online ? 'text-emerald-300 font-mono' : 'text-red-400 font-mono font-bold';
                    }
                    if (cardEl && badgeEl) {
                        if (c.has_problem) {
                            cardEl.className = 'bg-red-950/35 border border-red-500/70 p-3 rounded-xl text-xs space-y-2.5 transition';
                            badgeEl.className = 'px-2 py-0.5 rounded-full text-[10px] font-bold shrink-0 bg-red-500/20 text-red-300 border border-red-500/50';
                            badgeEl.textContent = c.problem_msg;
                            if (dotEl) dotEl.className = 'w-2 h-2 rounded-full bg-red-500 animate-ping inline-block';
                        } else {
                            cardEl.className = 'glass-card p-3 rounded-xl text-xs space-y-2.5';
                            badgeEl.className = 'px-2 py-0.5 rounded-full text-[10px] font-bold shrink-0 bg-emerald-500/15 text-emerald-300 border border-emerald-400/30';
                            badgeEl.textContent = 'ONLINE';
                            if (dotEl) dotEl.className = 'hidden';
                        }
                    }
                });
                return;
            }

            lastClientListFingerprint = fingerprint;

            container.innerHTML = filtered.map(c => {
                const isAktif = String(c.status_list).toUpperCase() !== 'ISOLIR';
                const hasProblem = Boolean(c.has_problem);
                const slug = c.ip_address.replace(/\./g, '-');

                const limitForm = !c.has_queue ? `
                    <form action="${limitRoute}" method="POST" class="flex items-center gap-1.5">
                        <input type="hidden" name="_token" value="${csrfToken}">
                        <input type="hidden" name="ip_address" value="${escapeHtml(c.ip_address)}">
                        <input type="hidden" name="nama_klien" value="${escapeHtml(c.nama_klien)}">
                        <select name="max_limit" class="bg-amber-950/80 border border-amber-500/50 text-amber-200 rounded-lg px-2 py-1 text-[11px] flex-1 focus:outline-none">
                            <option value="10M/10M">Pasang 10 Mbps</option>
                            <option value="20M/20M">Pasang 20 Mbps</option>
                            <option value="50M/50M">Pasang 50 Mbps</option>
                        </select>
                        <button type="submit" class="bg-amber-500 hover:bg-amber-400 text-slate-950 px-2.5 py-1 rounded-lg text-[11px] font-bold transition">Set Limit</button>
                    </form>
                ` : '';

                return `
                    <div id="card-${slug}" class="${hasProblem ? 'bg-red-950/35 border border-red-500/70' : 'glass-card'} p-3 rounded-xl text-xs space-y-2.5">
                        <div class="flex justify-between items-start gap-2">
                            <div class="min-w-0">
                                <div class="font-bold text-white flex items-center gap-1.5 truncate">
                                    <span id="dot-${slug}" class="${hasProblem ? 'w-2 h-2 rounded-full bg-red-500 animate-ping inline-block shrink-0' : 'hidden'}"></span>
                                    <span class="truncate">${escapeHtml(c.nama_klien)}</span>
                                </div>
                                <div class="font-mono text-[11px] text-cyan-400 mt-0.5 flex flex-wrap items-center gap-1">
                                    <span>${escapeHtml(c.ip_address)}</span>
                                    <span class="text-slate-500">• ${escapeHtml(c.interface)}</span>
                                    <span class="text-[9px] bg-white/5 border border-white/10 text-slate-300 px-1.5 py-0.2 rounded">${escapeHtml(c.source)}</span>
                                </div>
                            </div>
                            <span id="badge-${slug}" class="px-2 py-0.5 rounded-full text-[10px] font-bold shrink-0 ${hasProblem ? 'bg-red-500/20 text-red-300 border border-red-500/50' : 'bg-emerald-500/15 text-emerald-300 border border-emerald-400/30'}">
                                ${hasProblem ? escapeHtml(c.problem_msg) : 'ONLINE'}
                            </span>
                        </div>

                        <div class="grid grid-cols-3 gap-1 text-[10px] bg-slate-950/70 px-2.5 py-1.5 rounded-lg border border-white/5">
                            <div>
                                <div class="text-slate-500">Limit</div>
                                <div class="text-slate-200 font-mono font-semibold truncate">${escapeHtml(c.max_limit)}</div>
                            </div>
                            <div>
                                <div class="text-slate-500">Status</div>
                                <div id="lat-${slug}" class="${c.is_online ? 'text-emerald-300' : 'text-red-400 font-bold'} font-mono truncate">${escapeHtml(c.latency)}</div>
                            </div>
                            <div class="text-right">
                                <div class="text-slate-500">Trafik</div>
                                <div id="rate-${slug}" class="text-cyan-300 font-mono truncate">${escapeHtml(c.current_rate)}</div>
                            </div>
                        </div>

                        ${limitForm}

                        <div id="ping-res-${slug}" class="hidden text-[11px] font-mono px-2.5 py-1.5 rounded-lg bg-slate-950 border border-white/10"></div>

                        <div class="flex gap-1.5 pt-0.5">
                            <button type="button" onclick="focusNode('${escapeHtml(c.ip_address)}')" class="bg-slate-800/90 hover:bg-slate-700 text-slate-200 px-2.5 py-1.5 rounded-lg font-medium border border-white/5 transition">
                                Peta
                            </button>

                            <button type="button" onclick="pingClient('${escapeHtml(c.ip_address)}')" class="bg-sky-500/15 hover:bg-sky-500/25 text-sky-300 border border-sky-400/30 px-2.5 py-1.5 rounded-lg font-medium transition">
                                Ping
                            </button>

                            <form action="${statusRoute}" method="POST" class="flex-1">
                                <input type="hidden" name="_token" value="${csrfToken}">
                                <input type="hidden" name="_method" value="PATCH">
                                <input type="hidden" name="ip_address" value="${escapeHtml(c.ip_address)}">
                                <input type="hidden" name="nama_klien" value="${escapeHtml(c.nama_klien)}">
                                <input type="hidden" name="status_list" value="${isAktif ? 'ISOLIR' : 'PELANGGAN_AKTIF'}">
                                <button type="submit" class="w-full py-1.5 rounded-lg font-semibold transition ${isAktif ? 'bg-rose-500/15 text-rose-300 border border-rose-500/40 hover:bg-rose-600 hover:text-white' : 'bg-emerald-500/15 text-emerald-300 border border-emerald-500/40 hover:bg-emerald-600 hover:text-white'}">
                                    ${isAktif ? 'Isolir' : 'Buka Isolir'}
                                </button>
                            </form>

                            <form action="${baseClientsUrl}/${encodeURIComponent(c.ip_address)}" method="POST" onsubmit="return confirm('Hapus ${escapeHtml(c.ip_address)}?')">
                                <input type="hidden" name="_token" value="${csrfToken}">
                                <input type="hidden" name="_method" value="DELETE">
                                <button type="submit" class="bg-slate-800/90 hover:bg-red-600 text-slate-400 hover:text-white px-2.5 py-1.5 rounded-lg border border-white/5 transition" title="Hapus Klien">✕</button>
                            </form>
                        </div>
                    </div>
                `;
            }).join('');
        }

        function isProblemStatus(status) {
            return status === 'problem' || status === 'isolir';
        }

        function buildPopupHtml(node) {
            const isBad = isProblemStatus(node.status);
            const badgeBg = isBad ? 'background:rgba(239,68,68,0.2); color:#fca5a5; border:1px solid rgba(239,68,68,0.5);' : 'background:rgba(16,185,129,0.2); color:#6ee7b7; border:1px solid rgba(16,185,129,0.4);';
            const distText = node.distance_meters > 0
                ? `<div style="margin-top:6px; padding-top:6px; border-top:1px solid rgba(255,255,255,0.1); font-size:11px; color:#7dd3fc; font-family:'JetBrains Mono',monospace;">Jarak Tarikan Kabel: <strong>${node.distance_meters} m</strong></div>`
                : '';
            const pingBtn = node.type === 'client'
                ? `<button onclick="pingClient('${node.identifier}')" style="margin-top:8px; width:100%; background:linear-gradient(to right,#0284c7,#2563eb); color:white; border:none; padding:5px 10px; border-radius:8px; font-size:11px; font-weight:600; cursor:pointer;">Uji ICMP Ping Fisik</button>`
                : '';

            return `
                <div style="font-family:'Plus Jakarta Sans',sans-serif; min-width:205px;">
                    <div style="display:flex; align-items:center; justify-content:space-between; gap:8px; margin-bottom:4px;">
                        <strong style="font-size:13px; color:#ffffff;">${escapeHtml(node.name)}</strong>
                        <span style="font-size:9px; font-weight:700; padding:1px 6px; border-radius:999px; text-transform:uppercase; ${badgeBg}">${escapeHtml(node.type)}</span>
                    </div>
                    <div style="font-size:11px; font-weight:${isBad ? '700' : '400'}; color:${isBad ? '#fca5a5' : '#cbd5e1'};">${escapeHtml(node.detail)}</div>
                    ${distText}
                    ${pingBtn}
                </div>
            `;
        }

        function createMarkerIcon(node) {
            const color = colors[node.status] || '#ef4444';
            const isBad = isProblemStatus(node.status);
            const pulseClass = isBad ? 'pin-problem' : '';

            if (node.type === 'router') {
                return L.divIcon({
                    html: `
                        <div class="${pulseClass}" style="background:linear-gradient(135deg,#0284c7,#1d4ed8); width:32px; height:32px; border-radius:10px; border:2px solid #bae6fd; box-shadow:0 0 18px rgba(56,189,248,0.85); display:flex; align-items:center; justify-content:center;">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#ffffff" stroke-width="2.2">
                                <rect x="2" y="3" width="20" height="8" rx="2"/><rect x="2" y="13" width="20" height="8" rx="2"/><line x1="6" y1="7" x2="6.01" y2="7"/><line x1="6" y1="17" x2="6.01" y2="17"/>
                            </svg>
                        </div>`,
                    className: '',
                    iconSize: [32, 32],
                    iconAnchor: [16, 16]
                });
            }

            if (node.type === 'olt') {
                const bg = isBad ? 'linear-gradient(135deg,#dc2626,#991b1b)' : 'linear-gradient(135deg,#0891b2,#0e7490)';
                const border = isBad ? '#fecaca' : '#a5f3fc';
                return L.divIcon({
                    html: `
                        <div class="${pulseClass}" style="background:${bg}; width:26px; height:26px; border-radius:8px; border:2px solid ${border}; box-shadow:0 0 14px rgba(0,0,0,0.85); display:flex; align-items:center; justify-content:center;">
                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="#ffffff" stroke-width="2.4">
                                <circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/>
                            </svg>
                        </div>`,
                    className: '',
                    iconSize: [26, 26],
                    iconAnchor: [13, 13]
                });
            }

            const size = isBad ? 22 : 18;
            const half = size / 2;
            const innerIcon = isBad
                ? `<svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="#ffffff" stroke-width="3"><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>`
                : `<span style="width:6px; height:6px; border-radius:50%; background:#ffffff;"></span>`;

            return L.divIcon({
                html: `<div class="${pulseClass}" style="background:${color}; width:${size}px; height:${size}px; border-radius:50%; border:2.5px solid #ffffff; box-shadow:0 0 12px rgba(0,0,0,0.9); display:flex; align-items:center; justify-content:center;">${innerIcon}</div>`,
                className: '',
                iconSize: [size, size],
                iconAnchor: [half, half]
            });
        }

        function renderTopology() {
            polylines.forEach(line => map.removeLayer(line));
            polylines.length = 0;

            const activeIds = new Set(nodes.map(n => n.identifier));
            Object.keys(markerMap).forEach(id => {
                if (!activeIds.has(id)) {
                    map.removeLayer(markerMap[id]);
                    delete markerMap[id];
                }
            });

            nodes.forEach(node => {
                const isBad = isProblemStatus(node.status);
                const color = colors[node.status] || '#ef4444';

                if (node.parent_lat && node.parent_lng) {
                    const p1 = L.latLng(node.parent_lat, node.parent_lng);
                    const p2 = L.latLng(node.lat, node.lng);
                    const dist = Math.round(p1.distanceTo(p2));
                    node.distance_meters = dist;

                    const line = L.polyline([p1, p2], {
                        color: color,
                        weight: isBad ? 4 : (node.type === 'olt' ? 3.5 : 2.8),
                        opacity: 0.9,
                        dashArray: (isBad || node.status === 'warning') ? '7, 6' : null
                    }).addTo(map);

                    let cableLabel = `${dist} m`;
                    if (isBad) {
                        cableLabel = `${dist} m • RTO`;
                    } else if (node.is_flowing && node.mikrotik && node.mikrotik.current_rate) {
                        cableLabel = `${dist} m • ${node.mikrotik.current_rate}`;
                    }

                    line.bindTooltip(cableLabel, {
                        permanent: true,
                        direction: 'center',
                        className: isBad ? 'cable-tooltip-problem' : 'cable-tooltip'
                    });

                    polylines.push(line);
                }

                const customIcon = createMarkerIcon(node);

                if (!markerMap[node.identifier]) {
                    const marker = L.marker([node.lat, node.lng], {
                        icon: customIcon,
                        draggable: true
                    }).addTo(map);

                    marker.bindTooltip(node.name, {
                        permanent: true,
                        direction: 'top',
                        offset: [0, -12],
                        className: 'node-name-tooltip'
                    });

                    marker.bindPopup(buildPopupHtml(node));

                    marker.on('dragstart', () => { isDragging = true; });

                    marker.on('dragend', function(e) {
                        isDragging = false;
                        const pos = e.target.getLatLng();
                        node.lat = pos.lat;
                        node.lng = pos.lng;

                        nodes.forEach(n => {
                            if (n.parent_identifier === node.identifier) {
                                n.parent_lat = pos.lat;
                                n.parent_lng = pos.lng;
                            }
                        });
                        renderTopology();

                        fetch(`/nodes/${node.id}/coordinates`, {
                            method: 'PATCH',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-CSRF-TOKEN': csrfToken
                            },
                            body: JSON.stringify({ latitude: pos.lat, longitude: pos.lng })
                        }).then(() => {
                            showToast(`Koordinat ${node.name} diperbarui (${node.distance_meters} m).`);
                        });
                    });

                    markerMap[node.identifier] = marker;
                } else {
                    markerMap[node.identifier].setIcon(customIcon);
                    markerMap[node.identifier].setPopupContent(buildPopupHtml(node));
                    if (markerMap[node.identifier].getTooltip()) {
                        markerMap[node.identifier].setTooltipContent(node.name);
                    }
                }
            });
        }

        renderSidebarClients(latestClients);
        renderTopology();

        // Siklus Auto-Scan tiap 3 detik (3000 ms)
        function runTelemetryScan() {
            if (!livePollingEnabled) return;
            if (isDragging || isScanning) {
                setTimeout(runTelemetryScan, 500);
                return;
            }

            isScanning = true;
            const reqStart = performance.now();
            const url = `{{ route("topology.telemetry") }}?strict=${strictPhysicalMode ? 1 : 0}`;

            fetch(url)
                .then(r => r.json())
                .then(data => {
                    const roundTripMs = Math.round(performance.now() - reqStart);
                    const msEl = document.getElementById('stream-ms');
                    if (msEl) msEl.textContent = roundTripMs;

                    const hasAnyProblem = Boolean(data.mikrotikError) || (data.summary && data.summary.problem > 0);
                    const dot = document.getElementById('status-dot');
                    const alertBadge = document.getElementById('alert-badge');
                    const badgeProblemCount = document.getElementById('badge-problem-count');

                    if (hasAnyProblem) {
                        dot.className = 'absolute -top-1 -right-1 w-3 h-3 rounded-full border-2 border-slate-950 bg-red-500 animate-ping';
                        alertBadge.classList.remove('hidden');
                        if (badgeProblemCount) badgeProblemCount.textContent = data.summary ? data.summary.problem : 1;
                    } else {
                        dot.className = 'absolute -top-1 -right-1 w-3 h-3 rounded-full border-2 border-slate-950 bg-emerald-400';
                        alertBadge.classList.add('hidden');
                    }

                    if (data.mikrotikError) {
                        nodes.forEach(n => {
                            n.status = 'problem';
                            n.detail = 'PROBLEM: Router API Terputus!';
                        });
                        renderTopology();
                        return;
                    }

                    const cpu = data.resource.cpu_load;
                    const ram = data.resource.memory_usage;
                    document.getElementById('stat-cpu').textContent = cpu + '%';
                    document.getElementById('stat-ram').textContent = ram + '%';
                    document.getElementById('drawer-cpu').textContent = cpu + '%';
                    document.getElementById('drawer-ram').textContent = ram + '%';
                    document.getElementById('bar-cpu').style.width = Math.min(100, cpu) + '%';
                    document.getElementById('bar-ram').style.width = Math.min(100, ram) + '%';

                    document.getElementById('stat-aktif').textContent = data.summary.aktif;
                    document.getElementById('stat-problem').textContent = data.summary.problem;
                    document.getElementById('stat-cable').textContent = data.summary.total_cable;

                    const rtoPanel = document.getElementById('rto-alert-panel');
                    const rtoListEl = document.getElementById('rto-alert-list');
                    const rtoTimeEl = document.getElementById('rto-scan-time');
                    const rtoCountEl = document.getElementById('rto-panel-count');
                    const rtoItems = data.rtoList || [];

                    if (rtoItems.length > 0) {
                        rtoPanel.classList.remove('hidden');
                        rtoCountEl.textContent = rtoItems.length;
                        rtoTimeEl.textContent = new Date().toLocaleTimeString('id-ID');
                        rtoListEl.innerHTML = rtoItems.map(item => `
                            <div class="flex items-center justify-between bg-red-900/50 px-2.5 py-1.5 rounded-lg border border-red-500/40">
                                <span class="truncate pr-2"><strong>${escapeHtml(item.name)}</strong> <span class="font-mono text-red-300">(${escapeHtml(item.ip)})</span></span>
                                <button type="button" onclick="focusNode('${escapeHtml(item.ip)}')" class="bg-red-600 hover:bg-red-500 text-white text-[10px] font-bold px-2 py-0.5 rounded-md shrink-0 transition">
                                    Cek Peta
                                </button>
                            </div>
                        `).join('');

                        let hasNewRto = false;
                        const currentRtoSet = new Set();
                        rtoItems.forEach(item => {
                            currentRtoSet.add(item.ip);
                            if (!knownRtoIps.has(item.ip)) {
                                hasNewRto = true;
                            }
                        });
                        if (hasNewRto) {
                            playRtoBeep();
                            showToast(`ALERT RTO: ${rtoItems.length} perangkat terputus!`, true);
                        }
                        knownRtoIps = currentRtoSet;
                    } else {
                        rtoPanel.classList.add('hidden');
                        knownRtoIps.clear();
                    }

                    renderSidebarClients(data.clients);

                    const localPos = {};
                    nodes.forEach(n => { localPos[n.identifier] = { lat: n.lat, lng: n.lng }; });

                    nodes = data.topologyData.map(n => {
                        if (localPos[n.identifier]) {
                            n.lat = localPos[n.identifier].lat;
                            n.lng = localPos[n.identifier].lng;
                        }
                        return n;
                    });

                    renderTopology();
                })
                .catch(() => {})
                .finally(() => {
                    isScanning = false;
                    if (livePollingEnabled) {
                        setTimeout(runTelemetryScan, 3000);
                    }
                });
        }

        setTimeout(runTelemetryScan, 3000);

        function toggleDetectionMode() {
            strictPhysicalMode = !strictPhysicalMode;
            const btn = document.getElementById('btn-mode');
            const label = document.getElementById('mode-label');
            if (strictPhysicalMode) {
                label.textContent = 'Fisik Strict';
                btn.className = 'inline-flex items-center gap-1.5 bg-amber-500/20 hover:bg-amber-500/30 border border-amber-400/50 text-amber-200 text-xs font-semibold px-3 py-1.5 rounded-xl transition';
                showToast('Mode Fisik Strict: Menguji ICMP ping fisik tiap 3 detik.');
            } else {
                label.textContent = 'Auto Hybrid';
                btn.className = 'inline-flex items-center gap-1.5 bg-sky-500/15 hover:bg-sky-500/25 border border-sky-400/30 text-sky-300 text-xs font-semibold px-3 py-1.5 rounded-xl transition';
                showToast('Mode Auto Hybrid: Pembaruan otomatis setiap 3 detik.');
            }
        }

        function toggleLivePolling() {
            livePollingEnabled = !livePollingEnabled;
            const btn = document.getElementById('btn-live');
            if (livePollingEnabled) {
                btn.innerHTML = '<span class="w-2 h-2 rounded-full bg-emerald-400 animate-pulse"></span><span>3s (<span id="stream-ms" class="font-mono">0</span>ms)</span>';
                btn.className = 'inline-flex items-center gap-1.5 bg-emerald-500/15 hover:bg-emerald-500/25 border border-emerald-400/40 text-emerald-300 text-xs font-semibold px-3 py-1.5 rounded-xl transition';
                runTelemetryScan();
            } else {
                btn.innerHTML = '<span class="w-2 h-2 rounded-full bg-slate-500"></span><span>Paused</span>';
                btn.className = 'inline-flex items-center gap-1.5 bg-slate-800/80 border border-white/10 text-slate-400 text-xs font-semibold px-3 py-1.5 rounded-xl transition';
            }
        }

        function toggleSoundAlarm() {
            soundAlarmEnabled = !soundAlarmEnabled;
            document.getElementById('sound-icon').textContent = soundAlarmEnabled ? '🔔' : '🔕';
            document.getElementById('sound-label').textContent = soundAlarmEnabled ? 'Alarm ON' : 'Alarm OFF';
        }

        function pingClient(ip) {
            const slug = ip.replace(/\./g, '-');
            const box = document.getElementById('ping-res-' + slug);
            if (box) {
                box.classList.remove('hidden');
                box.textContent = 'Menguji ICMP ping fisik dari MikroTik...';
                box.className = 'text-[11px] font-mono px-2.5 py-1.5 rounded-lg bg-slate-950 border border-cyan-500/40 text-cyan-300';
            }

            fetch('{{ route("mikrotik.ping") }}', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken
                },
                body: JSON.stringify({ ip_address: ip })
            })
            .then(r => r.json())
            .then(res => {
                if (res.status === 'success') {
                    const d = res.data;
                    const text = d.online
                        ? `PING FISIK OK | Latency: ${d.latency} | Loss: ${d.loss}`
                        : `PING FISIK RTO | Loss: ${d.loss} (Belum ada perangkat fisik)`;
                    if (box) {
                        box.textContent = text;
                        box.className = d.online
                            ? 'text-[11px] font-mono px-2.5 py-1.5 rounded-lg bg-emerald-950/80 border border-emerald-500/50 text-emerald-300'
                            : 'text-[11px] font-mono px-2.5 py-1.5 rounded-lg bg-amber-950/80 border border-amber-500/50 text-amber-200';
                    }
                }
            });
        }

        function searchLocation() {
            const q = document.getElementById('map-search-input').value.trim();
            if (!q) return;

            fetch(`https://nominatim.openstreetmap.org/search?format=json&q=${encodeURIComponent(q)}&limit=1`)
                .then(r => r.json())
                .then(results => {
                    if (results.length > 0) {
                        const lat = parseFloat(results[0].lat);
                        const lon = parseFloat(results[0].lon);
                        map.setView([lat, lon], 17);
                        document.getElementById('lat').value = lat.toFixed(6);
                        document.getElementById('lng').value = lon.toFixed(6);
                        showToast(`Peta diarahkan ke: ${results[0].display_name.split(',')[0]}`);
                    } else {
                        showToast('Lokasi tidak ditemukan.');
                    }
                });
        }

        function locateMyGps() {
            if (!navigator.geolocation) {
                showToast('Browser tidak mendukung GPS.');
                return;
            }
            navigator.geolocation.getCurrentPosition(pos => {
                const lat = pos.coords.latitude;
                const lng = pos.coords.longitude;
                map.setView([lat, lng], 18);
                document.getElementById('lat').value = lat.toFixed(6);
                document.getElementById('lng').value = lng.toFixed(6);
                showToast('Berhasil mengarahkan peta ke koordinat GPS Anda.');
            }, () => {
                showToast('Izin lokasi GPS ditolak oleh browser.');
            });
        }

        let newPin = null;
        map.on('click', function(e) {
            document.getElementById('lat').value = e.latlng.lat.toFixed(6);
            document.getElementById('lng').value = e.latlng.lng.toFixed(6);
            if (newPin) map.removeLayer(newPin);
            newPin = L.marker(e.latlng).addTo(map).bindPopup('Titik Koordinat Pelanggan Baru').openPopup();
            showToast(`Koordinat dipilih: ${e.latlng.lat.toFixed(5)}, ${e.latlng.lng.toFixed(5)}`);
        });

        function focusNode(identifier) {
            const m = markerMap[identifier];
            if (m) {
                map.setView(m.getLatLng(), 18);
                m.openPopup();
            }
        }

        function toggleSidebar() {
            const sidebar = document.getElementById('sidebar');
            sidebar.classList.toggle('translate-x-[110%]');
            sidebar.classList.toggle('opacity-0');
            sidebar.classList.toggle('pointer-events-none');
        }
    </script>
</body>
</html>