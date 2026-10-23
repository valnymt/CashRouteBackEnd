<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\BranchResource;
use App\Models\Branch;
use App\Models\BranchDirectory;
use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Branches: list, add, edit, deactivate, directory (src/types/index.ts → Branch). */
class BranchController extends Controller
{
    /** GET /api/branches */
    public function index()
    {
        return BranchResource::collection(Branch::orderBy('code')->get());
    }

    /** POST /api/branches — admin only. */
    public function store(Request $request)
    {
        abort_unless($request->user()->hasPermission('branches.manage'), 403, 'You are not allowed to manage branches.');
        $data = $this->validated($request);

        $branch = Branch::create($this->toDb($data) + ['is_active' => $data['active'] ?? true]);
        Audit::log('branch.create', null, ['branch' => $branch->code]);

        return (new BranchResource($branch))->response()->setStatusCode(201);
    }

    /** GET /api/branches/{code} */
    public function show(string $code)
    {
        $branch = Branch::where('code', $code)->firstOrFail();

        return new BranchResource($branch);
    }

    /** PUT /api/branches/{code} — admin only. */
    public function update(Request $request, string $code)
    {
        abort_unless($request->user()->hasPermission('branches.manage'), 403, 'You are not allowed to manage branches.');
        $branch = Branch::where('code', $code)->firstOrFail();
        $data = $this->validated($request, $branch);

        $branch->update($this->toDb($data) + (isset($data['active']) ? ['is_active' => $data['active']] : []));
        Audit::log('branch.update', null, ['branch' => $branch->code, 'changed' => array_keys($branch->getChanges())]);

        return new BranchResource($branch);
    }

    /** DELETE /api/branches/{code} — soft deactivate (portal "Deactivate branch"). */
    public function destroy(Request $request, string $code)
    {
        abort_unless($request->user()->hasPermission('branches.manage'), 403, 'You are not allowed to manage branches.');
        $branch = Branch::where('code', $code)->firstOrFail();
        $branch->update(['is_active' => false]);
        Audit::log('branch.deactivate', null, ['branch' => $branch->code]);

        return new BranchResource($branch);
    }

    /** GET /api/branch-directory — the bank directory + `registered` flag. */
    public function directory()
    {
        $registered = Branch::pluck('code')->flip();

        return BranchDirectory::orderBy('code')->get()->map(fn ($d) => [
            'code' => $d->code,
            'name' => $d->name,
            'address' => $d->address,
            'lat' => (float) $d->lat,
            'lng' => (float) $d->lng,
            'registered' => $registered->has($d->code),
        ])->values();
    }

    /* --------------------------------------------------------------- helpers */

    /** Validate using the portal's field names (BranchInput). */
    private function validated(Request $request, ?Branch $branch = null): array
    {
        $codeRule = Rule::unique('branches', 'code');
        if ($branch) {
            $codeRule->ignore($branch->id);
        }

        return $request->validate([
            'code' => ['required', 'string', 'max:20', $codeRule],
            'name' => ['required', 'string', 'max:120'],
            'address' => ['required', 'string'],
            'type' => ['required', Rule::in(['Branch', 'HQ'])],
            'lat' => ['required', 'numeric'],
            'lng' => ['required', 'numeric'],
            'radius' => ['required', 'integer', 'min:10', 'max:2000'],
            'contactName' => ['required', 'string', 'max:120'],
            'contactMobile' => ['required', 'string', 'max:30'],
            'contactEmail' => ['required', 'email', 'max:160'],
            'active' => ['sometimes', 'boolean'],
        ]);
    }

    /** Portal field names → DB column names. */
    private function toDb(array $data): array
    {
        return [
            'code' => $data['code'],
            'name' => $data['name'],
            'address' => $data['address'],
            'is_hq' => ($data['type'] ?? 'Branch') === 'HQ',
            'lat' => $data['lat'],
            'lng' => $data['lng'],
            'handover_radius' => $data['radius'],
            'contact_name' => $data['contactName'],
            'contact_phone' => $data['contactMobile'],
            'contact_email' => $data['contactEmail'],
        ];
    }
}
