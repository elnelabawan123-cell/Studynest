<?php

namespace App\Providers;

use App\Mail\Transports\BrevoTransport;
use App\Models\Activity;
use App\Models\Lesson;
use App\Observers\Activityobserver;
use App\Observers\Lessonobserver;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

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
        \PhpOffice\PhpWord\Settings::setOutputEscapingEnabled(true);
        if (! class_exists(\ZipArchive::class)) {
            \PhpOffice\PhpWord\Settings::setZipClass(\PhpOffice\PhpWord\Settings::PCLZIP);
        }

        Mail::extend('brevo', function (array $config = []): BrevoTransport {
            return new BrevoTransport(
                apiKey: (string) config('services.brevo.key', ''),
                endpoint: (string) config('services.brevo.endpoint', 'https://api.brevo.com/v3/smtp/email'),
                timeout: (int) ($config['timeout'] ?? 10),
            );
        });

        $this->configureRateLimiting();

        if (app()->environment('testing')) {
            return;
        }

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
