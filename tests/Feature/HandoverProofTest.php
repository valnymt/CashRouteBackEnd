<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class HandoverProofTest extends TestCase
{
    public function test_verified_dropoff_is_completed_once_and_media_requires_report_access(): void
    {
        config([
            'database.default' => 'handover_test',
            'database.connections.handover_test' => ['driver' => 'sqlite', 'database' => ':memory:'],
        ]);
        $tables = [
            'cash_requests' => 'id integer primary key, reference text, amount decimal, status text, completed_at text, updated_at text',
            'trips' => 'id integer primary key, cash_request_id integer, team_id integer, status text, accepted_at text, completed_at text, is_on_time boolean, updated_at text',
            'teams' => 'id integer primary key',
            'branches' => 'id integer primary key, is_hq boolean',
            'trip_locations' => 'id integer primary key, trip_id integer, recorded_at text',
            'team_members' => 'team_id integer, user_id integer',
            'otps' => 'id integer primary key, cash_request_id integer, stage text, status text, verified_at text',
            'handover_proofs' => 'id integer primary key, cash_request_id integer, stage text, confirmed_amount decimal, denominations text, receiver_name text, signature_path text, submitted_by integer, submitted_at text, created_at text, updated_at text',
            'handover_photos' => 'id integer primary key, handover_proof_id integer, path text, created_at text, updated_at text',
        ];
        foreach ($tables as $table => $columns) {
            DB::statement("create table {$table} ({$columns})");
        }
        DB::table('cash_requests')->insert(['id' => 1, 'reference' => 'REQ-TEST', 'amount' => 250000, 'status' => 'in_transit']);
        DB::table('teams')->insert(['id' => 1]);
        DB::table('branches')->insert(['id' => 1, 'is_hq' => true]);
        DB::table('team_members')->insert(['team_id' => 1, 'user_id' => 1]);
        DB::table('trips')->insert([
            'id' => 1, 'cash_request_id' => 1, 'team_id' => 1, 'status' => 'in_transit',
            'accepted_at' => now()->subMinute(),
        ]);
        Storage::fake('local');

        $user = new class extends User {
            public function getForeignKey()
            {
                return 'user_id';
            }

            public function hasPermission(string $code): bool
            {
                return $code === ($this->is_delivery_member ? 'handover.perform' : 'reports.view');
            }
        };
        $user->id = 1;
        $user->is_delivery_member = true;
        Sanctum::actingAs($user);

        $png = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jrZkAAAAASUVORK5CYII=';
        $submit = fn () => $this->withHeader('Accept', 'application/json')->post('/api/requests/REQ-TEST/proof-of-delivery', [
            'confirmed_amount' => '250000.00',
            'receiver_name' => 'Receiver',
            'signature_png' => $png,
            'photos' => [UploadedFile::fake()->createWithContent('proof.png', base64_decode($png))],
        ]);
        $submit()->assertConflict();
        DB::table('otps')->insert([
            'cash_request_id' => 1, 'stage' => 'dropoff', 'status' => 'verified', 'verified_at' => now(),
        ]);
        $submit()->assertCreated()->assertJsonPath('status', 'completed');
        $submit()->assertOk()->assertJsonPath('id', 1);

        $this->assertDatabaseCount('handover_proofs', 1);
        $this->assertDatabaseCount('handover_photos', 1);
        $this->assertDatabaseHas('trips', ['id' => 1, 'status' => 'completed']);
        $this->assertDatabaseHas('cash_requests', ['id' => 1, 'status' => 'completed']);

        $image = '/api/trips/REQ-TEST/proof-of-delivery/1';
        $this->getJson($image)->assertForbidden();
        $user->is_delivery_member = false;
        $this->getJson('/api/trips/REQ-TEST')->assertOk()
            ->assertJsonPath('data.proof_of_delivery.photo_ids', [1])
            ->assertJsonPath('data.proof_of_delivery.receiver_name', 'Receiver');
        $this->get($image)->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->get('/api/trips/REQ-TEST/proof-of-delivery/signature')->assertOk()->assertHeader('Content-Type', 'image/png');
    }
}
