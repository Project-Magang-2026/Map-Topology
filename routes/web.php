<?php

use App\Http\Controllers\TopologyController;
use Illuminate\Support\Facades\Route;

Route::get('/', [TopologyController::class, 'index'])->name('topology.index');
Route::get('/telemetry', [TopologyController::class, 'liveTelemetry'])->name('topology.telemetry');
Route::post('/clients/ping', [TopologyController::class, 'pingClient'])->name('mikrotik.ping');
Route::patch('/nodes/{node}/coordinates', [TopologyController::class, 'updateCoordinates'])->name('nodes.coordinates');
Route::post('/sync-arp', [TopologyController::class, 'syncArp'])->name('mikrotik.sync');
Route::post('/clients', [TopologyController::class, 'storeClient'])->name('mikrotik.store');
Route::patch('/clients/status', [TopologyController::class, 'toggleStatus'])->name('mikrotik.status');
Route::post('/clients/limit', [TopologyController::class, 'setLimit'])->name('mikrotik.limit');
Route::delete('/clients/{ip}', [TopologyController::class, 'destroyClient'])->name('mikrotik.destroy');
Route::get('/export-kml', [TopologyController::class, 'exportKml'])->name('topology.kml');

// ODN & Fiber Management (Fase 1)
Route::post('/odn/nodes', [TopologyController::class, 'storeOdnNode'])->name('odn.store');
Route::patch('/odn/nodes/{node}', [TopologyController::class, 'updateOdnNode'])->name('odn.update');
Route::delete('/odn/nodes/{node}', [TopologyController::class, 'destroyOdnNode'])->name('odn.destroy');
Route::post('/clients/assign-odp', [TopologyController::class, 'assignClientOdp'])->name('odn.assign');

// Root Cause Analysis & Alert System (Fase 2)
Route::post('/rca/notify', [TopologyController::class, 'notifyTelegramIncident'])->name('rca.notify');

// Router Auto-Location & Cluster Relocation
Route::post('/router/locate', [TopologyController::class, 'autoLocateRouter'])->name('router.locate');
Route::post('/router/rearrange', [TopologyController::class, 'rearrangeCluster'])->name('router.rearrange');

// Telemetry Time-Series & Analitik (Fase 4)
Route::get('/telemetry/history', [TopologyController::class, 'historyTelemetry'])->name('topology.history');
