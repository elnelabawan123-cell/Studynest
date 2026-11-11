<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class AiVoiceController extends BaseApiController
{
    public function synthesize(Request $request): Response|JsonResponse
    {
        $validated = $request->validate([
            'text' => ['required', 'string', 'max:500'],
        ]);

        $text = preg_replace('/\s+/u', ' ', trim($validated['text'])) ?? '';
        if ($text === '') {
            return response()->json([
                'message' => 'Text cannot be empty.',
            ], 422);
        }

        $cfg = config('services.elevenlabs', []);
        $apiKey = trim((string) ($cfg['api_key'] ?? ''));
        if ($apiKey === '') {
            return response()->json([
                'message' => 'AI voice service is not configured.',
            ], 503);
        }

        $primaryVoiceId = trim((string) ($cfg['voice_id'] ?? 'MIWz5nzn9F2CYPIedIPx'));
        $fallbackVoiceId = trim((string) ($cfg['fallback_voice_id'] ?? 'EXAVITQu4vr4xnSDxMaL'));
        $modelId = trim((string) ($cfg['model_id'] ?? 'eleven_multilingual_v2'));
        $outputFormat = trim((string) ($cfg['output_format'] ?? 'mp3_44100_128'));
        $cacheDir = rtrim((string) ($cfg['cache_dir'] ?? storage_path('app/public/ai_voice')), '/\\');

        $voiceCandidates = [$primaryVoiceId];
        $primaryBlocked = Cache::get("elevenlabs_voice_402_{$primaryVoiceId}", false);
        if ($primaryBlocked && $fallbackVoiceId !== '' && $fallbackVoiceId !== $primaryVoiceId) {
            $voiceCandidates = [$fallbackVoiceId];
        } elseif ($fallbackVoiceId !== '' && $fallbackVoiceId !== $primaryVoiceId) {
            $voiceCandidates[] = $fallbackVoiceId;
        }

        $lastStatus = 502;
        $lastMessage = 'Unable to synthesize AI voice.';

        foreach ($voiceCandidates as $index => $voiceId) {
            if ($voiceId === '') {
                continue;
            }

            $hash = sha1("{$voiceId}|{$modelId}|{$outputFormat}|{$text}");
            $cachePath = $cacheDir . DIRECTORY_SEPARATOR . $hash . '.mp3';

            if (is_file($cachePath) && filesize($cachePath) > 128) {
                $cachedBytes = File::get($cachePath);
                if (is_string($cachedBytes) && strlen($cachedBytes) > 128) {
                    return $this->audioResponse(
                        $cachedBytes,
                        $voiceId,
                        'hit',
                        $voiceId !== $primaryVoiceId
                    );
                }
            }

            try {
                $response = Http::timeout(12)
                    ->withHeaders([
                        'xi-api-key' => $apiKey,
                        'Accept' => 'audio/mpeg',
                        'Content-Type' => 'application/json',
                    ])
                    ->post(
                        "https://api.elevenlabs.io/v1/text-to-speech/{$voiceId}?output_format=" . urlencode($outputFormat),
                        [
                            'text' => $text,
                            'model_id' => $modelId,
                            'voice_settings' => [
                                'stability' => (float) ($cfg['stability'] ?? 0.45),
                                'similarity_boost' => (float) ($cfg['similarity_boost'] ?? 0.80),
                                'style' => (float) ($cfg['style'] ?? 0.35),
                                'use_speaker_boost' => true,
                            ],
                        ]
                    );

                if ($response->successful()) {
                    $audioBytes = $response->body();
                    if (strlen($audioBytes) > 128) {
                        $this->writeCacheSafely($cacheDir, $cachePath, $audioBytes);
                        return $this->audioResponse(
                            $audioBytes,
                            $voiceId,
                            'miss',
                            $voiceId !== $primaryVoiceId
                        );
                    }
                }

                $status = $response->status();
                $lastStatus = $status >= 400 ? $status : 502;
                $bodyJson = $response->json();
                $code = is_array($bodyJson) ? ($bodyJson['detail']['code'] ?? '') : '';
                $lastMessage = is_array($bodyJson)
                    ? (string) ($bodyJson['detail']['message'] ?? $lastMessage)
                    : $lastMessage;

                if ($index === 0 && ($status === 402 || $code === 'paid_plan_required')) {
                    Cache::put("elevenlabs_voice_402_{$voiceId}", true, now()->addMinutes(15));
                    Log::info('ElevenLabs primary library voice requires paid plan; using fallback voice.', [
                        'primary_voice_id' => $voiceId,
                        'fallback_voice_id' => $fallbackVoiceId,
                    ]);
                    continue;
                }

                Log::warning('ElevenLabs TTS request failed', [
                    'voice_id' => $voiceId,
                    'status' => $status,
                    'code' => $code,
                    'message' => $lastMessage,
                ]);
                break;
            } catch (Throwable $e) {
                Log::warning('ElevenLabs TTS request exception', [
                    'voice_id' => $voiceId,
                    'error' => $e->getMessage(),
                ]);
                $lastStatus = 503;
                $lastMessage = 'ElevenLabs service temporarily unavailable.';
                break;
            }
        }

        return response()->json([
            'message' => $lastMessage,
        ], $lastStatus === 401 || $lastStatus === 402 || $lastStatus === 429 ? 503 : $lastStatus);
    }

    private function writeCacheSafely(string $cacheDir, string $cachePath, string $audioBytes): void
    {
        try {
            File::ensureDirectoryExists($cacheDir);
            $tmpPath = $cachePath . '.tmp.' . uniqid('', true);
            if (@file_put_contents($tmpPath, $audioBytes) !== false) {
                if (!@rename($tmpPath, $cachePath)) {
                    @copy($tmpPath, $cachePath);
                    @unlink($tmpPath);
                }
            }
        } catch (Throwable $e) {
            Log::debug('Could not persist AI voice cache file', ['error' => $e->getMessage()]);
        }
    }

    private function audioResponse(string $audioBytes, string $voiceId, string $cacheStatus, bool $usedFallback): Response
    {
        return response($audioBytes, 200, [
            'Content-Type' => 'audio/mpeg',
            'Content-Length' => (string) strlen($audioBytes),
            'Cache-Control' => 'public, max-age=604800',
            'X-AI-Voice-Id' => $voiceId,
            'X-AI-Voice-Cache' => $cacheStatus,
            'X-AI-Voice-Fallback' => $usedFallback ? '1' : '0',
        ]);
    }
}

