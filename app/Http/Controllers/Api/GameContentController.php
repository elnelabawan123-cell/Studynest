<?php

namespace App\Http\Controllers\Api;

use App\Models\GameContent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GameContentController extends BaseApiController
{
    public function showForStudent(string $gameKey): JsonResponse
    {
        $student = request()->user()->studentProfile;
        abort_unless($student, 403, 'No student profile found for this account.');

        return $this->resolveContentResponse($gameKey);
    }

    public function show(string $gameKey): JsonResponse
    {
        return $this->resolveContentResponse($gameKey);
    }

    public function update(Request $request, string $gameKey): JsonResponse
    {
        $validated = $request->validate([
            'grade' => 'sometimes|integer|min:1|max:6',
            'yunit' => 'sometimes|integer|min:0|max:10',
            'aralin' => 'sometimes|integer|min:0|max:30',
            'gameTitle' => 'nullable|string|max:255',
            'title' => 'nullable|string|max:255',
            'subtitle' => 'nullable|string|max:255',
            'welcome' => 'nullable|string|max:500',
            'instructions' => 'nullable|string|max:500',
            'completion' => 'nullable|string|max:500',
            'words' => 'required|array|min:1',
            'words.*.word' => 'required|string|max:100',
            'words.*.syllables' => 'required|array|min:2',
            'words.*.syllables.*' => 'required|string|max:40',
            'words.*.distractors' => 'nullable|array',
            'words.*.distractors.*' => 'required|string|max:40',
            'words.*.syllableCount' => 'nullable|integer|min:2|max:10',
            'words.*.syllable_count' => 'nullable|integer|min:2|max:10',
            'words.*.correctAnswer' => 'nullable|integer|min:2|max:10',
            'words.*.correct_answer' => 'nullable|integer|min:2|max:10',
            'words.*.voiceInstruction' => 'nullable|string|max:400',
            'words.*.voice_instruction' => 'nullable|string|max:400',
            'words.*.difficulty' => 'required|string|in:easy,medium,advanced',
            'words.*.round' => 'nullable|integer|min:1|max:5',
            'words.*.points' => 'nullable|integer|min:1|max:500',
            'words.*.bonusPoints' => 'nullable|integer|min:0|max:500',
            'words.*.bonus_points' => 'nullable|integer|min:0|max:500',
            'words.*.meaningHint' => 'nullable|string|max:400',
            'words.*.meaning_hint' => 'nullable|string|max:400',
        ]);

        [$parsedGrade, $parsedYunit, $parsedAralin] = $this->parseGameKey($gameKey);
        $grade = (int) ($validated['grade'] ?? $parsedGrade);
        $yunit = (int) ($validated['yunit'] ?? $parsedYunit);
        $aralin = (int) ($validated['aralin'] ?? $parsedAralin);

        $normalizedWords = [];
        foreach ($validated['words'] as $idx => $rawWord) {
            $word = strtoupper(preg_replace('/[\s-]+/', '', trim($rawWord['word'])));
            $syllables = array_values(array_map(
                fn($s) => strtoupper(trim((string) $s)),
                $rawWord['syllables']
            ));

            if (implode('', $syllables) !== $word) {
                return $this->error(
                    "Invalid Filipino syllable division at index {$idx}: '" . implode('-', $syllables) . "' does not reconstruct '{$word}'.",
                    422
                );
            }

            foreach ($syllables as $syl) {
                if (!preg_match('/[AEIOU]/', $syl)) {
                    return $this->error(
                        "Invalid Filipino syllable '{$syl}' in '{$word}': every pantig must contain a vowel.",
                        422
                    );
                }
            }

            $actualCount = count($syllables);
            $declaredCount = (int) ($rawWord['syllableCount'] ?? $rawWord['syllable_count'] ?? $actualCount);
            $correctAnswer = (int) ($rawWord['correctAnswer'] ?? $rawWord['correct_answer'] ?? $actualCount);
            if ($declaredCount !== $actualCount || $correctAnswer !== $actualCount) {
                return $this->error(
                    "Syllable count mismatch for '{$word}': expected {$actualCount} based on syllables.",
                    422
                );
            }

            $difficulty = strtolower($rawWord['difficulty']);
            $round = (int) ($rawWord['round'] ?? match ($difficulty) {
                'easy' => 1,
                'medium' => 2,
                default => 3,
            });

            $distractors = array_values(array_filter(
                array_unique(array_map(fn($d) => strtoupper(trim((string) $d)), $rawWord['distractors'] ?? [])),
                fn($d) => $d !== '' && !in_array($d, $syllables, true)
            ));

            $normalizedWords[] = [
                'word' => $word,
                'syllables' => $syllables,
                'distractors' => $distractors,
                'syllableCount' => $actualCount,
                'correctAnswer' => $actualCount,
                'voiceInstruction' => (string) ($rawWord['voiceInstruction'] ?? $rawWord['voice_instruction'] ?? "Piliin ang tamang pantig at ipatong ito sa tore para mabuo ang salitang {$word}!"),
                'difficulty' => $difficulty,
                'round' => $round,
                'points' => (int) ($rawWord['points'] ?? ($difficulty === 'easy' ? 20 : ($difficulty === 'medium' ? 25 : 30))),
                'bonusPoints' => (int) ($rawWord['bonusPoints'] ?? $rawWord['bonus_points'] ?? 15),
                'meaningHint' => (string) ($rawWord['meaningHint'] ?? $rawWord['meaning_hint'] ?? ''),
            ];
        }

        $defaults = self::defaultContent($gameKey);
        $title = $validated['gameTitle'] ?? $validated['title'] ?? ($defaults['gameTitle'] ?? 'Pantig Tower');

        $payload = [
            'gameKey' => $gameKey,
            'gameTitle' => $title,
            'subtitle' => $validated['subtitle'] ?? ($defaults['subtitle'] ?? 'Pagmamahal ng Isang Ama'),
            'welcome' => $validated['welcome'] ?? ($defaults['welcome'] ?? ''),
            'instructions' => $validated['instructions'] ?? ($defaults['instructions'] ?? ''),
            'completion' => $validated['completion'] ?? ($defaults['completion'] ?? ''),
            'words' => $normalizedWords,
        ];

        $record = GameContent::updateOrCreate(
            ['game_key' => $gameKey],
            [
                'grade' => $grade,
                'yunit' => $yunit,
                'aralin' => $aralin,
                'title' => $title,
                'content' => $payload,
                'updated_by' => $request->user()->id,
            ]
        );

        return $this->success([
            'game_key' => $record->game_key,
            'title' => $record->title,
            'source' => 'database',
            'content' => $record->content,
        ], 'Game content updated successfully.');
    }

    private function resolveContentResponse(string $gameKey): JsonResponse
    {
        $record = GameContent::where('game_key', $gameKey)->first();
        if ($record && is_array($record->content)) {
            return $this->success([
                'game_key' => $record->game_key,
                'title' => $record->title,
                'source' => 'database',
                'content' => $record->content,
            ]);
        }

        $default = self::defaultContent($gameKey);
        return $this->success([
            'game_key' => $gameKey,
            'title' => $default['gameTitle'] ?? $gameKey,
            'source' => 'default',
            'content' => $default,
        ]);
    }

    private function parseGameKey(string $gameKey): array
    {
        $parts = explode(':', $gameKey);
        if (count($parts) >= 3) {
            $grade = (int) preg_replace('/\D+/', '', $parts[0]);
            return [$grade > 0 ? $grade : 3, (int) $parts[1], (int) $parts[2]];
        }
        return [3, 0, 6];
    }

    public static function defaultContent(string $gameKey): array
    {
        return [
            'gameKey' => $gameKey,
            'gameTitle' => 'Pantig Tower',
            'subtitle' => 'Pagmamahal ng Isang Ama',
            'welcome' => 'Maligayang pagdating sa Pantig Tower! Buuin natin ang mga salita gamit ang tamang pantig!',
            'instructions' => 'Piliin ang tamang pantig at ipatong ito sa tore upang mabuo ang salita. Pagkatapos, bilangin kung ilang pantig ito!',
            'completion' => 'Napakahusay! Natutuhan mo na ang tamang pagpapantig sa Aralin 7: Pagmamahal ng Isang Ama!',
            'words' => [
                // EASY (Round 1 - 2 syllables)
                [
                    'word' => 'AMA',
                    'syllables' => ['A', 'MA'],
                    'distractors' => ['BA', 'TO'],
                    'syllableCount' => 2,
                    'correctAnswer' => 2,
                    'voiceInstruction' => 'Piliin ang tamang pantig at ipatong ito sa tore upang mabuo ang salitang AMA!',
                    'difficulty' => 'easy',
                    'round' => 1,
                    'points' => 20,
                    'bonusPoints' => 15,
                    'meaningHint' => 'Haligi ng tahanan na nagmamahal at nag-aalaga sa pamilya.',
                ],
                [
                    'word' => 'BATA',
                    'syllables' => ['BA', 'TA'],
                    'distractors' => ['MI', 'LO'],
                    'syllableCount' => 2,
                    'correctAnswer' => 2,
                    'voiceInstruction' => 'Piliin ang tamang pantig at ipatong ito sa tore upang mabuo ang salitang BATA!',
                    'difficulty' => 'easy',
                    'round' => 1,
                    'points' => 20,
                    'bonusPoints' => 15,
                    'meaningHint' => 'Munting anak na minamahal ng mga magulang.',
                ],
                [
                    'word' => 'BAHAY',
                    'syllables' => ['BA', 'HAY'],
                    'distractors' => ['KU', 'LA'],
                    'syllableCount' => 2,
                    'correctAnswer' => 2,
                    'voiceInstruction' => 'Piliin ang tamang pantig at ipatong ito sa tore upang mabuo ang salitang BAHAY!',
                    'difficulty' => 'easy',
                    'round' => 1,
                    'points' => 20,
                    'bonusPoints' => 15,
                    'meaningHint' => 'Lugar kung saan nakatira ang pamilya.',
                ],
                [
                    'word' => 'TATAY',
                    'syllables' => ['TA', 'TAY'],
                    'distractors' => ['PI', 'NO'],
                    'syllableCount' => 2,
                    'correctAnswer' => 2,
                    'voiceInstruction' => 'Piliin ang tamang pantig at ipatong ito sa tore upang mabuo ang salitang TATAY!',
                    'difficulty' => 'easy',
                    'round' => 1,
                    'points' => 20,
                    'bonusPoints' => 15,
                    'meaningHint' => 'Mapagmahal na ama ng tahanan.',
                ],
                // MEDIUM (Round 2 - 3 syllables)
                [
                    'word' => 'PAMILYA',
                    'syllables' => ['PA', 'MIL', 'YA'],
                    'distractors' => ['BA', 'TO', 'KO'],
                    'syllableCount' => 3,
                    'correctAnswer' => 3,
                    'voiceInstruction' => 'Piliin ang tamang pantig at ipatong ito sa tore upang mabuo ang salitang PAMILYA!',
                    'difficulty' => 'medium',
                    'round' => 2,
                    'points' => 25,
                    'bonusPoints' => 15,
                    'meaningHint' => 'Magkakasama sina Tatay, Nanay, at mga anak.',
                ],
                [
                    'word' => 'MAGULANG',
                    'syllables' => ['MA', 'GU', 'LANG'],
                    'distractors' => ['TA', 'SI', 'BO'],
                    'syllableCount' => 3,
                    'correctAnswer' => 3,
                    'voiceInstruction' => 'Piliin ang tamang pantig at ipatong ito sa tore upang mabuo ang salitang MAGULANG!',
                    'difficulty' => 'medium',
                    'round' => 2,
                    'points' => 25,
                    'bonusPoints' => 15,
                    'meaningHint' => 'Sina Ama at Ina na nag-aalaga sa atin.',
                ],
                [
                    'word' => 'MASAYA',
                    'syllables' => ['MA', 'SA', 'YA'],
                    'distractors' => ['PA', 'TO', 'BI'],
                    'syllableCount' => 3,
                    'correctAnswer' => 3,
                    'voiceInstruction' => 'Piliin ang tamang pantig at ipatong ito sa tore upang mabuo ang salitang MASAYA!',
                    'difficulty' => 'medium',
                    'round' => 2,
                    'points' => 25,
                    'bonusPoints' => 15,
                    'meaningHint' => 'Damdaming puno ng tuwa kasama ang mahal sa buhay.',
                ],
                [
                    'word' => 'LARUAN',
                    'syllables' => ['LA', 'RU', 'AN'],
                    'distractors' => ['KA', 'PI', 'MO'],
                    'syllableCount' => 3,
                    'correctAnswer' => 3,
                    'voiceInstruction' => 'Piliin ang tamang pantig at ipatong ito sa tore upang mabuo ang salitang LARUAN!',
                    'difficulty' => 'medium',
                    'round' => 2,
                    'points' => 25,
                    'bonusPoints' => 15,
                    'meaningHint' => 'Gamit sa paglalaro na ibinabahagi sa kapatid at kaibigan.',
                ],
                [
                    'word' => 'TAHANAN',
                    'syllables' => ['TA', 'HA', 'NAN'],
                    'distractors' => ['LU', 'GI', 'PO'],
                    'syllableCount' => 3,
                    'correctAnswer' => 3,
                    'voiceInstruction' => 'Piliin ang tamang pantig at ipatong ito sa tore upang mabuo ang salitang TAHANAN!',
                    'difficulty' => 'medium',
                    'round' => 2,
                    'points' => 25,
                    'bonusPoints' => 15,
                    'meaningHint' => 'Tirahan na puno ng pagmamahalan at pag-unawa.',
                ],
                [
                    'word' => 'KAWAYAN',
                    'syllables' => ['KA', 'WA', 'YAN'],
                    'distractors' => ['SI', 'MU', 'TO'],
                    'syllableCount' => 3,
                    'correctAnswer' => 3,
                    'voiceInstruction' => 'Piliin ang tamang pantig at ipatong ito sa tore upang mabuo ang salitang KAWAYAN!',
                    'difficulty' => 'medium',
                    'round' => 2,
                    'points' => 25,
                    'bonusPoints' => 15,
                    'meaningHint' => 'Matibay na halamang ginagamit sa paggawa ng saranggola at bahay.',
                ],
                // ADVANCED (Round 3 - 4+ syllables)
                [
                    'word' => 'PAGMAMAHAL',
                    'syllables' => ['PAG', 'MA', 'MA', 'HAL'],
                    'distractors' => ['BA', 'TO', 'KI', 'LA'],
                    'syllableCount' => 4,
                    'correctAnswer' => 4,
                    'voiceInstruction' => 'Piliin ang tamang pantig at ipatong ito sa tore upang mabuo ang salitang PAGMAMAHAL!',
                    'difficulty' => 'advanced',
                    'round' => 3,
                    'points' => 30,
                    'bonusPoints' => 20,
                    'meaningHint' => 'Wagas na pagkalinga ng isang ama at pamilya.',
                ],
                [
                    'word' => 'KAIBIGAN',
                    'syllables' => ['KA', 'I', 'BI', 'GAN'],
                    'distractors' => ['TO', 'MA', 'SU', 'LA'],
                    'syllableCount' => 4,
                    'correctAnswer' => 4,
                    'voiceInstruction' => 'Piliin ang tamang pantig at ipatong ito sa tore upang mabuo ang salitang KAIBIGAN!',
                    'difficulty' => 'advanced',
                    'round' => 3,
                    'points' => 30,
                    'bonusPoints' => 20,
                    'meaningHint' => 'Kasama sa paglalaro at pagtutulungan.',
                ],
                [
                    'word' => 'SARANGGOLA',
                    'syllables' => ['SA', 'RANG', 'GO', 'LA'],
                    'distractors' => ['TI', 'MU', 'NA', 'PO'],
                    'syllableCount' => 4,
                    'correctAnswer' => 4,
                    'voiceInstruction' => 'Piliin ang tamang pantig at ipatong ito sa tore upang mabuo ang salitang SARANGGOLA!',
                    'difficulty' => 'advanced',
                    'round' => 3,
                    'points' => 30,
                    'bonusPoints' => 20,
                    'meaningHint' => 'Laruan sa hangin na ginawa ni Itay.',
                ],
                [
                    'word' => 'PASASALAMAT',
                    'syllables' => ['PA', 'SA', 'SA', 'LA', 'MAT'],
                    'distractors' => ['KU', 'BI', 'TO', 'NA'],
                    'syllableCount' => 5,
                    'correctAnswer' => 5,
                    'voiceInstruction' => 'Piliin ang tamang pantig at ipatong ito sa tore upang mabuo ang salitang PASASALAMAT!',
                    'difficulty' => 'advanced',
                    'round' => 3,
                    'points' => 35,
                    'bonusPoints' => 25,
                    'meaningHint' => 'Pagpapakita ng pagpapahalaga sa mabuting ginawa ni Ama.',
                ],
            ],
        ];
    }
}

