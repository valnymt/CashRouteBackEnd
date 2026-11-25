<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\TeamResource;
use App\Models\Team;
use App\Models\User;
use App\Support\Audit;
use App\Support\DeliveryNotifications;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Delivery Teams: list, Create Team, Edit members, Confirm team.
 * Members are always from one branch. A team is a draft until confirmed; confirming needs at least 1 driver + 2 custodians.
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

        $team = DB::transaction(function () use ($team, $data, $request) {
            $team = Team::whereKey($team->id)->lockForUpdate()->firstOrFail();
            $this->ensureEditable($team);
            $team->update(['name' => $data['name']]);
            $this->saveMembers($team, $data, $request->user());

            return $team;
        });

        Audit::log('team.update', null, ['team' => $team->name, 'confirmed' => $team->is_confirmed]);

        return new TeamResource($team->load('members'));
    }

    public function setLeader(Request $request, string $id)
    {
        abort_unless($request->user()->hasPermission('teams.manage'), 403, 'You are not allowed to manage teams.');
        $data = $request->validate(['leaderId' => ['required', 'string', 'max:20']]);

        $team = DB::transaction(function () use ($id, $data) {
            $team = Team::whereKey($id)->lockForUpdate()->firstOrFail();
            $this->ensureEditable($team);
            $leader = $team->members()->where('users.employee_id', $data['leaderId'])->first();
            if (!$leader || !$leader->is_active || !$leader->is_delivery_member || $leader->role?->name !== 'Custodian') {
                throw ValidationException::withMessages(['leaderId' => 'Choose an active custodian from this delivery team.']);
            }

            if ((int) $team->leader_user_id === (int) $leader->id) {
                return $team;
            }

            $team->update(['leader_user_id' => $leader->id]);
            $this->notifyLeaderChange($team, $leader);

            return $team;
        });

        return new TeamResource($team->load('members'));
    }

    private function notifyLeaderChange(Team $team, User $leader): void
    {
        Audit::log('team.leader_changed', null, ['team' => $team->name, 'leader' => $leader->name]);
        DeliveryNotifications::send([$leader->id], [
            'type' => 'team.leader_changed',
            'link' => '/users/teams?team='.$team->id,
            'title' => 'You are the delivery team leader',
            'body' => "You can now accept assignments for {$team->name}.",
        ]);
        DeliveryNotifications::send($team->members()->where('users.id', '!=', $leader->id)->pluck('users.id'), [
            'type' => 'team.leader_changed',
            'link' => '/users/teams?team='.$team->id,
            'title' => 'Delivery team leader updated',
            'body' => "{$leader->name} is now the leader of {$team->name} and can accept assignments.",
        ]);
    }

    private function ensureEditable(Team $team): void
    {
        abort_if($team->status === 'on_trip' || $team->trips()->whereIn('status', ['assigned', 'in_transit'])->exists(),
            409, 'The team and its leader cannot be changed during an active delivery.');
    }

    private function validated(Request $request, ?Team $team = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:60', Rule::unique('teams', 'name')->ignore($team?->id)],
            'memberIds' => ['present', 'array'],
            'memberIds.*' => ['string', Rule::exists('users', 'employee_id')],
            'confirmed' => ['sometimes', 'boolean'],
            'leaderId' => [$team ? 'sometimes' : 'required', 'required', 'string', 'max:20'],
        ]);
    }

    /** Checks the members, moves them out of other teams, saves, sets confirmed / draft. */
    private function saveMembers(Team $team, array $data, User $by): void
    {
        $previous = $team->members()->pluck('users.id');
        $wasConfirmed = (bool) $team->is_confirmed;
        $renamed = $team->wasChanged('name');
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

        // Every member is from the same branch (drafts too).
        $missing = $members->first(fn ($m) => ! $m->branch_id);
        if ($missing) {
            throw ValidationException::withMessages(['members' => "{$missing->name} has no branch yet. Set one in User Accounts first."]);
        }
        if ($members->pluck('branch_id')->unique()->count() > 1) {
            throw ValidationException::withMessages(['members' => 'All team members must be from the same branch.']);
        }

        $confirm = (bool) ($data['confirmed'] ?? false);
        if ($confirm && $error = $this->ruleError($members)) {
            throw ValidationException::withMessages(['members' => $error]);
        }

        $leader = isset($data['leaderId'])
            ? $members->firstWhere('employee_id', $data['leaderId'])
            : $members->firstWhere('id', $team->leader_user_id);
        if ($leader && (!$leader->is_active || !$leader->is_delivery_member || $leader->role?->name !== 'Custodian')) {
            $leader = null;
        }
        if (!$leader && (isset($data['leaderId']) || $confirm)) {
            throw ValidationException::withMessages(['leaderId' => 'Select an active custodian on this team as its leader.']);
        }
        $leaderChanged = $leader && (int) $leader->id !== (int) $team->leader_user_id;

        // Moving someone out of another team turns that team back into a draft (same as the portal).
        $changedTeams = collect();
        foreach ($members as $m) {
            $old = $m->teams->first();
            if ($old && $old->id !== $team->id) {
                $old = Team::whereKey($old->id)->lockForUpdate()->firstOrFail();
                $this->ensureEditable($old);
                $old->members()->detach($m->id);
                if ((int) $old->leader_user_id === (int) $m->id) {
                    $old->update(['leader_user_id' => null]);
                }
                $changedTeams->put($old->id, $old);
            }
        }

        foreach ($changedTeams as $old) {
            $old->update(['is_confirmed' => false, 'confirmed_at' => null, 'confirmed_by' => null]);
            DeliveryNotifications::send($old->members()->pluck('users.id'), [
                'type' => 'team.updated',
                'title' => 'Delivery team updated',
                'link' => '/users/teams?team='.$old->id,
                'body' => "The membership of {$old->name} has changed.",
            ]);
        }

        $team->members()->sync($members->pluck('id'));
        if ($leaderChanged || (!$leader && $team->leader_user_id)) {
            $team->update(['leader_user_id' => $leader?->id]);
        }
        if ($leaderChanged) {
            $this->notifyLeaderChange($team, $leader);
        }
        $team->update([
            'is_confirmed' => $confirm,
            'confirmed_at' => $confirm ? now() : null,
            'confirmed_by' => $confirm ? $by->id : null,
        ]);

        $current = $members->pluck('id');
        DeliveryNotifications::send($current->diff($previous), [
            'type' => 'team.joined',
            'link' => '/users/teams?team='.$team->id,
            'title' => 'Added to a delivery team',
            'body' => "You were added to {$team->name}.",
        ]);
        DeliveryNotifications::send($previous->diff($current), [
            'type' => 'team.left',
            'title' => 'Removed from a delivery team',
            'body' => "You were removed from {$team->name}.",
        ]);

        if ($previous->diff($current)->isNotEmpty() || $current->diff($previous)->isNotEmpty() || $renamed || $wasConfirmed !== $confirm) {
            DeliveryNotifications::send($previous->intersect($current), [
                'type' => 'team.updated',
                'title' => $confirm && !$wasConfirmed ? 'Delivery team confirmed' : 'Delivery team updated',
                'link' => '/users/teams?team='.$team->id,
                'body' => "Review your delivery team {$team->name} in CashRoute.",
            ]);
        }
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
