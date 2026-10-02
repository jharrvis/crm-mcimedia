<?php

namespace Tests\Feature\Access;

use App\Domains\Access\Enums\Module;
use App\Domains\Access\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    private function actingAdmin(): User
    {
        return tap(User::factory()->create(), fn (User $u) => $this->actingAs($u));
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('users.index'))->assertRedirect(route('login'));
    }

    public function test_user_without_users_access_is_forbidden(): void
    {
        $role = Role::factory()->views([Module::Clients])->create();
        $this->actingAs(User::factory()->create(['role_id' => $role->id]));

        $this->get(route('users.index'))->assertForbidden();
    }

    public function test_can_create_user_with_role_and_hashed_password(): void
    {
        $this->actingAdmin();
        $role = Role::factory()->views([Module::Clients])->create();

        $response = $this->post(route('users.store'), [
            'name' => 'Siti Operator',
            'email' => 'siti@contoh.id',
            'role_id' => $role->id,
            'password' => 'rahasia-kuat-123',
            'password_confirmation' => 'rahasia-kuat-123',
        ]);

        $response->assertRedirect(route('users.index'));

        $user = User::where('email', 'siti@contoh.id')->firstOrFail();
        $this->assertSame($role->id, $user->role_id);
        $this->assertTrue(Hash::check('rahasia-kuat-123', $user->password));
        $this->assertNotSame('rahasia-kuat-123', $user->password);
    }

    public function test_required_fields_and_unique_email(): void
    {
        $this->actingAdmin();
        Role::factory()->create(['name' => 'staf']);
        User::factory()->create(['email' => 'ada@contoh.id']);

        $this->post(route('users.store'), [
            'name' => '',
            'email' => 'ada@contoh.id',
            'role_id' => '',
            'password' => 'pendek',
            'password_confirmation' => 'beda',
        ])->assertSessionHasErrors(['name', 'email', 'role_id', 'password']);
    }

    public function test_password_is_required_on_create(): void
    {
        $this->actingAdmin();
        $role = Role::factory()->create();

        $this->post(route('users.store'), [
            'name' => 'Tanpa Sandi',
            'email' => 'ts@contoh.id',
            'role_id' => $role->id,
        ])->assertSessionHasErrors('password');
    }

    public function test_can_update_user_and_blank_password_keeps_old_one(): void
    {
        $this->actingAdmin();
        $role = Role::factory()->create();
        $user = User::factory()->create(['name' => 'Lama']);
        $originalHash = $user->password;

        $this->put(route('users.update', $user), [
            'name' => 'Baru',
            'email' => $user->email,
            'role_id' => $role->id,
            'password' => '',
            'password_confirmation' => '',
        ])->assertRedirect(route('users.index'));

        $user->refresh();
        $this->assertSame('Baru', $user->name);
        $this->assertSame($role->id, $user->role_id);
        $this->assertSame($originalHash, $user->password);
    }

    public function test_password_can_be_changed_on_update(): void
    {
        $this->actingAdmin();
        $role = Role::factory()->create();
        $user = User::factory()->create();

        $this->put(route('users.update', $user), [
            'name' => $user->name,
            'email' => $user->email,
            'role_id' => $role->id,
            'password' => 'sandi-baru-456',
            'password_confirmation' => 'sandi-baru-456',
        ])->assertRedirect(route('users.index'));

        $this->assertTrue(Hash::check('sandi-baru-456', $user->fresh()->password));
    }

    public function test_cannot_delete_self(): void
    {
        $admin = $this->actingAdmin();

        $this->delete(route('users.destroy', $admin))->assertSessionHas('error');

        $this->assertDatabaseHas('users', ['id' => $admin->id]);
    }

    public function test_cannot_delete_last_administrator(): void
    {
        $adminRole = Role::factory()->admin()->create();
        $target = User::factory()->create(['role_id' => $adminRole->id]); // satu-satunya admin

        // Pelaku punya akses modul users (bukan admin) sehingga lolos gate,
        // tetapi tidak boleh menghapus admin terakhir.
        $manager = Role::factory()->manages([Module::Users])->create();
        $this->actingAs(User::factory()->create(['role_id' => $manager->id]));

        $this->delete(route('users.destroy', $target))->assertSessionHas('error');

        $this->assertDatabaseHas('users', ['id' => $target->id]);
    }

    public function test_can_delete_other_non_admin_user(): void
    {
        $this->actingAdmin();
        $role = Role::factory()->create();
        $user = User::factory()->create(['role_id' => $role->id]);

        $this->delete(route('users.destroy', $user))->assertRedirect(route('users.index'));

        $this->assertDatabaseMissing('users', ['id' => $user->id]);
    }

    public function test_cannot_remove_last_admin_role_via_update(): void
    {
        $staf = Role::factory()->create(['name' => 'staf']);
        $admin = User::factory()->create(); // tanpa role = admin warisan, satu-satunya

        $this->actingAs($admin);
        // Buat admin kedua supaya bisa bertindak... tapi admin hanya satu,
        // jadi menghapus dirinya dari admin tidak diizinkan.
        $this->put(route('users.update', $admin), [
            'name' => $admin->name,
            'email' => $admin->email,
            'role_id' => $staf->id,
        ])->assertSessionHas('error');

        $this->assertNull($admin->fresh()->role_id);
    }

    /*
    |--------------------------------------------------------------------------
    | Guard eskalasi hak akses (QA F4-1 BUG-01)
    |--------------------------------------------------------------------------
    |
    | Non-admin dengan permission users:manage tidak boleh mempromosikan
    | dirinya / user lain ke role administrator, menurunkan akun administrator,
    | maupun menghapus akun administrator. Hanya administrator yang boleh.
    |
    */

    private function actingUserManager(): User
    {
        $manager = Role::factory()->manages([Module::Users])->create();

        return tap(User::factory()->create(['role_id' => $manager->id]), fn (User $u) => $this->actingAs($u));
    }

    public function test_non_admin_manager_cannot_create_admin_user(): void
    {
        $this->actingUserManager();
        $adminRole = Role::factory()->admin()->create();

        $this->post(route('users.store'), [
            'name' => 'Naik Kelas',
            'email' => 'naik@contoh.id',
            'role_id' => $adminRole->id,
            'password' => 'rahasia-kuat-123',
            'password_confirmation' => 'rahasia-kuat-123',
        ])->assertSessionHas('error');

        $this->assertDatabaseMissing('users', ['email' => 'naik@contoh.id']);
    }

    public function test_non_admin_manager_cannot_promote_user_to_admin(): void
    {
        $this->actingUserManager();
        $adminRole = Role::factory()->admin()->create();
        $target = User::factory()->create(['role_id' => Role::factory()->create()->id]);

        $this->put(route('users.update', $target), [
            'name' => $target->name,
            'email' => $target->email,
            'role_id' => $adminRole->id,
        ])->assertSessionHas('error');

        $this->assertFalse($target->fresh()->isAdmin());
    }

    public function test_non_admin_manager_cannot_promote_self_to_admin(): void
    {
        $actor = $this->actingUserManager();
        $adminRole = Role::factory()->admin()->create();

        $this->put(route('users.update', $actor), [
            'name' => $actor->name,
            'email' => $actor->email,
            'role_id' => $adminRole->id,
        ])->assertSessionHas('error');

        $this->assertFalse($actor->fresh()->isAdmin());
    }

    public function test_non_admin_manager_cannot_demote_admin(): void
    {
        $this->actingUserManager();
        $adminRole = Role::factory()->admin()->create();
        $staf = Role::factory()->create(['name' => 'staf']);
        User::factory()->create(['role_id' => $adminRole->id]); // admin kedua supaya bukan sebatas lockout admin terakhir
        $admin = User::factory()->create(['role_id' => $adminRole->id]);

        $this->put(route('users.update', $admin), [
            'name' => 'Baru',
            'email' => $admin->email,
            'role_id' => $staf->id,
        ])->assertSessionHas('error');

        $this->assertSame($adminRole->id, $admin->fresh()->role_id);
    }

    public function test_non_admin_manager_cannot_delete_admin(): void
    {
        $this->actingUserManager();
        $adminRole = Role::factory()->admin()->create();
        User::factory()->create(['role_id' => $adminRole->id]); // admin kedua, jadi bukan "terakhir"
        $target = User::factory()->create(['role_id' => $adminRole->id]);

        $this->delete(route('users.destroy', $target))->assertSessionHas('error');

        $this->assertDatabaseHas('users', ['id' => $target->id]);
    }

    public function test_non_admin_manager_cannot_assign_higher_privilege_role(): void
    {
        $this->actingUserManager(); // kelola users saja
        $higher = Role::factory()->manages([Module::Users, Module::Invoices, Module::Clients])->create();
        $target = User::factory()->create(['role_id' => Role::factory()->create()->id]);

        $this->put(route('users.update', $target), [
            'name' => $target->name,
            'email' => $target->email,
            'role_id' => $higher->id,
        ])->assertSessionHas('error');

        $this->assertNotSame($higher->id, $target->fresh()->role_id);
    }

    public function test_non_admin_manager_cannot_update_higher_privilege_user(): void
    {
        $this->actingUserManager(); // kelola users saja (rank rendah)
        $higher = Role::factory()->manages([Module::Invoices, Module::Clients])->create();
        $target = User::factory()->create(['role_id' => $higher->id]);

        // Pelaku mencoba mengubah user yang role-nya lebih tinggi, walau
        // role_id tujuan tetap (rank sama / tidak lebih tinggi).
        $this->put(route('users.update', $target), [
            'name' => 'Baru',
            'email' => $target->email,
            'role_id' => $higher->id,
        ])->assertSessionHas('error');

        $this->assertNotSame('Baru', $target->fresh()->name);
    }

    public function test_non_admin_manager_can_assign_equal_or_lower_privilege_role(): void
    {
        $this->actingUserManager(); // rank 2 (kelola users)
        $equal = Role::factory()->manages([Module::Users])->create(); // rank 2
        $target = User::factory()->create(['role_id' => Role::factory()->create()->id]);

        $this->put(route('users.update', $target), [
            'name' => 'Setara',
            'email' => $target->email,
            'role_id' => $equal->id,
        ])->assertRedirect(route('users.index'));

        $this->assertSame($equal->id, $target->fresh()->role_id);
    }

    public function test_non_admin_manager_cannot_delete_higher_privilege_user(): void
    {
        $this->actingUserManager(); // rank 2
        $higher = Role::factory()->manages([Module::Invoices, Module::Clients])->create(); // rank 6
        $target = User::factory()->create(['role_id' => $higher->id]);

        $this->delete(route('users.destroy', $target))->assertSessionHas('error');

        $this->assertDatabaseHas('users', ['id' => $target->id]);
    }

    public function test_admin_can_still_assign_admin_role(): void
    {
        $this->actingAdmin();
        $adminRole = Role::factory()->admin()->create();
        $target = User::factory()->create();

        $this->put(route('users.update', $target), [
            'name' => $target->name,
            'email' => $target->email,
            'role_id' => $adminRole->id,
        ])->assertRedirect(route('users.index'));

        $this->assertTrue($target->fresh()->isAdmin());
    }

    public function test_non_admin_manager_can_still_update_non_admin_user(): void
    {
        $this->actingUserManager();
        $role = Role::factory()->create();
        $target = User::factory()->create(['role_id' => Role::factory()->create()->id]);

        $this->put(route('users.update', $target), [
            'name' => 'Diperbarui',
            'email' => $target->email,
            'role_id' => $role->id,
        ])->assertRedirect(route('users.index'));

        $target->refresh();
        $this->assertSame('Diperbarui', $target->name);
        $this->assertSame($role->id, $target->role_id);
    }
}
