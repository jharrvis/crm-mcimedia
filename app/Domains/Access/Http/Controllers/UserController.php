<?php

namespace App\Domains\Access\Http\Controllers;

use App\Domains\Access\Http\Requests\UserRequest;
use App\Domains\Access\Models\Role;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class UserController extends Controller
{
    public function index(Request $request): View
    {
        $users = User::with('role')
            ->when($request->filled('q'), fn ($q) => $q->where(
                fn ($q) => $q->where('name', 'like', '%'.$request->string('q').'%')
                    ->orWhere('email', 'like', '%'.$request->string('q').'%')
            ))
            ->orderBy('name')
            ->paginate(15)
            ->withQueryString();

        return view('users.index', [
            'users' => $users,
            'adminCount' => $this->adminCount(),
        ]);
    }

    public function create(): View
    {
        return view('users.create', [
            'user' => new User,
            'roles' => $this->roleOptions(),
        ]);
    }

    public function store(UserRequest $request): RedirectResponse
    {
        User::create($request->validated());

        return redirect()->route('users.index')->with('success', 'User berhasil ditambahkan.');
    }

    public function edit(User $user): View
    {
        return view('users.edit', [
            'user' => $user,
            'roles' => $this->roleOptions(),
        ]);
    }

    public function update(UserRequest $request, User $user): RedirectResponse
    {
        $data = $request->validated();

        if (empty($data['password'])) {
            unset($data['password']);
        }

        if ($this->wouldLoseLastAdmin($user, (int) $data['role_id'])) {
            return back()->withInput()->with('error', 'Perubahan dibatalkan: ini satu-satunya akun administrator.');
        }

        $user->update($data);

        return redirect()->route('users.index')->with('success', 'User berhasil diperbarui.');
    }

    public function destroy(User $user): RedirectResponse
    {
        if ($user->is(auth()->user())) {
            return back()->with('error', 'Anda tidak bisa menghapus akun sendiri.');
        }

        if ($user->isAdmin() && $this->adminCount() <= 1) {
            return back()->with('error', 'Tidak bisa menghapus user administrator terakhir.');
        }

        $user->delete();

        return redirect()->route('users.index')->with('success', 'User berhasil dihapus.');
    }

    /**
     * Jumlah akun dengan akses penuh (role is_admin atau akun warisan tanpa role).
     */
    private function adminCount(): int
    {
        return User::where(fn ($q) => $q->whereNull('role_id')
            ->orWhereHas('role', fn ($role) => $role->where('is_admin', true)))
            ->count();
    }

    /**
     * Apakah mengubah user ke role baru akan menghabiskan administrator terakhir.
     */
    private function wouldLoseLastAdmin(User $user, int $newRoleId): bool
    {
        if (! $user->isAdmin()) {
            return false;
        }

        $targetIsAdmin = (bool) Role::whereKey($newRoleId)->value('is_admin');

        return ! $targetIsAdmin && $this->adminCount() <= 1;
    }

    /**
     * @return Collection<int, Role>
     */
    private function roleOptions()
    {
        return Role::orderBy('label')->get();
    }
}
