<?php

namespace Tests\Feature;

use App\Models\AdminSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Tests\TestCase;

class SecuritySettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_two_factor_login_requires_a_valid_totp_code_before_issuing_a_token(): void
    {
        $user = User::create([
            'name' => 'Test Admin',
            'email' => 'admin@example.test',
            'username' => 'test-admin',
            'password' => 'correct-password',
            'role' => 'admin',
        ]);
        $user->forceFill([
            'two_factor_enabled' => true,
            'two_factor_secret' => Crypt::encryptString('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ'),
        ])->save();

        $login = $this->postJson('/api/login', [
            'identifier' => 'test-admin',
            'password' => 'correct-password',
        ])->assertOk()
            ->assertJsonPath('data.two_factor_required', true);

        $challengeId = $login->json('data.challenge_id');
        $this->assertNotEmpty($challengeId);
        $this->assertDatabaseCount('personal_access_tokens', 0);

        $code = $this->currentCode('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ');
        $wrongCode = $code === '000000' ? '000001' : '000000';
        $this->postJson('/api/2fa/verify', [
            'challenge_id' => $challengeId,
            'code' => $wrongCode,
        ])->assertStatus(422);
        $this->assertDatabaseCount('personal_access_tokens', 0);

        $this->postJson('/api/2fa/verify', [
            'challenge_id' => $challengeId,
            'code' => $code,
        ])->assertOk()
            ->assertJsonPath('data.user.role', 'admin')
            ->assertJsonStructure(['data' => ['token']]);

        $this->assertDatabaseCount('personal_access_tokens', 1);
    }

    public function test_account_must_verify_authenticator_code_to_enable_two_factor(): void
    {
        $user = User::create([
            'name' => 'Test Teacher',
            'email' => 'teacher@example.test',
            'username' => 'test-teacher',
            'password' => 'correct-password',
            'role' => 'teacher',
        ]);

        $setup = $this->actingAs($user, 'sanctum')
            ->postJson('/api/2fa/enable', ['password' => 'correct-password'])
            ->assertOk()
            ->assertJsonStructure(['secret', 'otpauth_uri']);
        $secret = $setup->json('secret');

        $this->assertGreaterThan(255, strlen($user->fresh()->two_factor_secret));
        $this->assertNotSame($secret, $user->fresh()->two_factor_secret);
        $this->assertFalse($user->fresh()->two_factor_enabled);

        $this->postJson('/api/2fa/confirm-setup', [
            'code' => $this->currentCode($secret),
        ])->assertOk()->assertJsonPath('enabled', true);

        $this->assertTrue($user->fresh()->two_factor_enabled);
    }

    public function test_maintenance_mode_blocks_teacher_apis_but_allows_admin_apis(): void
    {
        AdminSetting::set('maintenance_mode', true);

        $teacher = User::create([
            'name' => 'Test Teacher',
            'email' => 'teacher@example.test',
            'username' => 'test-teacher',
            'password' => 'correct-password',
            'role' => 'teacher',
        ]);
        $admin = User::create([
            'name' => 'Test Admin',
            'email' => 'admin@example.test',
            'username' => 'test-admin',
            'password' => 'correct-password',
            'role' => 'admin',
        ]);

        $this->actingAs($teacher, 'sanctum')
            ->getJson('/api/teacher/me')
            ->assertStatus(503)
            ->assertJsonPath('maintenance', true);

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/settings')
            ->assertOk();
    }

    public function test_admin_settings_persist_and_multiple_admin_login_policy_is_applied(): void
    {
        $admin = User::create([
            'name' => 'Test Admin',
            'email' => 'admin@example.test',
            'username' => 'test-admin',
            'password' => 'correct-password',
            'role' => 'admin',
        ]);

        $this->actingAs($admin, 'sanctum')
            ->putJson('/api/settings', [
                'auto_section_assignment' => true,
                'teacher_registration' => true,
                'student_self_enrollment' => false,
                'admin_multi_login' => true,
                'activity_logs_enabled' => true,
                'email_notifications' => false,
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertTrue(AdminSetting::get('admin_multi_login'));
        $this->assertFalse(AdminSetting::get('email_notifications'));

        AdminSetting::set('school_year', '2026-2027');
        AdminSetting::set('grading_system', 'GPA');
        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/settings')
            ->assertOk()
            ->assertJsonMissingPath('settings.school_year')
            ->assertJsonMissingPath('settings.grading_system');

        $admin->createToken('existing-session');
        $this->postJson('/api/login', [
            'identifier' => 'test-admin',
            'password' => 'correct-password',
        ])->assertOk();

        $this->assertDatabaseCount('personal_access_tokens', 2);
    }

    private function currentCode(string $base32Secret): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $secret = '';
        $buffer = 0;
        $bits = 0;
        foreach (str_split($base32Secret) as $character) {
            $buffer = ($buffer << 5) | strpos($alphabet, $character);
            $bits += 5;
            if ($bits >= 8) {
                $bits -= 8;
                $secret .= chr(($buffer >> $bits) & 0xff);
                $buffer &= (1 << $bits) - 1;
            }
        }

        $counter = intdiv(time(), 30);
        $hash = hash_hmac(
            'sha1',
            pack('N2', ($counter >> 32) & 0xffffffff, $counter & 0xffffffff),
            $secret,
            true
        );
        $offset = ord($hash[19]) & 0x0f;
        $binary = unpack('N', substr($hash, $offset, 4))[1] & 0x7fffffff;

        return str_pad((string) ($binary % 1_000_000), 6, '0', STR_PAD_LEFT);
    }
}
