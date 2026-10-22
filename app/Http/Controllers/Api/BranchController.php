<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use Illuminate\Http\Request;

class BranchController extends Controller
{
    public function index()
    {
        return response()->json([
            'success' => true,
            'data' => Branch::all(),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'code'             => 'required|string|unique:branches,code',
            'name'             => 'required|string',
            'address'          => 'required|string',
            'lat'              => 'required|numeric',
            'lng'              => 'required|numeric',
            'handover_radius'  => 'required|integer|min:0',
            'contact_name'     => 'nullable|string',
            'contact_phone'    => 'nullable|string',
            'contact_email'    => 'nullable|email',
            'is_hq'            => 'boolean',
            'is_active'        => 'boolean',
        ]);

        $branch = Branch::create($validated);

        return response()->json([
            'success' => true,
            'data'    => $branch,
        ], 201);
    }

    public function show(string $id)
    {
        $branch = Branch::find($id);

        if (!$branch) {
            return response()->json(['success' => false, 'message' => 'Branch not found'], 404);
        }

        return response()->json(['success' => true, 'data' => $branch]);
    }

    public function update(Request $request, string $id)
    {
        $branch = Branch::find($id);

        if (!$branch) {
            return response()->json(['success' => false, 'message' => 'Branch not found'], 404);
        }

        $validated = $request->validate([
            'code'             => 'sometimes|string|unique:branches,code,' . $id,
            'name'             => 'sometimes|string',
            'address'          => 'sometimes|string',
            'lat'              => 'sometimes|numeric',
            'lng'              => 'sometimes|numeric',
            'handover_radius'  => 'sometimes|integer|min:0',
            'contact_name'     => 'nullable|string',
            'contact_phone'    => 'nullable|string',
            'contact_email'    => 'nullable|email',
            'is_hq'            => 'boolean',
            'is_active'        => 'boolean',
        ]);

        $branch->update($validated);

        return response()->json(['success' => true, 'data' => $branch]);
    }

    public function directory()
    {
        $branches = Branch::where('is_active', true)
            ->select('id', 'code', 'name', 'address', 'lat', 'lng', 'is_hq')
            ->orderBy('is_hq', 'desc')
            ->orderBy('name')
            ->get();

        return response()->json(['success' => true, 'data' => $branches]);
    }

    public function destroy(string $id)
    {
        $branch = Branch::find($id);

        if (!$branch) {
            return response()->json(['success' => false, 'message' => 'Branch not found'], 404);
        }

        $branch->delete();

        return response()->json(['success' => true, 'message' => 'Branch deleted']);
    }
}
