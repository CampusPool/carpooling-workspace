<?php

require __DIR__.'/../Mobility/Recurrence/WeeklySchedulePreview.php';
use STS\Mobility\Recurrence\WeeklySchedulePreview;

$checks = 0;
function checkSchedule(bool $valid, string $reason): void
{
    global $checks;
    $checks++;
    if (!$valid) {
        throw new RuntimeException('FAIL: '.$reason);
    }
}
$days = WeeklySchedulePreview::dates(1 | 4 | 16, '08:30:00', '2026-10-19', 7);
checkSchedule(array_column($days, 'service_date') === ['2026-10-19','2026-10-21','2026-10-23'], 'weekday mask');
checkSchedule(array_column($days, 'weekday') === [1,3,5], 'weekday indices');
checkSchedule($days[0]['departure_local'] === '2026-10-19T08:30:00+05:30', 'Kolkata offset');
$sunday = WeeklySchedulePreview::dates(64, '12:00:00', '2026-10-18', 7);
checkSchedule(count($sunday) === 1 && $sunday[0]['service_date'] === '2026-10-18', 'Sunday mask');
$year = WeeklySchedulePreview::dates(127, '23:59:59', '2026-12-30', 4);
checkSchedule(count($year) === 4 && $year[3]['service_date'] === '2027-01-02', 'year rollover');
$leap = WeeklySchedulePreview::dates(127, '09:00:00', '2028-02-28', 3);
checkSchedule($leap[1]['service_date'] === '2028-02-29', 'leap day');
foreach ([[0,'08:00:00','2026-10-19',7], [128,'08:00:00','2026-10-19',7], [1,'25:00:00','2026-10-19',7], [1,'08:00:00','2026-02-30',7], [1,'08:00:00','2026-10-19',32], [1,'08:00:00','2026-10-19',0]] as $case) {
    $threw = false;
    try { WeeklySchedulePreview::dates(...$case); }
    catch (InvalidArgumentException $e) { $threw = true; }
    checkSchedule($threw, 'reject '.json_encode($case));
}
$dst = WeeklySchedulePreview::dates(64, '02:30:00', '2026-03-08', 1, 'America/New_York');
checkSchedule($dst === [], 'DST gap cannot shift silently');
echo "PASS: {$checks} recurrence preview checks\n";
