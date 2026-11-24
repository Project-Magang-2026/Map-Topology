<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TelemetrySnapshot extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'cpu_load',
        'memory_usage',
        'active_clients',
        'rto_clients',
        'traffic_rx_mbps',
        'traffic_tx_mbps',
        'created_at',
    ];

    protected $casts = [
        'cpu_load'        => 'integer',
        'memory_usage'    => 'integer',
        'active_clients'  => 'integer',
        'rto_clients'     => 'integer',
        'traffic_rx_mbps' => 'float',
        'traffic_tx_mbps' => 'float',
        'created_at'      => 'datetime',
    ];
}
