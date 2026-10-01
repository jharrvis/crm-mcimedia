<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_change_password(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $this->get('/profile/password')->assertOk();

        $this->put('/profile/password', [
            'current_password' => 'password',
            'password' => 'kata-sandi-baru-123',
            'password_confirmation' => 'kata-sandi-baru-123',
        ])->assertSessionHas('success');

        $this->assertTrue(Hash::check('kata-sandi-baru-123', $user->fresh()->password));
    }

    public function test_password_change_fails_with_wrong_current_password(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $this->put('/profile/password', [
            'current_password' => 'salah',
            'password' => 'kata-sandi-baru-123',
            'password_confirmation' => 'kata-sandi-baru-123',
        ])->assertSessionHasErrors('current_password');
    }
}
