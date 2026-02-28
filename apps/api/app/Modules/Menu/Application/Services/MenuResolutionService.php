<?php

declare(strict_types=1);

namespace App\Modules\Menu\Application\Services;

use App\Modules\Menu\Domain\Entities\Menu;
use Carbon\Carbon;

class MenuResolutionService
{
    /**
     * Resolve the active menu for a company at the given datetime.
     *
     * Priority:
     * 1. Find active, non-default menus where ALL rules match (date range, time range, day-of-week)
     * 2. If multiple match, pick by display_order (lowest first)
     * 3. Fallback to the default menu
     */
    public function resolve(string $companyId, ?Carbon $at = null): ?Menu
    {
        $at = $at ?? Carbon::now();
        $currentTime = $at->format('H:i:s');
        $currentDate = $at->toDateString();
        $currentDayOfWeek = (int) $at->format('N'); // ISO: 1=Mon..7=Sun

        // Try non-default active menus first
        $candidates = Menu::query()
            ->forCompany($companyId)
            ->active()
            ->nonDefault()
            ->orderBy('display_order')
            ->get();

        foreach ($candidates as $menu) {
            if ($this->matchesRules($menu, $currentTime, $currentDate, $currentDayOfWeek)) {
                return $menu;
            }
        }

        // Fallback to default menu
        return Menu::query()
            ->forCompany($companyId)
            ->active()
            ->default()
            ->first();
    }

    private function matchesRules(Menu $menu, string $currentTime, string $currentDate, int $currentDayOfWeek): bool
    {
        // Check date range
        if ($menu->start_date !== null && $currentDate < $menu->start_date) {
            return false;
        }
        if ($menu->end_date !== null && $currentDate > $menu->end_date) {
            return false;
        }

        // Check time range (MVP: no overnight ranges)
        if ($menu->active_from !== null && $currentTime < $menu->active_from) {
            return false;
        }
        if ($menu->active_until !== null && $currentTime > $menu->active_until) {
            return false;
        }

        // Check day of week
        if ($menu->available_days !== null && count($menu->available_days) > 0) {
            if (! in_array($currentDayOfWeek, $menu->available_days, true)) {
                return false;
            }
        }

        return true;
    }
}
