<?php

declare(strict_types=1);

namespace App\Modules\Scheduling;

use App\Modules\Scheduling\Domain\Contracts\AppointmentRepositoryInterface;
use App\Modules\Scheduling\Domain\Contracts\AppointmentSequenceInterface;
use App\Modules\Scheduling\Domain\Contracts\BayRepositoryInterface;
use App\Modules\Scheduling\Domain\Contracts\ScheduleConfigRepositoryInterface;
use App\Modules\Scheduling\Infrastructure\Captcha\AlwaysPassCaptchaVerifier;
use App\Modules\Scheduling\Infrastructure\Captcha\CaptchaVerifierInterface;
use App\Modules\Scheduling\Infrastructure\Captcha\RecaptchaVerifier;
use App\Modules\Scheduling\Infrastructure\Commands\ScheduleAppointmentReminders;
use App\Modules\Scheduling\Infrastructure\Persistence\EloquentAppointmentRepository;
use App\Modules\Scheduling\Infrastructure\Persistence\EloquentAppointmentSequence;
use App\Modules\Scheduling\Infrastructure\Persistence\EloquentBayRepository;
use App\Modules\Scheduling\Infrastructure\Persistence\EloquentScheduleConfigRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

/**
 * Service provider for the top-level Scheduling module.
 *
 * Binds the CAPTCHA verifier contract to either the real google/recaptcha
 * implementation (production) or the always-pass fake (testing). Future tasks
 * add repository bindings (Bay, ScheduleConfig, Appointment) and adapter
 * bindings to Workshop/WorkOrder + Workshop/Technician.
 */
final class SchedulingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(AppointmentRepositoryInterface::class, EloquentAppointmentRepository::class);
        $this->app->bind(BayRepositoryInterface::class, EloquentBayRepository::class);
        $this->app->bind(ScheduleConfigRepositoryInterface::class, EloquentScheduleConfigRepository::class);
        $this->app->bind(AppointmentSequenceInterface::class, EloquentAppointmentSequence::class);

        $this->app->singleton(CaptchaVerifierInterface::class, static function (Application $app): CaptchaVerifierInterface {
            if ($app->environment('testing')) {
                return new AlwaysPassCaptchaVerifier;
            }

            /** @var ConfigRepository $configRepo */
            $configRepo = $app->make(ConfigRepository::class);
            /** @var array<string, mixed> $config */
            $config = (array) $configRepo->get('services.recaptcha', []);
            $secretKey = isset($config['secret_key']) && is_string($config['secret_key']) ? $config['secret_key'] : '';
            $minScore = isset($config['min_score']) && is_numeric($config['min_score']) ? (float) $config['min_score'] : 0.5;
            $hostname = isset($config['hostname']) && is_string($config['hostname']) && $config['hostname'] !== ''
                ? $config['hostname']
                : null;

            return new RecaptchaVerifier(
                secretKey: $secretKey,
                minScore: $minScore,
                expectedHostname: $hostname,
            );
        });
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/Presentation/routes.php');

        if ($this->app->runningInConsole()) {
            $this->commands([
                ScheduleAppointmentReminders::class,
            ]);
        }
    }
}
