<?php

declare(strict_types=1);

namespace Modules\Admin\app\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Admin\app\Services\AdminDashboardService;

class AdminDashboardController extends Controller
{
    public function __construct(
        protected AdminDashboardService $dashboardService
    ) {}

    /**
     * Retrieve global platform KPI statistics.
     */
    public function stats(Request $request): JsonResponse
    {
        $filters = $request->only(['period', 'start_date', 'end_date']);
        $stats = $this->dashboardService->getPlatformStats($filters);

        return ApiResponse::success(
            'Platform dashboard statistics retrieved successfully.',
            $stats
        );
    }

    /**
     * Retrieve time-series chart points for GMV and ticket sales velocity.
     */
    public function charts(Request $request): JsonResponse
    {
        $filters = $request->only(['days']);
        $charts = $this->dashboardService->getChartsData($filters);

        return ApiResponse::success(
            'Platform dashboard charts data retrieved successfully.',
            $charts
        );
    }
}
