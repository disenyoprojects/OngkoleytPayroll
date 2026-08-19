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

    /**
     * Delete a login. Two ways out of this leave nobody able to administer the
     * system, so both are refused: removing yourself, and removing the last
     * full-access login. Either would need a shell on the server to undo.
     */
    public function destroy(Request $request, User $user) {
        if ($request->user()->id === $user->id) {
            return response()->json([
                'message' => 'You cannot remove the login you are signed in with.',
            ], 422);
        }

        if ($user->isAdmin() && User::where('role', 'admin')->orWhereNull('role')->count() <= 1) {
            return response()->json([
                'message' => 'This is the last full-access login — removing it would lock everyone out.',
            ], 422);
        }

        $email = $user->email;
        $user->branches()->detach();
        $user->delete();

        return response()->json(['message' => "Removed the login {$email}."]);
    }
}
