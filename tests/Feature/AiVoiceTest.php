<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AiVoiceTest extends TestCase
{
    use RefreshDatabase;

    private string $tempCacheDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempCacheDir = storage_path('framework/testing/ai_voice_' . uniqid('', true));
        config()->set('services.elevenlabs.api_key', 'test-elevenlabs-key');
        config()->set('services.elevenlabs.voice_id', 'MIWz5nzn9F2CYPIedIPx');
        config()->set('services.elevenlabs.fallback_voice_id', 'EXAVITQu4vr4xnSDxMaL');
        config()->set('services.elevenlabs.model_id', 'eleven_multilingual_v2');
        config()->set('services.elevenlabs.output_format', 'mp3_44100_128');
        config()->set('services.elevenlabs.cache_dir', $this->tempCacheDir);
        Cache::forget('elevenlabs_voice_402_MIWz5nzn9F2CYPIedIPx');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->tempCacheDir);
        parent::tearDown();
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->postJson('/api/ai-voice/synthesize', ['text' => 'Kumusta!'])
            ->assertStatus(401);
    }

    public function test_synthesizes_with_primary_voice_and_serves_cached_mp3_on_repeat(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);

        $user = User::create([
            'name' => 'Student Voice',
            'username' => 'student_voice_1',
            'email' => 'student.voice1@studynest.test',
            'password' => bcrypt('password'),
            'role' => 'student',
            'is_active' => true,
        ]);

        $fakeMp3 = str_repeat('ID3_FAKE_MP3_', 20);
        Http::fake([
            'https://api.elevenlabs.io/v1/text-to-speech/MIWz5nzn9F2CYPIedIPx*' => Http::response(
                $fakeMp3,
                200,
                ['Content-Type' => 'audio/mpeg']
            ),
        ]);

        $first = $this->actingAs($user, 'sanctum')
            ->postJson('/api/ai-voice/synthesize', ['text' => '  Kumusta,   mag-aaral!  ']);

        $first->assertStatus(200)
            ->assertHeader('Content-Type', 'audio/mpeg')
            ->assertHeader('X-AI-Voice-Id', 'MIWz5nzn9F2CYPIedIPx')
            ->assertHeader('X-AI-Voice-Cache', 'miss');
        $this->assertSame($fakeMp3, $first->getContent());

        // Second call with equivalent spacing hits disk cache instead of calling ElevenLabs again
        $second = $this->actingAs($user, 'sanctum')
            ->postJson('/api/ai-voice/synthesize', ['text' => 'Kumusta, mag-aaral!']);

        $second->assertStatus(200)
            ->assertHeader('X-AI-Voice-Cache', 'hit')
            ->assertHeader('X-AI-Voice-Id', 'MIWz5nzn9F2CYPIedIPx');
        $this->assertSame($fakeMp3, $second->getContent());

        Http::assertSentCount(1);
    }

    public function test_falls_back_cleanly_to_premade_voice_when_library_voice_requires_paid_plan(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);

        $user = User::create([
            'name' => 'Student Voice Two',
            'username' => 'student_voice_2',
            'email' => 'student.voice2@studynest.test',
            'password' => bcrypt('password'),
            'role' => 'student',
            'is_active' => true,
        ]);

        $fallbackMp3 = str_repeat('FALLBACK_MP3_', 20);
        Http::fake(function (ClientRequest $request) use ($fallbackMp3) {
            if (str_contains($request->url(), 'MIWz5nzn9F2CYPIedIPx')) {
                return Http::response([
                    'detail' => [
                        'type' => 'payment_required',
                        'code' => 'paid_plan_required',
                        'message' => 'Free users cannot use library voices via the API.',
                    ],
                ], 402);
            }
            if (str_contains($request->url(), 'EXAVITQu4vr4xnSDxMaL')) {
                return Http::response($fallbackMp3, 200, ['Content-Type' => 'audio/mpeg']);
            }
            return Http::response(['detail' => 'Unexpected URL'], 500);
        });

        $res = $this->actingAs($user, 'sanctum')
            ->postJson('/api/ai-voice/synthesize', ['text' => 'Buuin ang salitang pamilya!']);

        $res->assertStatus(200)
            ->assertHeader('Content-Type', 'audio/mpeg')
            ->assertHeader('X-AI-Voice-Id', 'EXAVITQu4vr4xnSDxMaL')
            ->assertHeader('X-AI-Voice-Fallback', '1');
        $this->assertSame($fallbackMp3, $res->getContent());
    }
}

