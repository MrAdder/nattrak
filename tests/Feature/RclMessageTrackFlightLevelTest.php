<?php

namespace Tests\Feature;

use App\Enums\AccessLevelEnum;
use App\Models\DatalinkAuthority;
use App\Models\Track;
use App\Models\VatsimAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class RclMessageTrackFlightLevelTest extends TestCase
{
    use RefreshDatabase;

    private const ROUTEING = 'LIMRI 51/20 49/30 47/40 46/50 SUPRY';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('app.rcl_time_constraints_enabled', false);
        config()->set('app.rcl_auto_acknowledgement_enabled', false);
        config()->set('app.rcl_rr_matching_track_action', 'reject');

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

    private function createTrack(?array $flightLevels = [35000, 36000, 37000, 39000, 40000]): Track
    {
        return Track::create([
            'identifier' => 'B',
            'active' => true,
            'last_routeing' => self::ROUTEING,
            'valid_from' => now(),
            'valid_to' => now()->addHours(6),
            'last_active' => now(),
            'flight_levels' => $flightLevels,
        ]);
    }

    private function submit(string $flightLevel, ?int $trackId, ?string $randomRouteing = null)
    {
        return $this->from(route('pilots.rcl.create'))
            ->post(route('pilots.rcl.store'), [
                'callsign' => 'DLH428',
                'destination' => 'KCLT',
                'flight_level' => $flightLevel,
                'max_flight_level' => '400',
                'mach' => '085',
                'entry_fix' => 'LIMRI',
                'entry_time' => '1357',
                'tmi' => '258',
                'track_id' => $trackId,
                'random_routeing' => $randomRouteing,
                'is_concorde' => '0',
                'target_datalink_authority_id' => 'OCEN',
            ]);
    }

    public function test_track_request_at_unavailable_level_is_rejected(): void
    {
        $track = $this->createTrack();

        $this->submit('380', $track->id)
            ->assertRedirect(route('pilots.rcl.create'))
            ->assertSessionHasErrors([
                'flight_level.track' => 'NAT Track B is only available at FL350, 360, 370, 390, 400. Please request one of those levels, or request a random routeing if you require FL380.',
            ]);

        $this->assertDatabaseCount('rcl_messages', 0);
    }

    public function test_track_request_at_available_level_is_accepted(): void
    {
        $track = $this->createTrack();

        $this->submit('370', $track->id)
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('pilots.message-history'));

        $this->assertDatabaseHas('rcl_messages', ['callsign' => 'DLH428', 'track_id' => $track->id, 'flight_level' => '370']);
    }

    /**
     * @dataProvider unknownTrackLevels
     */
    public function test_track_with_unknown_levels_accepts_any_level(?array $flightLevels): void
    {
        $track = $this->createTrack($flightLevels);

        $this->submit('380', $track->id)
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('pilots.message-history'));
    }

    public static function unknownTrackLevels(): array
    {
        return [
            'no levels' => [null],
            'unparsed levels' => [[0]],
        ];
    }

    public function test_rr_along_track_at_unavailable_level_is_accepted_as_rr(): void
    {
        $this->createTrack();

        $this->submit('380', null, self::ROUTEING)
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('pilots.message-history'));

        $this->assertDatabaseHas('rcl_messages', ['callsign' => 'DLH428', 'track_id' => null, 'random_routeing' => self::ROUTEING]);
    }

    public function test_rr_along_track_at_available_level_is_still_rejected(): void
    {
        $this->createTrack();

        $this->submit('370', null, self::ROUTEING)
            ->assertRedirect(route('pilots.rcl.create'))
            ->assertSessionHasErrors('random_routeing');

        $this->assertDatabaseCount('rcl_messages', 0);
    }

    public function test_rr_along_track_at_unavailable_level_is_not_converted_to_track(): void
    {
        config()->set('app.rcl_rr_matching_track_action', 'convert');
        $this->createTrack();

        $this->submit('380', null, self::ROUTEING)
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('pilots.message-history'));

        $this->assertDatabaseHas('rcl_messages', ['callsign' => 'DLH428', 'track_id' => null, 'random_routeing' => self::ROUTEING]);
    }
}
