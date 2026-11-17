<?php

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Models\Favorite;
use App\Models\ProblemList;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PreferenceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $favorites = Favorite::where('user_id', $user->id)
            ->with('problem')
            ->get();

        $customLists = ProblemList::where('user_id', $user->id)
            ->with('problems')
            ->get();

        return response()->json([
            'favorites'    => $favorites,
            'custom_lists' => $customLists,
        ]);
    }

    public function toggleFavorite(Request $request, string $id): JsonResponse
    {
        $userId = $request->user()->id;
        $favorite = Favorite::where('user_id', $userId)->where('problem_id', $id)->first();

        if ($favorite) {
            $favorite->delete();
            return response()->json(['favorited' => false]);
        }

        Favorite::create([
            'user_id'    => $userId,
            'problem_id' => $id,
        ]);

        return response()->json(['favorited' => true]);
    }
}
