<?php

namespace App\Http\Controllers\Api;

use App\Services\FilipinoNeuralTtsService;
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
    public function synthesize(Request $request, FilipinoNeuralTtsService $filipinoTts): Response|JsonResponse
    {
        $validated = $request->validate([
            'text' => ['required', 'string', 'max:500'],
        ]);

        $text = $filipinoTts->normalizeFilipinoSpeechText($validated['text']);
        if ($text === '') {
            return response()->json([
                'message' => 'Text cannot be empty.',
            ], 422);
        }

        $cfg = config('services.elevenlabs', []);
        $apiKey = trim((string) ($cfg['api_key'] ?? ''));
        $primaryVoiceId = trim((string) ($cfg['voice_id'] ?? 'MIWz5nzn9F2CYPIedIPx'));
        $fallbackVoiceId = trim((string) ($cfg['fallback_voice_id'] ?? 'EXAVITQu4vr4xnSDxMaL'));
        $modelId = trim((string) ($cfg['model_id'] ?? 'eleven_multilingual_v2'));
        $outputFormat = trim((string) ($cfg['output_format'] ?? 'mp3_44100_128'));
        $cacheDir = rtrim((string) ($cfg['cache_dir'] ?? storage_path('app/public/ai_voice')), '/\\');
        $nativeFilVoice = 'fil-PH-BlessicaNeural';

        // Prioritize genuine native Filipino synthesis:
        // 1) Configured primary ElevenLabs voice (e.g., native Filipino library voice if paid plan active).
        // 2) Native Filipino neural teacher voice (fil-PH-BlessicaNeural) — zero English accent.
        // 3) ElevenLabs fallback voice with explicit Filipino language hint (last-resort fallback).
        $primaryBlocked = $apiKey === '' || (bool) Cache::get("elevenlabs_voice_402_{$primaryVoiceId}", false);
        if (!$primaryBlocked && $primaryVoiceId !== '') {
            $primaryResult = $this->tryElevenLabs($apiKey, $primaryVoiceId, $modelId, $outputFormat, $cacheDir, $cfg, $text);
            if ($primaryResult !== null) {
                return $primaryResult;
            }
        }

        // Serve native Filipino neural voice (fil-PH-BlessicaNeural) with disk cache.
        $filHash = sha1("v2|{$nativeFilVoice}|{$text}");
        $filCachePath = $cacheDir . DIRECTORY_SEPARATOR . $filHash . '.mp3';
        if (is_file($filCachePath) && filesize($filCachePath) > 128) {
            $cachedBytes = File::get($filCachePath);
            if (is_string($cachedBytes) && strlen($cachedBytes) > 128) {
                return $this->audioResponse($cachedBytes, $nativeFilVoice, 'hit', true);
            }
        }

        $filAudio = $filipinoTts->synthesize($text, $nativeFilVoice);
        if (is_string($filAudio) && strlen($filAudio) > 128) {
            $this->writeCacheSafely($cacheDir, $filCachePath, $filAudio);
            return $this->audioResponse($filAudio, $nativeFilVoice, 'miss', true);
        }

        // Deep fallback: pre-made ElevenLabs voice explicitly using flash_v2_5 + language_code=fil
        if ($apiKey !== '' && $fallbackVoiceId !== '') {
            $fbResult = $this->tryElevenLabs($apiKey, $fallbackVoiceId, 'eleven_flash_v2_5', $outputFormat, $cacheDir, $cfg, $text, 'fil', true);
            if ($fbResult !== null) {
                return $fbResult;
            }
        }

        return response()->json([
            'message' => 'AI voice service temporarily unavailable.',
        ], 503);
    }

    private function tryElevenLabs(
        string $apiKey,
        string $voiceId,
        string $modelId,
        string $outputFormat,
        string $cacheDir,
        array $cfg,
        string $text,
        ?string $languageCode = null,
        bool $isFallback = false,
    ): ?Response {
        $hash = sha1("v2|{$voiceId}|{$modelId}|" . ($languageCode ?? 'auto') . "|{$outputFormat}|{$text}");
        $cachePath = $cacheDir . DIRECTORY_SEPARATOR . $hash . '.mp3';

        if (is_file($cachePath) && filesize($cachePath) > 128) {
            $cachedBytes = File::get($cachePath);
            if (is_string($cachedBytes) && strlen($cachedBytes) > 128) {
                return $this->audioResponse($cachedBytes, $voiceId, 'hit', $isFallback);
            }
        }

        $payload = [
            'text' => $text,
            'model_id' => $modelId,
            'voice_settings' => [
                'stability' => (float) ($cfg['stability'] ?? 0.45),
                'similarity_boost' => (float) ($cfg['similarity_boost'] ?? 0.80),
                'style' => (float) ($cfg['style'] ?? 0.35),
                'use_speaker_boost' => true,
            ],
        ];
        if ($languageCode !== null && $languageCode !== '') {
            $payload['language_code'] = $languageCode;
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
                    $payload
                );

            if ($response->successful()) {
                $audioBytes = $response->body();
                if (strlen($audioBytes) > 128) {
                    $this->writeCacheSafely($cacheDir, $cachePath, $audioBytes);
                    return $this->audioResponse($audioBytes, $voiceId, 'miss', $isFallback);
                }
            }

            $status = $response->status();
            $bodyJson = $response->json();
            $code = is_array($bodyJson) ? ($bodyJson['detail']['code'] ?? '') : '';
            $message = is_array($bodyJson) ? (string) ($bodyJson['detail']['message'] ?? '') : '';

            if (!$isFallback && ($status === 402 || $code === 'paid_plan_required')) {
                Cache::put("elevenlabs_voice_402_{$voiceId}", true, now()->addMinutes(15));
                Log::info('ElevenLabs primary library voice requires paid plan; switching to native Filipino neural voice.', [
                    'primary_voice_id' => $voiceId,
                ]);
                return null;
            }

            Log::warning('ElevenLabs TTS request failed', [
                'voice_id' => $voiceId,
                'status' => $status,
                'code' => $code,
                'message' => $message,
            ]);
        } catch (Throwable $e) {
            Log::warning('ElevenLabs TTS request exception', [
                'voice_id' => $voiceId,
                'error' => $e->getMessage(),
            ]);
        }

        return null;
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

