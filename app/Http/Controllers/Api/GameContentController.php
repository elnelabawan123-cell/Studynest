<?php

namespace App\Http\Controllers\Api;

use App\Models\GameContent;
use App\Support\GameCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GameContentController extends BaseApiController
{
    public function index(Request $request): JsonResponse
    {
        $gradeFilter = $request->query('grade');
        $modeFilter = $request->query('mode');
        $searchFilter = strtolower(trim((string) $request->query('search', '')));

        $records = GameContent::all()->keyBy('game_key');
        $catalog = GameCatalog::all();
        $seenKeys = [];
        $items = [];

        foreach ($catalog as $entry) {
            $key = (string) ($entry['gameKey'] ?? '');
            if ($key === '') {
                continue;
            }
            $seenKeys[$key] = true;

            $record = $records->get($key);
            $merged = $record && is_array($record->content)
                ? self::mergeWithDefault($key, $record->content)
                : $entry;

            $item = [
                'gameKey' => $key,
                'grade' => (int) ($entry['grade'] ?? 1),
                'yunit' => (int) ($entry['yunit'] ?? 0),
                'aralin' => (int) ($entry['aralin'] ?? 0),
                'aralinNumber' => (int) ($entry['aralinNumber'] ?? (($entry['aralin'] ?? 0) + 1)),
                'mode' => (string) ($entry['mode'] ?? '2d'),
                'subject' => (string) ($entry['subject'] ?? ''),
                'emoji' => (string) ($merged['emoji'] ?? $entry['emoji'] ?? '🎮'),
                'yunitTitle' => (string) ($merged['yunitTitle'] ?? $entry['yunitTitle'] ?? ''),
                'title' => (string) ($merged['title'] ?? $entry['title'] ?? ''),
                'topic' => (string) ($merged['topic'] ?? $merged['subtitle'] ?? $entry['topic'] ?? ''),
                'gameTitle' => (string) ($merged['gameTitle'] ?? $entry['gameTitle'] ?? ''),
                'questionCount' => count($merged['words'] ?? $merged['questions'] ?? []),
                'source' => $record ? 'database' : 'default',
                'hasOverride' => (bool) $record,
                'updatedAt' => $record?->updated_at?->toIso8601String(),
            ];

            if ($gradeFilter !== null && $gradeFilter !== '' && (int) $gradeFilter !== $item['grade']) {
                continue;
            }
            if ($modeFilter !== null && $modeFilter !== '' && strtolower((string) $modeFilter) !== $item['mode']) {
                continue;
            }
            if ($searchFilter !== '') {
                $haystack = strtolower(
                    $item['gameKey'] . ' ' .
                    $item['yunitTitle'] . ' ' .
                    $item['title'] . ' ' .
                    $item['topic'] . ' ' .
                    $item['gameTitle'] . ' ' .
                    $item['subject']
                );
                if (!str_contains($haystack, $searchFilter)) {
                    continue;
                }
            }

            $items[] = $item;
        }

        // Include any extra custom DB-only game keys not present in the built-in catalog
        foreach ($records as $key => $record) {
            if (isset($seenKeys[$key]) || !is_array($record->content)) {
                continue;
            }
            $c = $record->content;
            if ($gradeFilter !== null && $gradeFilter !== '' && (int) $gradeFilter !== (int) $record->grade) {
                continue;
            }
            $items[] = [
                'gameKey' => $key,
                'grade' => (int) $record->grade,
                'yunit' => (int) $record->yunit,
                'aralin' => (int) $record->aralin,
                'aralinNumber' => (int) $record->aralin + 1,
                'mode' => (string) ($c['mode'] ?? '2d'),
                'subject' => (string) ($c['subject'] ?? ''),
                'emoji' => (string) ($c['emoji'] ?? '🎮'),
                'yunitTitle' => (string) ($c['yunitTitle'] ?? ''),
                'title' => (string) ($c['title'] ?? $record->title),
                'topic' => (string) ($c['topic'] ?? $c['subtitle'] ?? ''),
                'gameTitle' => (string) ($c['gameTitle'] ?? $record->title),
                'questionCount' => count($c['words'] ?? $c['questions'] ?? []),
                'source' => 'database',
                'hasOverride' => true,
                'updatedAt' => $record->updated_at?->toIso8601String(),
            ];
        }

        return $this->success([
            'catalog' => $items,
            'total' => count($items),
            'customizedCount' => $records->count(),
        ]);
    }

    public function indexForStudent(Request $request): JsonResponse
    {
        $student = $request->user()->studentProfile;
        abort_unless($student, 403, 'No student profile found for this account.');

        $gradeFilter = $request->query('grade');
        $query = GameContent::query();
        if ($gradeFilter !== null && $gradeFilter !== '') {
            $query->where('grade', (int) $gradeFilter);
        }

        $overrides = [];
        foreach ($query->get() as $record) {
            if (!is_array($record->content)) {
                continue;
            }
            $overrides[$record->game_key] = self::mergeWithDefault($record->game_key, $record->content);
        }

        return $this->success([
            'overrides' => $overrides,
            'count' => count($overrides),
        ]);
    }

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

    public function destroy(string $gameKey): JsonResponse
    {
        GameContent::where('game_key', $gameKey)->delete();

        $default = self::defaultContent($gameKey);
        return $this->success([
            'game_key' => $gameKey,
            'title' => $default['gameTitle'] ?? $default['title'] ?? $gameKey,
            'source' => 'default',
            'content' => $default,
        ], 'Game content reset to built-in default.');
    }

    public function update(Request $request, string $gameKey): JsonResponse
    {
        $isPantigTower = $gameKey === 'g3m:0:6' || $request->has('words');

        $rules = [
            'grade' => 'sometimes|integer|min:1|max:6',
            'yunit' => 'sometimes|integer|min:0|max:15',
            'aralin' => 'sometimes|integer|min:0|max:40',
            'mode' => 'nullable|string|in:2d,3d',
            'yunitTitle' => 'nullable|string|max:255',
            'title' => 'nullable|string|max:255',
            'topic' => 'nullable|string|max:255',
            'subtitle' => 'nullable|string|max:255',
            'explanation' => 'nullable|string|max:5000',
            'tipBox' => 'nullable|string|max:1000',
            'gameTitle' => 'nullable|string|max:255',
            'welcome' => 'nullable|string|max:1000',
            'instructions' => 'nullable|string|max:2000',
            'completion' => 'nullable|string|max:1000',
            'steps' => 'nullable|array|max:10',
            'steps.*.text' => 'required|string|max:300',
            'steps.*.emoji' => 'nullable|string|max:16',
            'questions' => 'nullable|array|min:1|max:60',
            'questions.*.question' => 'required|string|max:1000',
            'questions.*.emoji' => 'nullable|string|max:16',
            'questions.*.choices' => 'required|array|min:2|max:6',
            'questions.*.choices.*' => 'required|string|max:400',
            'questions.*.choiceEmojis' => 'nullable|array',
            'questions.*.choiceEmojis.*' => 'nullable|string|max:16',
            'questions.*.correctIndex' => 'required|integer|min:0',
            'questions.*.explanation' => 'nullable|string|max:1000',
            'questions.*.category' => 'nullable|string|max:100',
            'questions.*.round' => 'nullable|integer|min:1|max:10',
            'questions.*.points' => 'nullable|integer|min:1|max:500',
        ];

        if ($isPantigTower) {
            $rules = array_merge($rules, [
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
        }

        $validated = $request->validate($rules);

        [$parsedGrade, $parsedYunit, $parsedAralin] = $this->parseGameKey($gameKey);
        $defaults = self::defaultContent($gameKey);
        $grade = (int) ($validated['grade'] ?? $defaults['grade'] ?? $parsedGrade);
        $yunit = (int) ($validated['yunit'] ?? $defaults['yunit'] ?? $parsedYunit);
        $aralin = (int) ($validated['aralin'] ?? $defaults['aralin'] ?? $parsedAralin);

        $normalizedWords = null;
        if ($isPantigTower && !empty($validated['words'])) {
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
        }

        $normalizedQuestions = null;
        if (array_key_exists('questions', $validated)) {
            $normalizedQuestions = [];
            foreach ($validated['questions'] as $qIdx => $rawQ) {
                $choices = array_values(array_map(fn($c) => trim((string) $c), $rawQ['choices']));
                $correctIdx = (int) $rawQ['correctIndex'];
                if ($correctIdx < 0 || $correctIdx >= count($choices)) {
                    return $this->error(
                        "Invalid correctIndex {$correctIdx} at question #" . ($qIdx + 1) . ": must point to one of the " . count($choices) . " choices.",
                        422
                    );
                }
                $rawChoiceEmojis = $rawQ['choiceEmojis'] ?? [];
                $choiceEmojis = [];
                foreach ($choices as $cIdx => $_) {
                    $choiceEmojis[] = trim((string) ($rawChoiceEmojis[$cIdx] ?? ''));
                }
                $normalizedQuestions[] = [
                    'question' => trim((string) $rawQ['question']),
                    'emoji' => trim((string) ($rawQ['emoji'] ?? '❓')) ?: '❓',
                    'choices' => $choices,
                    'choiceEmojis' => $choiceEmojis,
                    'correctIndex' => $correctIdx,
                    'explanation' => trim((string) ($rawQ['explanation'] ?? '')),
                    'category' => trim((string) ($rawQ['category'] ?? '')),
                    'round' => isset($rawQ['round']) ? (int) $rawQ['round'] : 1,
                    'points' => isset($rawQ['points']) ? (int) $rawQ['points'] : 10,
                ];
            }
        }

        $normalizedSteps = null;
        if (array_key_exists('steps', $validated)) {
            $normalizedSteps = [];
            foreach ($validated['steps'] as $s) {
                $normalizedSteps[] = [
                    'text' => trim((string) $s['text']),
                    'emoji' => trim((string) ($s['emoji'] ?? '⭐')) ?: '⭐',
                ];
            }
        }

        $existing = GameContent::where('game_key', $gameKey)->first()?->content;
        $base = is_array($existing) ? self::mergeWithDefault($gameKey, $existing) : $defaults;

        $gameTitle = trim((string) ($validated['gameTitle'] ?? ($base['gameTitle'] ?? $base['title'] ?? $gameKey)));
        $lessonTitle = trim((string) ($validated['title'] ?? ($base['title'] ?? $gameTitle)));
        $topic = trim((string) ($validated['topic'] ?? $validated['subtitle'] ?? ($base['topic'] ?? $base['subtitle'] ?? '')));
        $subtitle = trim((string) ($validated['subtitle'] ?? $validated['topic'] ?? ($base['subtitle'] ?? $topic)));

        $payload = [
            'gameKey' => $gameKey,
            'grade' => $grade,
            'yunit' => $yunit,
            'aralin' => $aralin,
            'aralinNumber' => (int) ($base['aralinNumber'] ?? ($aralin + 1)),
            'mode' => (string) ($validated['mode'] ?? $base['mode'] ?? '2d'),
            'subject' => (string) ($base['subject'] ?? ''),
            'yunitTitle' => trim((string) ($validated['yunitTitle'] ?? ($base['yunitTitle'] ?? ''))),
            'title' => $lessonTitle,
            'emoji' => (string) ($base['emoji'] ?? '🎮'),
            'topic' => $topic,
            'subtitle' => $subtitle,
            'explanation' => (string) ($validated['explanation'] ?? ($base['explanation'] ?? '')),
            'tipBox' => (string) ($validated['tipBox'] ?? ($base['tipBox'] ?? '')),
            'gameTitle' => $gameTitle,
            'welcome' => (string) ($validated['welcome'] ?? ($base['welcome'] ?? '')),
            'instructions' => (string) ($validated['instructions'] ?? ($base['instructions'] ?? '')),
            'completion' => (string) ($validated['completion'] ?? ($base['completion'] ?? '')),
            'steps' => $normalizedSteps ?? ($base['steps'] ?? []),
        ];

        if ($normalizedWords !== null) {
            $payload['words'] = $normalizedWords;
        } elseif (!empty($base['words'])) {
            $payload['words'] = $base['words'];
        }

        if ($normalizedQuestions !== null) {
            $payload['questions'] = $normalizedQuestions;
        } elseif (isset($base['questions'])) {
            $payload['questions'] = $base['questions'];
        }

        $recordTitle = $gameTitle !== '' ? $gameTitle : $lessonTitle;

        $record = GameContent::updateOrCreate(
            ['game_key' => $gameKey],
            [
                'grade' => $grade,
                'yunit' => $yunit,
                'aralin' => $aralin,
                'title' => $recordTitle,
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
            $merged = self::mergeWithDefault($gameKey, $record->content);
            return $this->success([
                'game_key' => $record->game_key,
                'title' => $merged['gameTitle'] ?? $record->title,
                'source' => 'database',
                'content' => $merged,
            ]);
        }

        $default = self::defaultContent($gameKey);
        return $this->success([
            'game_key' => $gameKey,
            'title' => $default['gameTitle'] ?? $default['title'] ?? $gameKey,
            'source' => 'default',
            'content' => $default,
        ]);
    }

    /**
     * @param  array<string, mixed>  $stored
     * @return array<string, mixed>
     */
    private static function mergeWithDefault(string $gameKey, array $stored): array
    {
        $default = self::defaultContent($gameKey);
        return array_merge($default, $stored);
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
        if ($gameKey !== 'g3m:0:6') {
            $catalogEntry = GameCatalog::find($gameKey);
            if ($catalogEntry !== null) {
                return $catalogEntry;
            }
        }

        $pantigCatalog = GameCatalog::find($gameKey);
        return array_merge($pantigCatalog ?? [], [
            'gameKey' => $gameKey,
            'grade' => 3,
            'yunit' => 0,
            'aralin' => 6,
            'aralinNumber' => 7,
            'mode' => '3d',
            'subject' => 'Filipino (Angkla 3)',
            'yunitTitle' => 'Ako sa Aking Sambahayan',
            'title' => 'Pagmamahal ng Isang Ama',
            'topic' => 'Pagpapantig',
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
        ]);
    }
}

