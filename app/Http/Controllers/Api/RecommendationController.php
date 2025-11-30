<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\RecommendationEngine;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class RecommendationController extends Controller
{
    public function index(): JsonResponse
    {
        try {
            if (!auth()->check()) {
                return response()->json([
                    'error' => 'Unauthenticated',
                    'message' => 'Please log in to view recommendations'
                ], 401);
            }

            $engine = new RecommendationEngine(auth()->user());
            $recommendations = $engine->getRecommendations();

            Log::info('Recommendations fetched', [
                'user_id' => auth()->id(),
                'counts' => [
                    'because_you_liked' => $recommendations['because_you_liked']['count'],
                    'jump_back_in' => $recommendations['jump_back_in']['count'],
                    'made_for_you' => $recommendations['made_for_you']['count'],
                ]
            ]);

            return response()->json([
                'data' => $recommendations,
                'meta' => [
                    'generated_at' => now()->toIso8601String(),
                    'cache_ttl' => '30 minutes'
                ]
            ], 200);

        } catch (\Exception $e) {
            Log::error('Recommendation engine failed', [
                'user_id' => auth()->id(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'error' => 'Unable to generate recommendations',
                'message' => 'Please try again later'
            ], 500);
        }
    }

    public function clearCache(): JsonResponse
    {
        try {
            if (!auth()->check()) {
                return response()->json(['error' => 'Unauthenticated'], 401);
            }

            $engine = new RecommendationEngine(auth()->user());
            $engine->clearCache();

            return response()->json([
                'message' => 'Recommendations cache cleared successfully'
            ], 200);

        } catch (\Exception $e) {
            Log::error('Failed to clear recommendations cache', [
                'user_id' => auth()->id(),
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'error' => 'Failed to clear cache'
            ], 500);
        }
    }
}
