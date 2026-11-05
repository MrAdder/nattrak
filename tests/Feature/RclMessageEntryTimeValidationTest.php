<?php

namespace Tests\Feature;

use App\Enums\AccessLevelEnum;
use App\Models\DatalinkAuthority;
use App\Models\VatsimAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class RclMessageEntryTimeValidationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('app.rcl_time_constraints_enabled', true);
        config()->set('app.rcl_auto_acknowledgement_enabled', false);

        Gate::define('activePilot', fn (VatsimAccount $account) => true);

        $this->actingAs(VatsimAccount::create([
            'given_name' => 'Test',
            'surname' => 'Pilot',
            'rating_int' => 1,
            'access_level' => AccessLevelEnum::Pilot,
        ]));

        DatalinkAuthority::create([
            'id' => 'OCEN',
            'name' => 'Oceanic',
            'prefix' => 'OC',
            'auto_acknowledge_participant' => false,
            'valid_rcl_target' => true,
            'system' => false,
        ]);
    }

    private function submitWithEntryTime(string $entryTime)
    {
        return $this->from(route('pilots.rcl.create'))
            ->post(route('pilots.rcl.store'), [
                'callsign' => 'BAW123',
                'destination' => 'EGLL',
                'flight_level' => '340',
                'max_flight_level' => '390',
                'mach' => '080',
                'entry_fix' => 'GOMUP',
                'entry_time' => $entryTime,
                'tmi' => '123',
                'track_id' => null,
                'random_routeing' => 'GOMUP 58/20 57/30 JANJO',
                'is_concorde' => '0',
                'target_datalink_authority_id' => 'OCEN',
            ]);
    }

    /**
     * @dataProvider invalidEntryTimes
     */
    public function test_invalid_entry_time_is_rejected_without_crashing(string $entryTime): void
    {
        $this->submitWithEntryTime($entryTime)
            ->assertRedirect(route('pilots.rcl.create'))
            ->assertSessionHasErrors('entry_time');

        $this->assertDatabaseCount('rcl_messages', 0);
    }

    public function test_out_of_range_time_shows_hhmm_message(): void
    {
        $this->submitWithEntryTime('1275')
            ->assertSessionHasErrors([
                'entry_time' => 'Your oceanic entry time must be a valid 24-hour time in HHMM format (e.g. 1350).',
            ]);
    }

    public static function invalidEntryTimes(): array
    {
        return [
            'minute out of range' => ['1275'],
            'hour out of range' => ['2530'],
            'decimal' => ['12.5'],
            'exponent' => ['1e10'],
        ];
    }

    public function test_valid_entry_time_outside_window_gets_range_error(): void
    {
        $this->submitWithEntryTime(now()->addHours(5)->format('Hi'))
            ->assertRedirect(route('pilots.rcl.create'))
            ->assertSessionHasErrors('entry_time.range');
    }
}
