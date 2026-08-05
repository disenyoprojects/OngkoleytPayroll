<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

/** Admin-only management of the admin + branch logins (see routes: admin.only). */
class UserController extends Controller {
    public function index() {
        return response()->json(
            User::with('branch')->orderByRaw("role = 'admin' desc")->orderBy('email')->get()
                ->map(fn (User $u) => [
                    'id' => $u->id,
                    'name' => $u->name,
                    'email' => $u->email,
                    'role' => $u->isAdmin() ? 'admin' : 'branch',
                    'branch' => $u->branch?->name,
                ])
        );
    }

    public function updatePassword(Request $request, User $user) {
        $data = $request->validate([
            'password' => ['required', 'string', 'min:8', 'max:255'],
        ]);

        $user->update(['password' => Hash::make($data['password'])]);

        return response()->json(['message' => "Password updated for {$user->email}."]);
    }
}
