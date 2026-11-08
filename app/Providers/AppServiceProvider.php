<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use App\Models\Lesson;
use App\Models\Activity;
use App\Observers\Lessonobserver;
use App\Observers\Activityobserver;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if (app()->environment('testing')) {
            return;
        }

        $this->configureRateLimiting();

        Lesson::observe(Lessonobserver::class);
        Activity::observe(Activityobserver::class);
    }

    /**
     * Configure the rate limiters for the application.
     */
    protected function configureRateLimiting(): void
    {
        // General authenticated API — 60 requests per minute per user (or IP if guest)
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)
                ->by($request->user()?->id ?: $request->ip())
                ->response(fn() => response()->json([
                    'message' => 'Too many requests. Please slow down.',
                ], 429));
        });

        // Student routes — 30 requests per minute (lighter usage expected)
        RateLimiter::for('student-api', function (Request $request) {
            return Limit::perMinute(30)
                ->by('student|' . ($request->user()?->id ?: $request->ip()))
                ->response(fn() => response()->json([
                    'message' => 'Too many requests. Please slow down.',
                ], 429));
        });

        // File/media uploads — max 10 per minute
        RateLimiter::for('uploads', function (Request $request) {
            return Limit::perMinute(10)
                ->by('upload|' . ($request->user()?->id ?: $request->ip()))
                ->response(fn() => response()->json([
                    'message' => 'Upload limit reached. Please wait before uploading again.',
                ], 429));
        });
    }

   

 
 

}
