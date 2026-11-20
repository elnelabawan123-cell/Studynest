<?php

namespace App\Http\Controllers\Api;

use App\Models\ShopItem;
use App\Models\StarTransaction;
use App\Models\Student;
use App\Models\StudentInventory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StudentShopController extends BaseApiController
{
    public function items(Request $request): JsonResponse
    {
        return $this->success(
            ShopItem::where('is_active', true)->orderBy('price')->get()
        );
    }

    public function purchase(Request $request): JsonResponse
    {
        $student = $request->user()->studentProfile;
        abort_unless($student, 403, 'No student profile found for this account.');

        $data = $request->validate([
            'item_slug' => 'required|string|exists:shop_items,slug',
        ]);

        $item = ShopItem::where('slug', $data['item_slug'])->where('is_active', true)->first();
        if (!$item) {
            return $this->error('This item is not available.', 404);
        }

        try {
            $result = DB::transaction(function () use ($student, $item) {
                // Lock the student row so two rapid/duplicate purchase
                // clicks can't both pass the balance check before either
                // deduction is committed.
                $lockedStudent = Student::where('id', $student->id)->lockForUpdate()->first();

                if ($lockedStudent->stars_balance < $item->price) {
                    throw new \RuntimeException('INSUFFICIENT_BALANCE');
                }

                $lockedStudent->stars_balance -= $item->price;
                $lockedStudent->save();

                StarTransaction::create([
                    'student_id' => $lockedStudent->id,
                    'amount' => -$item->price,
                    'balance_after' => $lockedStudent->stars_balance,
                    'type' => 'purchase',
                    'reference' => $item->slug,
                    'description' => 'Purchased ' . $item->name,
                ]);

                $inventory = StudentInventory::firstOrNew([
                    'student_id' => $lockedStudent->id,
                    'shop_item_id' => $item->id,
                ]);
                $inventory->quantity = ($inventory->quantity ?? 0) + 1;
                $inventory->save();

                return [
                    'balance' => $lockedStudent->stars_balance,
                    'inventory' => $inventory->fresh('shopItem'),
                ];
            });
        } catch (\RuntimeException $e) {
            if ($e->getMessage() === 'INSUFFICIENT_BALANCE') {
                return $this->error('Not enough stars!', 422);
            }
            throw $e;
        }

        return $this->success($result, 'Purchase successful.');
    }

    // ── POST /api/student/shop/equip-avatar ───────────────────────────────
    // Sets which owned "character" item is used as the student's profile
    // picture. Deliberately separate from StudentInventoryController::
    // consume() — equipping a cosmetic never touches quantity or stars,
    // unlike spending a booster.
    public function equipAvatar(Request $request): JsonResponse
    {
        return $this->equipCosmetic(
            $request,
            category: 'character',
            column: 'equipped_avatar_slug',
            wrongCategoryMessage: 'This item cannot be used as a profile picture.',
            unownedMessage: "You don't own this avatar yet.",
            successMessage: 'Avatar equipped.',
        );
    }

    // ── POST /api/student/shop/equip-border ───────────────────────────────
    // Equips an owned "border" item around the student's profile avatar, or
    // clears it when item_slug is null/empty.
    public function equipBorder(Request $request): JsonResponse
    {
        return $this->equipCosmetic(
            $request,
            category: 'border',
            column: 'equipped_border_slug',
            wrongCategoryMessage: 'This item cannot be used as an avatar border.',
            unownedMessage: "You don't own this avatar border yet.",
            successMessage: 'Avatar border updated.',
            allowUnequip: true,
        );
    }

    private function equipCosmetic(
        Request $request,
        string $category,
        string $column,
        string $wrongCategoryMessage,
        string $unownedMessage,
        string $successMessage,
        bool $allowUnequip = false,
    ): JsonResponse {
        $student = $request->user()->studentProfile;
        abort_unless($student, 403, 'No student profile found for this account.');

        $data = $request->validate([
            'item_slug' => ($allowUnequip ? 'nullable' : 'required') . '|string|exists:shop_items,slug',
        ]);

        $slug = $data['item_slug'] ?? null;
        if ($allowUnequip && ($slug === null || trim($slug) === '')) {
            $student->update([$column => null]);

            return $this->success($student->fresh()->equippedCosmeticsPayload(), $successMessage);
        }

        $item = ShopItem::where('slug', $slug)->where('is_active', true)->first();
        if (!$item || $item->category !== $category) {
            return $this->error($wrongCategoryMessage, 422);
        }

        $owned = StudentInventory::where('student_id', $student->id)
            ->where('shop_item_id', $item->id)
            ->where('quantity', '>', 0)
            ->exists();
        if (!$owned) {
            return $this->error($unownedMessage, 403);
        }

        $student->update([$column => $item->slug]);

        return $this->success($student->fresh()->equippedCosmeticsPayload(), $successMessage);
    }
}
