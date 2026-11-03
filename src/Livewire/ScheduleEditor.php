<?php

namespace ME\Abs\Livewire;

use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Locked;
use Livewire\Component;
use ME\Abs\Livewire\Concerns\InteractsWithBusiness;
use ME\Abs\Models\Staff;
use ME\Abs\Support\BusinessAccess;

/**
 * Weekly working hours and breaks of one staff member. Each weekday holds any number of intervals
 * ("HH:MM" local time; end may be "24:00"). Overlapping intervals on the same day are rejected.
 */
class ScheduleEditor extends Component
{
    use InteractsWithBusiness;

    public const DAYS = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 0 => 'Sunday'];

    #[Locked]
    public int $staffId;

    /** @var array<int, array<int, array{start: string, end: string}>> */
    public array $work = [];

    /** @var array<int, array<int, array{start: string, end: string}>> */
    public array $breaks = [];

    public function mount(Staff $staff): void
    {
        $this->authorizeBusiness('abs_schedule.view');
        abort_unless($staff->business_id === $this->business()->getKey(), 404);
        $this->staffId = $staff->getKey();

        foreach (array_keys(self::DAYS) as $day) {
            $this->work[$day] = [];
            $this->breaks[$day] = [];
        }
        foreach ($staff->schedules()->orderBy('start_time')->get() as $row) {
            $this->work[$row->weekday][] = ['start' => substr($row->start_time, 0, 5), 'end' => substr($row->end_time, 0, 5)];
        }
        foreach ($staff->breaks()->orderBy('start_time')->get() as $row) {
            $this->breaks[$row->weekday][] = ['start' => substr($row->start_time, 0, 5), 'end' => substr($row->end_time, 0, 5)];
        }
    }

    public function addRow(string $type, int $day): void
    {
        if (! array_key_exists($day, self::DAYS) || ! in_array($type, ['work', 'breaks'], true)) {
            return;
        }

        $this->{$type}[$day][] = $type === 'work' ? ['start' => '09:00', 'end' => '17:00'] : ['start' => '13:00', 'end' => '14:00'];
    }

    public function removeRow(string $type, int $day, int $index): void
    {
        if (in_array($type, ['work', 'breaks'], true) && isset($this->{$type}[$day][$index])) {
            unset($this->{$type}[$day][$index]);
            $this->{$type}[$day] = array_values($this->{$type}[$day]);
        }
    }

    public function copyMondayToWeekdays(): void
    {
        foreach ([2, 3, 4, 5] as $day) {
            $this->work[$day] = $this->work[1] ?? [];
            $this->breaks[$day] = $this->breaks[1] ?? [];
        }
    }

    public function save(): void
    {
        $this->authorizeBusiness('abs_schedule.edit');
        $staff = $this->staff();
        $time = 'regex:/^([01]\d|2[0-3]):[0-5]\d$/';
        $end = 'regex:/^(([01]\d|2[0-3]):[0-5]\d|24:00)$/';

        $this->validate([
            'work' => ['array'], 'breaks' => ['array'],
            'work.*' => ['array', 'max:12'], 'breaks.*' => ['array', 'max:12'],
            'work.*.*.start' => ['required', $time], 'work.*.*.end' => ['required', $end],
            'breaks.*.*.start' => ['required', $time], 'breaks.*.*.end' => ['required', $end],
        ], [], ['work.*.*.start' => 'start', 'work.*.*.end' => 'end', 'breaks.*.*.start' => 'break start', 'breaks.*.*.end' => 'break end']);

        foreach (['work', 'breaks'] as $type) {
            foreach ($this->{$type} as $day => $rows) {
                if (! array_key_exists((int) $day, self::DAYS)) {
                    $this->addError($type, 'Unknown weekday.');

                    return;
                }
                if ($message = $this->overlapError($rows)) {
                    $this->addError("{$type}.{$day}", self::DAYS[(int) $day].': '.$message);

                    return;
                }
            }
        }

        DB::transaction(function () use ($staff) {
            $staff->schedules()->delete();
            $staff->breaks()->delete();
            foreach ($this->work as $day => $rows) {
                foreach ($rows as $row) {
                    $staff->schedules()->create(['weekday' => (int) $day, 'start_time' => $row['start'], 'end_time' => $row['end']]);
                }
            }
            foreach ($this->breaks as $day => $rows) {
                foreach ($rows as $row) {
                    $staff->breaks()->create(['weekday' => (int) $day, 'start_time' => $row['start'], 'end_time' => $row['end']]);
                }
            }
        });

        me_change_log('Schedule updated for '.$staff->name, 'abs.schedule.update')->record([], ['work' => $this->work, 'breaks' => $this->breaks]);
        session()->flash('success', __('abs::abs.saved'));
        $this->redirect(route('abs.staff.schedule', $staff->getKey()));
    }

    /**
     * @param  array<int, array{start: string, end: string}>  $rows
     */
    public function overlapError(array $rows): ?string
    {
        usort($rows, fn ($a, $b) => strcmp($a['start'], $b['start']));
        $previousEnd = null;

        foreach ($rows as $row) {
            if (strcmp($row['end'], $row['start']) <= 0) {
                return "{$row['start']}–{$row['end']} must end after it starts.";
            }
            if ($previousEnd !== null && strcmp($row['start'], $previousEnd) < 0) {
                return "{$row['start']}–{$row['end']} overlaps another interval.";
            }
            $previousEnd = $row['end'];
        }

        return null;
    }

    public function render()
    {
        return view('abs::livewire.schedule-editor', [
            'days' => self::DAYS,
            'canEdit' => app(BusinessAccess::class)->can(auth()->user(), $this->business(), 'abs_schedule.edit'),
        ]);
    }

    private function staff(): Staff
    {
        return $this->business()->staff()->findOrFail($this->staffId);
    }
}
