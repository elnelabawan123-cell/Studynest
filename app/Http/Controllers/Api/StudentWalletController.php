<?php

namespace App\Http\Controllers\Api;

use App\Models\ShopItem;
use App\Models\StarTransaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StudentWalletController extends BaseApiController
{
    public function balance(Request $request): JsonResponse
    {
        $student = $request->user()->studentProfile;
        abort_unless($student, 403, 'No student profile found for this account.');

        // The Star Shop screen already hits this endpoint on load, so
        // piggybacking the equipped-avatar state here means it never needs
        // a second request just to know what's currently equipped.
        $avatarEmoji = $student->equipped_avatar_slug
            ? ShopItem::where('slug', $student->equipped_avatar_slug)->value('emoji')
            : null;

        return $this->success([
            'balance' => $student->stars_balance,
            'equipped_avatar_slug' => $student->equipped_avatar_slug,
            'equipped_avatar_emoji' => $avatarEmoji,
        ]);
    }

    public function transactions(Request $request): JsonResponse
    {
        $student = $request->user()->studentProfile;
        abort_unless($student, 403, 'No student profile found for this account.');

        return $this->success(
            StarTransaction::where('student_id', $student->id)
                ->latest()
                ->paginate(50)
        );
    }
}
