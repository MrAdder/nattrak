<?php

namespace Tests\Feature;

use App\Enums\AccessLevelEnum;
use App\Http\Livewire\Pilots\NotifyNewEta;
use App\Models\RclMessage;
use App\Models\VatsimAccount;
use App\Services\VatsimDataService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

class RclCreateEntryTimeLabelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

        Gate::define('activePilot', fn (VatsimAccount $account) => true);
        $this->mock(VatsimDataService::class)->shouldReceive('getActivePilotData')->andReturn(null);

        $this->actingAs(VatsimAccount::create([
            'given_name' => 'Test',
            'surname' => 'Pilot',
            'rating_int' => 1,
            'access_level' => AccessLevelEnum::Pilot,
        ]));
    }

    public function test_label_asks_for_eta_at_oceanic_entry_fix(): void
    {
        $this->get(route('pilots.rcl.create'))
            ->assertOk()
            ->assertSee('Estimated time of arrival at <span id="entry_time_fix">oceanic entry fix</span>', false);
    }

    public function test_label_names_previously_entered_fix(): void
    {
        $this->withSession(['_old_input' => ['entry_fix' => 'malot']])
            ->get(route('pilots.rcl.create'))
            ->assertOk()
            ->assertSee('Estimated time of arrival at <span id="entry_time_fix">MALOT</span>', false);
    }

    public function test_notify_new_eta_label_names_the_requested_entry_fix(): void
    {
        RclMessage::create([
            'vatsim_account_id' => auth()->id(),
            'callsign' => 'BAW123',
            'destination' => 'KJFK',
            'flight_level' => '350',
            'max_flight_level' => '390',
            'mach' => '084',
            'entry_fix' => 'MALOT',
            'entry_time' => '1350',
            'tmi' => '258',
            'random_routeing' => 'MALOT 53/20 52/30 51/40 50/50 LOMSI',
            'request_time' => now(),
            'atc_rejected' => false,
            'is_concorde' => false,
        ]);

        Livewire::test(NotifyNewEta::class)
            ->assertSeeHtml('New estimated time of arrival at MALOT</label>');
    }

    public function test_previously_entered_fix_is_escaped(): void
    {
        $this->withSession(['_old_input' => ['entry_fix' => '<b>x']])
            ->get(route('pilots.rcl.create'))
            ->assertOk()
            ->assertSee('<span id="entry_time_fix">&lt;B&gt;X</span>', false);
    }
}
