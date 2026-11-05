<?php

namespace Tests\Feature;

use App\Enums\AccessLevelEnum;
use App\Http\Livewire\Controllers\CreateManualClx;
use App\Models\DatalinkAuthority;
use App\Models\Track;
use App\Models\VatsimAccount;
use App\Services\VatsimDataService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class RclFormKeepsInputTest extends TestCase
{
    use RefreshDatabase;

    private VatsimAccount $user;

    private Track $track;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        config()->set('app.rcl_time_constraints_enabled', false);

        Gate::define('activePilot', fn (VatsimAccount $account) => true);

        $this->user = VatsimAccount::create([
            'given_name' => 'Test',
            'surname' => 'Pilot',
            'rating_int' => 1,
            'access_level' => AccessLevelEnum::Pilot,
        ]);
        $this->actingAs($this->user);

        // The pilot is connected with a flight plan that differs from what they typed
        Http::fake([
            'data.vatsim.net/*' => Http::response([
                'pilots' => [[
                    'cid' => $this->user->id,
                    'callsign' => 'BAW123',
                    'flight_plan' => ['aircraft_short' => 'B77W', 'altitude' => '35000', 'arrival' => 'KJFK'],
                ]],
                'controllers' => [],
            ]),
            '*' => Http::response([]),
        ]);

        DatalinkAuthority::create([
            'id' => 'NAT',
            'name' => 'Shanwick/Gander',
            'prefix' => 'NAT',
            'auto_acknowledge_participant' => false,
            'valid_rcl_target' => true,
            'system' => false,
        ]);
        DatalinkAuthority::create([
            'id' => 'OCEN',
            'name' => 'Oceanic',
            'prefix' => 'OC',
            'auto_acknowledge_participant' => false,
            'valid_rcl_target' => true,
            'system' => false,
        ]);

        $this->track = Track::create([
            'identifier' => 'B',
            'active' => true,
            'last_routeing' => 'LIMRI 51/20 49/30 47/40 46/50 SUPRY',
            'valid_from' => now(),
            'valid_to' => now()->addHours(6),
            'last_active' => now(),
        ]);
    }

    private function pilotInput(array $overrides = []): array
    {
        return array_merge([
            'callsign' => 'BAW99',
            'destination' => 'KBOS',
            'flight_level' => '370',
            'max_flight_level' => '390',
            'mach' => '084',
            'entry_fix' => 'TOOLONG',
            'entry_time' => '1357',
            'tmi' => '258',
            'track_id' => (string) $this->track->id,
            'random_routeing' => null,
            'is_concorde' => '0',
            'target_datalink_authority_id' => 'OCEN',
        ], $overrides);
    }

    public function test_pilot_form_is_refilled_after_validation_error(): void
    {
        $this->from(route('pilots.rcl.create'))
            ->post(route('pilots.rcl.store'), $this->pilotInput())
            ->assertRedirect(route('pilots.rcl.create'))
            ->assertSessionHasErrors('entry_fix');

        $this->get(route('pilots.rcl.create'))
            ->assertOk()
            ->assertSee('value="BAW99"', false)
            ->assertSee('value="KBOS"', false)
            ->assertSee('value="370"', false)
            ->assertSee('value="'.$this->track->id.'" selected', false)
            ->assertSee('value="OCEN" selected', false)
            ->assertDontSee('<option value="" selected>None</option>', false);
    }

    public function test_pilot_form_uses_flight_plan_data_without_previous_input(): void
    {
        $this->get(route('pilots.rcl.create'))
            ->assertOk()
            ->assertSee('value="BAW123"', false)
            ->assertSee('value="KJFK"', false)
            ->assertSee('value="350"', false)
            ->assertSee('Your callsign was automatically collected')
            ->assertSee('Your destination was automatically collected');
    }

    public function test_pilot_form_without_flight_plan_does_not_claim_data_was_collected(): void
    {
        $this->mock(VatsimDataService::class)->shouldReceive('getActivePilotData')->andReturn(null);

        $this->get(route('pilots.rcl.create'))
            ->assertOk()
            ->assertDontSee('Your callsign was automatically collected')
            ->assertDontSee('Your destination was automatically collected');
    }

    public function test_pilot_entry_fix_input_is_limited_to_five_characters(): void
    {
        $this->get(route('pilots.rcl.create'))
            ->assertOk()
            ->assertSee('id="entry_fix" placeholder="e.g. MALOT" maxlength="5"', false);
    }

    public function test_manual_clx_keeps_input_after_entry_fix_error(): void
    {
        Livewire::test(CreateManualClx::class, [
            'tracks' => Track::all(),
            'dlAuthorities' => DatalinkAuthority::all(),
        ])
            ->set('callsign', 'BAW99')
            ->set('destination', 'KBOS')
            ->set('flightLevel', '370')
            ->set('mach', '084')
            ->set('randomRouteing', 'TOOLONG 51/20 49/30 47/40 46/50 SUPRY')
            ->set('entryFix', 'TOOLONG')
            ->set('entryTime', '1357')
            ->call('submit')
            ->assertHasErrors('entryFix')
            ->assertSet('callsign', 'BAW99')
            ->assertSet('destination', 'KBOS')
            ->assertSet('flightLevel', '370')
            ->assertSet('randomRouteing', 'TOOLONG 51/20 49/30 47/40 46/50 SUPRY')
            ->assertSet('entryFix', 'TOOLONG')
            ->assertSeeHtml('id="entry_fix" placeholder="e.g. MALOT" maxlength="5"');
    }
}
