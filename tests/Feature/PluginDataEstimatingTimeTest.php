<?php

namespace Tests\Feature;

use App\Enums\AccessLevelEnum;
use App\Models\ClxMessage;
use App\Models\DatalinkAuthority;
use App\Models\RclMessage;
use App\Models\Track;
use App\Models\VatsimAccount;
use App\Services\ClxMessageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PluginDataEstimatingTimeTest extends TestCase
{
    use RefreshDatabase;

    private function createRclMessage(): RclMessage
    {
        $pilot = VatsimAccount::create([
            'given_name' => 'Test',
            'surname' => 'Pilot',
            'rating_int' => 1,
            'access_level' => AccessLevelEnum::Pilot,
        ]);

        $track = Track::create([
            'identifier' => 'B',
            'active' => true,
            'last_routeing' => 'LIMRI 51/20 49/30 47/40 46/50 SUPRY',
            'valid_from' => now(),
            'valid_to' => now()->addHours(6),
            'last_active' => now(),
        ]);

        return RclMessage::create([
            'vatsim_account_id' => $pilot->id,
            'callsign' => 'DLH428',
            'destination' => 'KCLT',
            'flight_level' => '380',
            'max_flight_level' => '400',
            'mach' => '085',
            'track_id' => $track->id,
            'entry_fix' => 'LIMRI',
            'entry_time' => '1357',
            'tmi' => '258',
            'request_time' => now(),
            'atc_rejected' => false,
            'is_concorde' => false,
        ]);
    }

    public function test_pending_request_reports_pilot_estimate(): void
    {
        $this->createRclMessage();

        $this->getJson('/api/plugins')
            ->assertOk()
            ->assertJsonPath('0.status', 'PENDING')
            ->assertJsonPath('0.estimating_time', '13:57');
    }

    public function test_auto_acknowledged_request_keeps_pilot_estimate(): void
    {
        DatalinkAuthority::create([
            'id' => 'SYST',
            'name' => 'System',
            'prefix' => 'SYST',
            'auto_acknowledge_participant' => false,
            'valid_rcl_target' => false,
            'system' => true,
        ]);

        $rclMessage = $this->createRclMessage();

        (new ClxMessageService())->moveAutoAcknowledgedRclToProcessedList($rclMessage, true);

        $this->assertNull(ClxMessage::first()->cto_time);

        $this->getJson('/api/plugins')
            ->assertOk()
            ->assertJsonPath('0.status', 'CLEARED')
            ->assertJsonPath('0.estimating_time', '13:57');
    }

    public function test_cleared_request_with_cto_reports_cto(): void
    {
        DatalinkAuthority::create([
            'id' => 'NAT',
            'name' => 'Shanwick/Gander',
            'prefix' => 'NAT',
            'auto_acknowledge_participant' => false,
            'valid_rcl_target' => true,
            'system' => false,
        ]);

        $rclMessage = $this->createRclMessage();

        ClxMessage::create([
            'vatsim_account_id' => $rclMessage->vatsim_account_id,
            'rcl_message_id' => $rclMessage->id,
            'flight_level' => '380',
            'mach' => '085',
            'track_id' => $rclMessage->track_id,
            'entry_fix' => 'LIMRI',
            'cto_time' => '1402',
            'raw_entry_time_restriction' => '1402',
            'is_concorde' => false,
            'datalink_authority_id' => 'NAT',
            'simple_datalink_message' => 'TEST',
            'datalink_message' => ['TEST'],
        ]);

        $this->getJson('/api/plugins')
            ->assertOk()
            ->assertJsonPath('0.status', 'CLEARED')
            ->assertJsonPath('0.estimating_time', '14:02');
    }
}
