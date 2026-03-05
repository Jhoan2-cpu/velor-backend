<?php

namespace App\Services\Focus;

use App\Http\Resources\FocusTimeEntryResource;
use App\Http\Resources\IdleTimeEntryResource;
use App\Models\FocusTimeEntry;
use App\Models\IdleTimeEntry;
use Carbon\CarbonImmutable;

class FocusDailyLogService
{
    /**
     * @return array{
     *   date: string,
     *   date_local: string,
     *   time_zone_name: string,
     *   tracked_seconds: int,
     *   untracked_seconds: int,
     *   focus_time_entries: array<int, array<string, mixed>>,
     *   idle_time_entries: array<int, array<string, mixed>>,
     *   entries: array<int, array<string, mixed>>
     * }
     */
    public function buildForUser(int $userId, string $date, string $timeZoneName): array
    {
        [$rangeStartUtc, $rangeEndUtc] = $this->utcRangeForLocalDate($date, $timeZoneName);

        $focusEntries = FocusTimeEntry::query()
            ->where('user_id', $userId)
            ->where('started_at_utc', '<', $rangeEndUtc)
            ->where(function ($query) use ($rangeStartUtc): void {
                $query->whereNull('ended_at_utc')
                    ->orWhere('ended_at_utc', '>=', $rangeStartUtc);
            })
            ->orderBy('started_at_utc')
            ->get();

        $idleEntries = IdleTimeEntry::query()
            ->where('user_id', $userId)
            ->where('started_at_utc', '<', $rangeEndUtc)
            ->where(function ($query) use ($rangeStartUtc): void {
                $query->whereNull('ended_at_utc')
                    ->orWhere('ended_at_utc', '>=', $rangeStartUtc);
            })
            ->orderBy('started_at_utc')
            ->get();

        $focusPayload = FocusTimeEntryResource::collection($focusEntries)->resolve();
        $idlePayload = IdleTimeEntryResource::collection($idleEntries)->resolve();
        $mergedEntries = $this->mergeEntries($focusPayload, $idlePayload);

        return [
            'date' => $date,
            'date_local' => $date,
            'time_zone_name' => $timeZoneName,
            'tracked_seconds' => $this->sumElapsed($focusPayload),
            'untracked_seconds' => $this->sumElapsed($idlePayload),
            'focus_time_entries' => $focusPayload,
            'idle_time_entries' => $idlePayload,
            'entries' => $mergedEntries,
        ];
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function utcRangeForLocalDate(string $date, string $timeZoneName): array
    {
        $localStart = CarbonImmutable::createFromFormat('Y-m-d H:i:s', "{$date} 00:00:00", $timeZoneName);
        $localEnd = $localStart->addDay();

        return [
            $localStart->setTimezone('UTC'),
            $localEnd->setTimezone('UTC'),
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $entries
     */
    private function sumElapsed(array $entries): int
    {
        return array_reduce(
            $entries,
            static fn (int $carry, array $entry): int => $carry + (int) ($entry['elapsed_seconds'] ?? 0),
            0,
        );
    }

    /**
     * @param array<int, array<string, mixed>> $focusEntries
     * @param array<int, array<string, mixed>> $idleEntries
     * @return array<int, array<string, mixed>>
     */
    private function mergeEntries(array $focusEntries, array $idleEntries): array
    {
        $mappedFocus = array_map(static function (array $entry): array {
            return [
                'id' => 'focus:' . (string) $entry['id'],
                'source_type' => 'focus',
                'source_id' => (string) $entry['id'],
                'task_id' => $entry['focus_task_id_nullable'],
                'mode' => $entry['mode_snapshot'],
                'started_at_utc' => $entry['started_at_utc'],
                'ended_at_utc' => $entry['ended_at_utc'],
                'elapsed_seconds' => $entry['elapsed_seconds'],
                'reason' => $entry['stop_reason'],
            ];
        }, $focusEntries);

        $mappedIdle = array_map(static function (array $entry): array {
            return [
                'id' => 'idle:' . (string) $entry['id'],
                'source_type' => 'idle',
                'source_id' => (string) $entry['id'],
                'task_id' => null,
                'mode' => null,
                'started_at_utc' => $entry['started_at_utc'],
                'ended_at_utc' => $entry['ended_at_utc'],
                'elapsed_seconds' => $entry['elapsed_seconds'],
                'reason' => $entry['reason'],
            ];
        }, $idleEntries);

        $combined = [...$mappedFocus, ...$mappedIdle];

        usort($combined, static fn (array $a, array $b): int => strcmp(
            (string) ($a['started_at_utc'] ?? ''),
            (string) ($b['started_at_utc'] ?? ''),
        ));

        return $combined;
    }
}
