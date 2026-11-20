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