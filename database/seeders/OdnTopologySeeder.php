<?php

namespace Database\Seeders;

use App\Models\NetworkNode;
use Illuminate\Database\Seeder;

class OdnTopologySeeder extends Seeder
{
    public function run(): void
    {
        $olt1 = NetworkNode::where('identifier', 'bridge-olt1')->first();
        $olt2 = NetworkNode::where('identifier', 'bridge-olt2')->first();

        if ($olt1) {
            $odp1_1 = NetworkNode::updateOrCreate(
                ['identifier' => 'ODP-OLT1-01'],
                [
                    'name'              => 'ODP-OLT1-01 (RT 01)',
                    'type'              => 'odp',
                    'node_subtype'      => 'splitter_1_8',
                    'parent_identifier' => 'bridge-olt1',
                    'capacity_ports'    => 8,
                    'used_ports'        => 3,
                    'latitude'          => $olt1->latitude + 0.0007,
                    'longitude'         => $olt1->longitude + 0.0006,
                    'notes'             => 'Tiang Telkom No. 12 / Depan Masjid',
                ]
            );

            $odp1_2 = NetworkNode::updateOrCreate(
                ['identifier' => 'ODP-OLT1-02'],
                [
                    'name'              => 'ODP-OLT1-02 (RT 02)',
                    'type'              => 'odp',
                    'node_subtype'      => 'splitter_1_8',
                    'parent_identifier' => 'bridge-olt1',
                    'capacity_ports'    => 8,
                    'used_ports'        => 2,
                    'latitude'          => $olt1->latitude - 0.0006,
                    'longitude'         => $olt1->longitude + 0.0011,
                    'notes'             => 'Tiang No. 18 / Perempatan Gang Melati',
                ]
            );

            // Hubungkan klien OLT1 ke ODP
            $c1 = NetworkNode::where('identifier', '10.10.1.11')->first();
            if ($c1) $c1->update(['parent_identifier' => 'ODP-OLT1-01', 'fiber_core_color' => 'Biru', 'optical_power_rx' => -19.20]);

            $c2 = NetworkNode::where('identifier', '10.10.1.12')->first();
            if ($c2) $c2->update(['parent_identifier' => 'ODP-OLT1-01', 'fiber_core_color' => 'Oranye', 'optical_power_rx' => -19.85]);

            $c3 = NetworkNode::where('identifier', '10.10.1.13')->first();
            if ($c3) $c3->update(['parent_identifier' => 'ODP-OLT1-01', 'fiber_core_color' => 'Hijau', 'optical_power_rx' => -20.40]);

            $c4 = NetworkNode::where('identifier', '10.10.1.14')->first();
            if ($c4) $c4->update(['parent_identifier' => 'ODP-OLT1-02', 'fiber_core_color' => 'Biru', 'optical_power_rx' => -21.10]);

            $c5 = NetworkNode::where('identifier', '10.10.1.15')->first();
            if ($c5) $c5->update(['parent_identifier' => 'ODP-OLT1-02', 'fiber_core_color' => 'Oranye', 'optical_power_rx' => -21.65]);
        }

        if ($olt2) {
            $odp2_1 = NetworkNode::updateOrCreate(
                ['identifier' => 'ODP-OLT2-01'],
                [
                    'name'              => 'ODP-OLT2-01 (Kawasan Ruko)',
                    'type'              => 'odp',
                    'node_subtype'      => 'splitter_1_8',
                    'parent_identifier' => 'bridge-olt2',
                    'capacity_ports'    => 8,
                    'used_ports'        => 3,
                    'latitude'          => $olt2->latitude - 0.0007,
                    'longitude'         => $olt2->longitude - 0.0006,
                    'notes'             => 'Tiang No. 05 / Ruko Sentra Niaga',
                ]
            );

            $odp2_2 = NetworkNode::updateOrCreate(
                ['identifier' => 'ODP-OLT2-02'],
                [
                    'name'              => 'ODP-OLT2-02 (Blok B)',
                    'type'              => 'odp',
                    'node_subtype'      => 'splitter_1_8',
                    'parent_identifier' => 'bridge-olt2',
                    'capacity_ports'    => 8,
                    'used_ports'        => 2,
                    'latitude'          => $olt2->latitude + 0.0008,
                    'longitude'         => $olt2->longitude - 0.0010,
                    'notes'             => 'Tiang No. 09 / Depan Hotel',
                ]
            );

            $c6 = NetworkNode::where('identifier', '10.10.2.11')->first();
            if ($c6) $c6->update(['parent_identifier' => 'ODP-OLT2-01', 'fiber_core_color' => 'Biru', 'optical_power_rx' => -18.75]);

            $c7 = NetworkNode::where('identifier', '10.10.2.12')->first();
            if ($c7) $c7->update(['parent_identifier' => 'ODP-OLT2-01', 'fiber_core_color' => 'Oranye', 'optical_power_rx' => -19.10]);

            $c8 = NetworkNode::where('identifier', '10.10.2.13')->first();
            if ($c8) $c8->update(['parent_identifier' => 'ODP-OLT2-01', 'fiber_core_color' => 'Hijau', 'optical_power_rx' => -20.05]);

            $c9 = NetworkNode::where('identifier', '10.10.2.14')->first();
            if ($c9) $c9->update(['parent_identifier' => 'ODP-OLT2-02', 'fiber_core_color' => 'Biru', 'optical_power_rx' => -22.30]);

            $c10 = NetworkNode::where('identifier', '10.10.2.15')->first();
            if ($c10) $c10->update(['parent_identifier' => 'ODP-OLT2-02', 'fiber_core_color' => 'Oranye', 'optical_power_rx' => -23.15]);
        }
    }
}
