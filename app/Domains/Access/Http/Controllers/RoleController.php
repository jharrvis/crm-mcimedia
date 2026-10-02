<?php

namespace App\Domains\Access\Http\Controllers;

use App\Domains\Access\Enums\AccessLevel;
use App\Domains\Access\Enums\Module;
use App\Domains\Access\Http\Requests\RoleRequest;
use App\Domains\Access\Models\Role;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class RoleController extends Controller
{
    public function index(): View
    {
        $roles = Role::withCount('users')->orderByDesc('is_admin')->orderBy('label')->get();

        return view('roles.index', compact('roles'));
    }

    public function create(): View
    {
        return view('roles.create', $this->formData(new Role));
    }

    public function store(RoleRequest $request): RedirectResponse
    {
        Role::create($request->validated());

        return redirect()->route('roles.index')->with('success', 'Role berhasil ditambahkan.');
    }

    public function edit(Role $role): View
    {
        return view('roles.edit', $this->formData($role));
    }

    public function update(RoleRequest $request, Role $role): RedirectResponse
    {
        $role->update($request->validated());

        return redirect()->route('roles.index')->with('success', 'Role berhasil diperbarui.');
    }

    public function destroy(Role $role): RedirectResponse
    {
        if ($role->is_admin) {
            return back()->with('error', 'Role administrator tidak bisa dihapus.');
        }

        if ($role->users()->exists()) {
            return back()->with('error', 'Role masih dipakai user. Pindahkan user ke role lain terlebih dahulu.');
        }

        $role->delete();

        return redirect()->route('roles.index')->with('success', 'Role berhasil dihapus.');
    }

    /**
     * @return array<string, mixed>
     */
    private function formData(Role $role): array
    {
        return [
            'role' => $role,
            'modules' => Module::cases(),
            'levels' => AccessLevel::cases(),
        ];
    }
}
