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
        $data = $request->validated();

        if ($violation = $this->escalationViolation(null, (int) $data['role_id'])) {
            return back()->withInput()->with('error', $violation);
        }

        User::create($data);

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
        $newRoleId = (int) $data['role_id'];

        if (empty($data['password'])) {
            unset($data['password']);
        }

        if ($violation = $this->escalationViolation($user, $newRoleId)) {
            return back()->withInput()->with('error', $violation);
        }

        if ($this->wouldLoseLastAdmin($user, $newRoleId)) {
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

        if ($user->isAdmin() && ! $this->actorIsAdmin()) {
            return back()->with('error', 'Hanya administrator yang bisa menghapus akun administrator.');
        }

        if (! $this->actorIsAdmin() && $user->role && $user->role->privilegeRank() > (auth()->user()->role?->privilegeRank() ?? 0)) {
            return back()->with('error', 'Anda tidak boleh menghapus akun dengan hak akses lebih tinggi dari role Anda.');
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
     * Apakah pelaku request adalah administrator (role is_admin atau akun warisan).
     */
    private function actorIsAdmin(): bool
    {
        $actor = auth()->user();

        return $actor instanceof User && $actor->isAdmin();
    }

    /**
     * Guard eskalasi hak akses (QA F4-1 BUG-01). Pelaku non-admin, meski punya
     * permission users:manage, tidak boleh:
     *  - memberi role administrator ke user mana pun (termasuk dirinya sendiri);
     *  - mengubah atau menghapus akun administrator;
     *  - memberi role yang hak aksesnya lebih tinggi dari role pelaku sendiri;
     *  - mengubah akun yang role-nya lebih tinggi dari role pelaku.
     *
     * @return string|null pesan penolakan; null bila tidak ada pelanggaran.
     */
    private function escalationViolation(?User $target, int $newRoleId): ?string
    {
        if ($this->actorIsAdmin()) {
            return null;
        }

        $newRole = Role::find($newRoleId);

        if ($newRole?->is_admin) {
            return 'Hanya administrator yang bisa memberi role administrator.';
        }

        if ($target !== null && $target->isAdmin()) {
            return 'Hanya administrator yang bisa mengubah akun administrator.';
        }

        $actorRank = auth()->user()->role?->privilegeRank() ?? 0;

        if (($newRole?->privilegeRank() ?? 0) > $actorRank) {
            return 'Role baru punya hak akses lebih tinggi dari role Anda.';
        }

        if ($target !== null && ($target->role?->privilegeRank() ?? 0) > $actorRank) {
            return 'Anda tidak boleh mengubah akun dengan hak akses lebih tinggi dari role Anda.';
        }

        return null;
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
     * Daftar role untuk form. Non-admin tidak offered role administrator —
     * guard di store/update akan menolaknya dengan pesan apa pun (UA savvy).
     *
     * @return Collection<int, Role>
     */
    private function roleOptions()
    {
        return Role::when(! $this->actorIsAdmin(), fn ($q) => $q->where('is_admin', false))
            ->orderBy('label')
            ->get();
    }
}
