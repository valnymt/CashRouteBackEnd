<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\TeamResource;
use App\Models\Team;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Delivery Teams: list, Create Team, Edit members, Confirm team.
 * A team is a draft until confirmed; confirming needs at least 1 driver + 2 custodians.
 * Any member change makes it a draft again. Teams on a trip can't be changed.
 */
class TeamController extends Controller
{
    /** GET /api/teams */
    public function index()
    {
        return TeamResource::collection(Team::with('members')->orderBy('name')->get());
    }

    /** POST /api/teams — { name, memberIds: ["U-004", …], confirmed?: bool } */
    public function store(Request $request)
    {
        abort_unless($request->user()->hasPermission('teams.manage'), 403, 'You are not allowed to manage teams.');
        $data = $this->validated($request);

        $team = DB::transaction(function () use ($data, $request) {
            $team = Team::create(['name' => $data['name'], 'status' => 'available']);
            $this->saveMembers($team, $data, $request->user());

            return $team;
        });

        Audit::log('team.create', null, ['team' => $team->name, 'confirmed' => $team->is_confirmed]);

        return (new TeamResource($team->load('members')))->response()->setStatusCode(201);
    }

    /** PUT /api/teams/{id} — same body as create. */
    public function update(Request $request, string $id)
    {
        abort_unless($request->user()->hasPermission('teams.manage'), 403, 'You are not allowed to manage teams.');
        $team = Team::findOrFail($id);
        abort_if($team->status === 'on_trip', 409, "{$team->name} is on a delivery and can't be edited until the trip ends.");

        $data = $this->validated($request, $team);

        DB::transaction(function () use ($team, $data, $request) {
            $team->update(['name' => $data['name']]);
            $this->saveMembers($team, $data, $request->user());
        });

        Audit::log('team.update', null, ['team' => $team->name, 'confirmed' => $team->is_confirmed]);

        return new TeamResource($team->load('members'));
    }

    private function validated(Request $request, ?Team $team = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:60', Rule::unique('teams', 'name')->ignore($team?->id)],
            'memberIds' => ['present', 'array'],
            'memberIds.*' => ['string', Rule::exists('users', 'employee_id')],
            'confirmed' => ['sometimes', 'boolean'],
        ]);
    }

    /** Checks the members, moves them out of other teams, saves, sets confirmed / draft. */
    private function saveMembers(Team $team, array $data, User $by): void
    {
        $members = User::with(['role', 'teams'])->whereIn('employee_id', $data['memberIds'])->get();

        foreach ($members as $m) {
            if (! in_array($m->role->name, ['Driver', 'Custodian'], true)) {
                throw ValidationException::withMessages(['members' => "{$m->name} is not a driver or custodian."]);
            }
            $old = $m->teams->first();
            if ($old && $old->id !== $team->id && $old->status === 'on_trip') {
                throw ValidationException::withMessages(['members' => "{$m->name} is on a delivery with {$old->name}."]);
            }
        }

        $confirm = (bool) ($data['confirmed'] ?? false);
        if ($confirm && $error = $this->ruleError($members)) {
            throw ValidationException::withMessages(['members' => $error]);
        }

        // Moving someone out of another team turns that team back into a draft (same as the portal).
        foreach ($members as $m) {
            $old = $m->teams->first();
            if ($old && $old->id !== $team->id) {
                $old->members()->detach($m->id);
                $old->update(['is_confirmed' => false, 'confirmed_at' => null, 'confirmed_by' => null]);
            }
        }

        $team->members()->sync($members->pluck('id'));
        $team->update([
            'is_confirmed' => $confirm,
            'confirmed_at' => $confirm ? now() : null,
            'confirmed_by' => $confirm ? $by->id : null,
        ]);
    }

    /** null if the team has at least 1 driver and 2 custodians, otherwise the reason. */
    private function ruleError(Collection $members): ?string
    {
        $drivers = $members->filter(fn ($u) => $u->role->name === 'Driver')->count();
        $custodians = $members->filter(fn ($u) => $u->role->name === 'Custodian')->count();

        if ($drivers >= 1 && $custodians >= 2) {
            return null;
        }

        return "A team needs at least 1 driver and 2 custodians (now {$drivers} driver(s), {$custodians} custodian(s)).";
    }
}
