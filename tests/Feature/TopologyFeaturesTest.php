<?php

namespace Tests\Feature;

use App\Models\NetworkNode;
use App\Models\TelemetrySnapshot;
use App\Services\RootCauseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TopologyFeaturesTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_retrieve_topology_dashboard(): void
    {
        $response = $this->get('/');
        $response->assertStatus(200);
    }

    public function test_can_create_odn_splitter_node(): void
    {
        $response = $this->post('/odn/nodes', [
            'name' => 'ODP-TEST-01',
            'type' => 'odp',
            'latitude' => -7.3112,
            'longitude' => 112.7431,
            'capacity_ports' => 8,
            'parent_identifier' => 'OLT-01',
            'notes' => 'Test ODP Splitter',
        ]);

        $response->assertSessionHas('success');

        $this->assertDatabaseHas('network_nodes', [
            'name' => 'ODP-TEST-01',
            'type' => 'odp',
            'node_subtype' => 'splitter_odp',
            'capacity_ports' => 8,
        ]);
    }

    public function test_can_assign_client_to_odp(): void
    {
        $odp = NetworkNode::create([
            'identifier' => 'ODP-TEST-02',
            'name' => 'ODP-TEST-02',
            'type' => 'odp',
            'node_subtype' => 'odp',
            'latitude' => -7.311,
            'longitude' => 112.743,
            'capacity_ports' => 8,
            'used_ports' => 1,
            'fiber_core_color' => 'orange',
            'optical_power_rx' => -19.00,
            'is_active' => true,
        ]);

        $client = NetworkNode::create([
            'identifier' => '192.168.10.50',
            'name' => 'CLIENT-TEST-01',
            'type' => 'client',
            'latitude' => -7.312,
            'longitude' => 112.744,
            'parent_identifier' => null,
            'is_active' => true,
        ]);

        $response = $this->post('/clients/assign-odp', [
            'ip_address' => '192.168.10.50',
            'odp_identifier' => 'ODP-TEST-02',
            'fiber_core_color' => 'blue',
            'optical_power_rx' => -19.5,
        ]);

        $response->assertSessionHas('success');

        $this->assertDatabaseHas('network_nodes', [
            'identifier' => '192.168.10.50',
            'parent_identifier' => 'ODP-TEST-02',
            'fiber_core_color' => 'blue',
        ]);
    }

    public function test_rca_service_deduces_root_causes(): void
    {
        $rcaService = new RootCauseService();

        $olt = NetworkNode::create([
            'identifier' => 'OLT-TEST-01',
            'name' => 'OLT-TEST-01',
            'type' => 'olt',
            'latitude' => -7.31,
            'longitude' => 112.74,
            'is_active' => false, // down
        ]);

        $odp = NetworkNode::create([
            'identifier' => 'ODP-TEST-DOWN',
            'name' => 'ODP-TEST-DOWN',
            'type' => 'odp',
            'latitude' => -7.311,
            'longitude' => 112.741,
            'parent_identifier' => $olt->identifier,
            'is_active' => false,
        ]);

        $client = NetworkNode::create([
            'identifier' => '192.168.10.99',
            'name' => 'CLIENT-DOWN',
            'type' => 'client',
            'latitude' => -7.312,
            'longitude' => 112.742,
            'parent_identifier' => $odp->identifier,
            'is_active' => false,
        ]);

        $nodes = collect([$olt, $odp, $client]);
        $clients = [
            [
                'nama_klien' => 'CLIENT-DOWN',
                'ip_address' => '192.168.10.99',
                'mac_address' => '00:11:22:33:44:55',
                'status' => 'problem',
                'interface' => 'ether1',
            ]
        ];
        $interfaces = [
            ['name' => 'ether1', 'running' => false, 'disabled' => false]
        ];

        $analysis = $rcaService->analyze($nodes, $clients, $interfaces, 'MikroTik connection timeout');

        $this->assertArrayHasKey('incidents', $analysis);
        $this->assertArrayHasKey('suppressed', $analysis);
        $this->assertGreaterThanOrEqual(1, count($analysis['incidents']));
    }

    public function test_can_fetch_telemetry_history(): void
    {
        TelemetrySnapshot::create([
            'active_clients' => 10,
            'rto_clients' => 1,
            'cpu_load' => 25,
            'memory_usage' => 210,
            'traffic_rx_mbps' => 84.5,
            'traffic_tx_mbps' => 120.2,
        ]);

        $response = $this->getJson('/telemetry/history');

        $response->assertStatus(200)
                 ->assertJsonStructure([
                     'status',
                     'data',
                 ]);
    }
}
