<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Integration;
use App\Models\MediaItem;
use App\Models\WishlistItem;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(): View
    {
        $mediaCounts = MediaItem::query()
            ->selectRaw('count(*) as total')
            ->selectRaw('sum(case when type = ? then 1 else 0 end) as movies', ['movie'])
            ->selectRaw('sum(case when type = ? then 1 else 0 end) as series', ['series'])
            ->selectRaw('sum(case when is_available then 1 else 0 end) as available')
            ->firstOrFail();

        $wishlistCounts = WishlistItem::query()
            ->selectRaw('count(*) as total')
            ->selectRaw('sum(case when status = ? then 1 else 0 end) as pending', [WishlistItem::PENDING])
            ->selectRaw('sum(case when status = ? then 1 else 0 end) as requested', [WishlistItem::REQUESTED])
            ->firstOrFail();

        return view('dashboard', [
            'libraryStats' => [
                'total' => (int) $mediaCounts->total,
                'movies' => (int) $mediaCounts->movies,
                'series' => (int) $mediaCounts->series,
                'available' => (int) $mediaCounts->available,
                'unavailable' => (int) $mediaCounts->total - (int) $mediaCounts->available,
            ],
            'wishlistStats' => [
                'total' => (int) $wishlistCounts->total,
                'pending' => (int) $wishlistCounts->pending,
                'requested' => (int) $wishlistCounts->requested,
            ],
            'integrationCount' => Integration::query()->count(),
            'recentLogs' => ActivityLog::query()
                ->select(['id', 'subject_type', 'subject_id', 'event', 'message', 'created_at'])
                ->with('subject')
                ->latest()
                ->limit(8)
                ->get(),
        ]);
    }
}
