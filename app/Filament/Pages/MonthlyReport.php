<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Enums\NavigationGroup;
use App\Services\Attendance\AttendanceCalendar;
use App\Services\Attendance\MonthlyAttendanceSummary;
use App\Support\Attendance\SessionDuration;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * One month, one row per employee: the attendance list's facts folded
 * into the figures somebody doing payroll elsewhere copies out, with a
 * CSV that is those same figures and nothing else.
 *
 * All the arithmetic is MonthlyAttendanceSummary's; this page holds only
 * the chosen month, and the chosen month is a string of the browser's
 * own month input - the one control that gives a phone and a desktop the
 * same picker without a component in between. An unparseable value falls
 * back to the current month rather than erroring: a hand-edited query
 * cannot make this page claim anything, only choose what it reads.
 */
final class MonthlyReport extends Page
{
    protected static ?string $slug = 'monthly-report';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentChartBar;

    // The same glyph filled in, so the entry the reader is standing on is
    // legible as the current one from the shape alone.
    protected static string|BackedEnum|null $activeNavigationIcon = Heroicon::DocumentChartBar;

    protected static ?int $navigationSort = 4;

    protected string $view = 'filament.pages.monthly-report';

    /**
     * The chosen month as the browser's month input writes it: 'YYYY-MM'.
     */
    public string $month = '';

    public static function getNavigationGroup(): NavigationGroup
    {
        return NavigationGroup::Attendance;
    }

    public static function getNavigationLabel(): string
    {
        return __('reports.navigation.label');
    }

    public function getTitle(): string
    {
        return __('reports.title');
    }

    public function getSubheading(): string
    {
        return __('reports.subheading');
    }

    public function mount(): void
    {
        $this->month = app(AttendanceCalendar::class)->today()->format('Y-m');
    }

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('export')
                ->label(__('reports.actions.export'))
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->color('gray')
                ->action(fn (): StreamedResponse => $this->exportCsv()),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $service = app(MonthlyAttendanceSummary::class);
        $monthStart = $this->monthStart();
        $rows = $service->rows($monthStart);

        return [
            'monthLabel' => $monthStart->locale(app()->getLocale())->isoFormat('MMMM YYYY'),
            'workingDaysElapsed' => count($service->elapsedWorkingDays($monthStart)),
            'rows' => $rows,
            'formatDuration' => static fn (int $seconds): string => SessionDuration::format($seconds),
        ];
    }

    /**
     * The table as a file: the same service, the same month, one line per
     * employee, headers in the reader's language. The BOM is for Excel,
     * which otherwise reads Arabic CSV as mojibake.
     */
    private function exportCsv(): StreamedResponse
    {
        $monthStart = $this->monthStart();
        $rows = app(MonthlyAttendanceSummary::class)->rows($monthStart);

        $filename = 'attendance-'.$monthStart->format('Y-m').'.csv';

        return response()->streamDownload(function () use ($rows): void {
            $handle = fopen('php://output', 'w');

            if ($handle === false) {
                return;
            }

            fwrite($handle, "\u{FEFF}");

            fputcsv($handle, [
                __('reports.fields.employee'),
                __('reports.fields.job_title'),
                __('reports.fields.days_attended'),
                __('reports.fields.time_inside'),
                __('reports.fields.late_days'),
                __('reports.fields.total_lateness'),
                __('reports.fields.early_check_outs'),
                __('reports.fields.leave_days'),
                __('reports.fields.days_unrecorded'),
            ]);

            foreach ($rows as $row) {
                fputcsv($handle, [
                    $row->employeeName,
                    $row->jobTitle ?? '',
                    (string) $row->daysAttended,
                    SessionDuration::format($row->secondsInside),
                    (string) $row->lateDays,
                    SessionDuration::format($row->latenessSeconds),
                    (string) $row->earlyCheckOuts,
                    (string) $row->leaveDays,
                    (string) $row->daysUnrecorded,
                ]);
            }

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * The chosen month's first day, in the attendance timezone. Anything
     * that is not a month chooses the current one.
     */
    private function monthStart(): CarbonImmutable
    {
        $today = app(AttendanceCalendar::class)->today();

        if (preg_match('/^\d{4}-\d{2}$/', $this->month) !== 1) {
            return $today->startOfMonth();
        }

        return CarbonImmutable::createFromFormat('!Y-m', $this->month, (string) config('app.timezone'))->startOfMonth();
    }
}
