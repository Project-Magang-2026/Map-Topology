<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class NetworkNode extends Model
{
    public const FIBER_COLORS = [
        1  => ['name' => 'Biru',    'hex' => '#2563eb'],
        2  => ['name' => 'Oranye',  'hex' => '#ea580c'],
        3  => ['name' => 'Hijau',   'hex' => '#16a34a'],
        4  => ['name' => 'Cokelat', 'hex' => '#92400e'],
        5  => ['name' => 'Abu-abu', 'hex' => '#64748b'],
        6  => ['name' => 'Putih',   'hex' => '#e2e8f0'],
        7  => ['name' => 'Merah',   'hex' => '#dc2626'],
        8  => ['name' => 'Hitam',   'hex' => '#1e293b'],
        9  => ['name' => 'Kuning',  'hex' => '#eab308'],
        10 => ['name' => 'Ungu',    'hex' => '#9333ea'],
        11 => ['name' => 'Pink',    'hex' => '#ec4899'],
        12 => ['name' => 'Toska',   'hex' => '#0d9488'],
    ];

    public $timestamps = false;

    protected $fillable = [
        'identifier',
        'name',
        'type',
        'node_subtype',
        'parent_identifier',
        'latitude',
        'longitude',
        'capacity_ports',
        'used_ports',
        'fiber_core_color',
        'optical_power_rx',
        'attenuation_db',
        'notes',
    ];

    protected $casts = [
        'latitude'         => 'float',
        'longitude'        => 'float',
        'capacity_ports'   => 'integer',
        'used_ports'       => 'integer',
        'optical_power_rx' => 'float',
        'attenuation_db'   => 'float',
    ];

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_identifier', 'identifier');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_identifier', 'identifier');
    }

    public function getOpticalStatusAttribute(): string
    {
        if ($this->optical_power_rx === null) {
            return 'unknown';
        }
        if ($this->optical_power_rx >= -23.0) {
            return 'optimal';
        }
        if ($this->optical_power_rx >= -27.0) {
            return 'warning';
        }
        return 'critical';
    }

    public function getFiberHexAttribute(): ?string
    {
        if (!$this->fiber_core_color) {
            return null;
        }

        foreach (self::FIBER_COLORS as $c) {
            if (strcasecmp($c['name'], $this->fiber_core_color) === 0) {
                return $c['hex'];
            }
        }

        return null;
    }
}
