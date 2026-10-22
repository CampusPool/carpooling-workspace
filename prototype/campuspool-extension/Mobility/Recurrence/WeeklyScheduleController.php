<?php

namespace STS\Mobility\Recurrence;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use STS\Models\Trip;

final class WeeklyScheduleController
{
    public function preview(Request $request, Trip $trip): JsonResponse
    {
        $user = $request->user();
        if (!$user || (int) $user->id !== (int) $trip->user_id) {
            return response()->json(['error' => ['code' => 'TRIP_OWNER_REQUIRED']], 403);
        }
        $mask = (int) ($trip->weekly_schedule ?? 0);
        if ($mask < 1 || $mask > 127) {
            return response()->json(['error' => ['code' => 'NOT_A_WEEKLY_PLAN']], 422);
        }
        $zone = 'Asia/Kolkata';
        $today = now($zone)->format('Y-m-d');
        $from = $request->query('from', $today);
        $days = $request->query('days', '14');
        if (!is_string($from) || !is_scalar($days)
            || !ctype_digit((string) $days) || (int) $days < 1 || (int) $days > 31) {
            return response()->json(['error' => ['code' => 'INVALID_PREVIEW_RANGE']], 422);
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/D', $from) || $from < $today) {
            return response()->json(['error' => ['code' => 'INVALID_START_DATE']], 422);
        }
        $value = $trip->weekly_schedule_time;
        $time = is_object($value) && method_exists($value, 'format')
            ? $value->format('H:i:s') : (is_string($value) ? $value : '');
        try {
            $dates = WeeklySchedulePreview::dates($mask, $time, $from, (int) $days, $zone);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => ['code' => 'INVALID_SCHEDULE']], 422);
        }
        return response()->json([
            'trip_id' => (int) $trip->id,
            'weekly_schedule' => $mask,
            'timezone' => $zone,
            'from' => $from,
            'days' => (int) $days,
            'is_preview_only' => true,
            'occurrences_materialized' => false,
            'dates' => $dates,
        ]);
    }
}
