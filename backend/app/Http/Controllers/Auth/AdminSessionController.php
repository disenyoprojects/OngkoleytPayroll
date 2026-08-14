<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AdminSessionController extends Controller {
    public function login(Request $request) {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::where('email', $credentials['email'])->first();

        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['These credentials do not match our records.'],
            ]);
        }

        return response()->json([
            'token' => $user->createToken('admin-session')->plainTextToken,
        ]);
    }

    public function logout(Request $request) {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Logged out.']);
    }

    public function me(Request $request) {
        $user = $request->user()->loadMissing(['branch', 'branches']);
        $branchIds = $user->scopedBranchIds();

        return response()->json([
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->isAdmin() ? 'admin' : 'branch',
            'branch_id' => $user->branch_id,
            'branch' => $user->branch?->name,
            // Every branch this login covers — one account can span several
            // sites, so the UI shows the group rather than a single name.
            'branch_ids' => $branchIds,
            'branches' => $branchIds === null ? null : $user->branches->pluck('name')->values(),
        ]);
    }
}
