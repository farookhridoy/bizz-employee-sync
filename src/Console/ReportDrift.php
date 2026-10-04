<?php

namespace Bizzsol\EmployeeSync\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Read-only report of identity drift between users, hr_as_basic_info, hrms_employees, hrms_employee_users and
 * user_priorities. Changes nothing. Exit code 1 with --fail when anything is found (for CI / schedulers).
 *
 *   php artisan employee-sync:report [--limit=10] [--fail]
 */
class ReportDrift extends Command
{
    protected $signature = 'employee-sync:report {--limit=10 : sample rows shown per check} {--fail : exit 1 when drift is found}';

    protected $description = 'Report (read-only) drift between users, hr_as_basic_info, hrms_employees and their links';

    public function handle(): int
    {
        $t = config('employee-sync.tables');
        [$u, $b, $e, $l, $p] = [$t['users'], $t['basic_info'], $t['employees'], $t['employee_users'], $t['priorities']];
        $limit = (int) $this->option('limit');

        $checks = [
            'Users sharing one hr_as_basic_info row' => DB::table($u)->whereNull("$u.deleted_at")->whereNotNull('hr_as_basic_info_id')
                ->join(DB::raw("(select hr_as_basic_info_id h, count(*) n from `$u` where deleted_at is null and hr_as_basic_info_id is not null group by 1 having n > 1) s"), 's.h', '=', "$u.hr_as_basic_info_id")
                ->select("$u.id", "$u.email", "$u.associate_id", "$u.hr_as_basic_info_id"),

            'users.associate_id differs from its hr_as_basic_info.associate_id' => DB::table($u)->whereNull("$u.deleted_at")
                ->join($b, "$b.id", '=', "$u.hr_as_basic_info_id")->whereColumn("$u.associate_id", '!=', "$b.associate_id")
                ->select("$u.id", "$u.email", "$u.associate_id as user_assoc", "$b.associate_id as basic_assoc"),

            'users.associate_id differs from linked hrms_employees.uid' => DB::table($u)->whereNull("$u.deleted_at")
                ->join($l, fn ($j) => $j->on("$l.user_id", '=', "$u.id")->whereNull("$l.deleted_at"))
                ->join($e, "$e.id", '=', "$l.employee_id")->whereColumn("$u.associate_id", '!=', "$e.uid")
                ->select("$u.id", "$u.email", "$u.associate_id as user_assoc", "$e.uid as employee_uid"),

            'Users with no active hrms_employee_users link' => DB::table($u)->whereNull("$u.deleted_at")
                ->whereNotExists(fn ($q) => $q->from($l)->whereColumn("$l.user_id", "$u.id")->whereNull("$l.deleted_at"))
                ->select("$u.id", "$u.email", "$u.associate_id"),

            'Employees with more than one active user link' => DB::table($l)->whereNull('deleted_at')->groupBy('employee_id')
                ->havingRaw('count(*) > 1')->select('employee_id', DB::raw('count(*) as links')),

            'Users with more than one active employee link' => DB::table($l)->whereNull('deleted_at')->groupBy('user_id')
                ->havingRaw('count(*) > 1')->select('user_id', DB::raw('count(*) as links')),

            'Duplicate hrms_employees.uid' => DB::table($e)->groupBy('uid')->havingRaw('count(*) > 1')->select('uid', DB::raw('count(*) as n')),

            'Duplicate hr_as_basic_info.associate_id' => DB::table($b)->whereNotNull('associate_id')->groupBy('associate_id')
                ->havingRaw('count(*) > 1')->select('associate_id', DB::raw('count(*) as n')),

            'Duplicate users.associate_id' => DB::table($u)->whereNull('deleted_at')->whereNotNull('associate_id')->groupBy('associate_id')
                ->havingRaw('count(*) > 1')->select('associate_id', DB::raw('count(*) as n')),

            'Employees whose reporting manager does not exist' => DB::table($e)->where('reporting_manager_id', '>', 0)
                ->whereNotExists(fn ($q) => $q->from("$e as m")->whereColumn('m.id', "$e.reporting_manager_id"))
                ->select('id', 'uid', 'reporting_manager_id'),

            'Employees with no reporting manager (0 / null)' => DB::table($e)->where(fn ($q) => $q->whereNull('reporting_manager_id')->orWhere('reporting_manager_id', 0))
                ->select('id', 'uid'),

            'Linked users without a priority for the employee\'s home department' => DB::table($l)->whereNull("$l.deleted_at")
                ->join($e, "$e.id", '=', "$l.employee_id")->whereNotNull("$e.main_department_id")
                ->whereNotExists(fn ($q) => $q->from($p)->whereColumn("$p.user_id", "$l.user_id")->whereColumn("$p.hr_department_id", "$e.main_department_id")->whereNull("$p.deleted_at"))
                ->select("$l.user_id", "$e.uid", "$e.main_department_id"),
        ];

        $total = 0;
        foreach ($checks as $title => $query) {
            $count = (clone $query)->count();
            $total += $count;
            $this->line(($count ? '<fg=yellow>' : '<fg=green>').str_pad((string) $count, 6, ' ', STR_PAD_LEFT).'</>  '.$title);
            if ($count && $limit > 0) {
                $this->table(array_keys((array) $query->first()), $query->limit($limit)->get()->map(fn ($r) => (array) $r)->all());
            }
        }

        $this->newLine();
        $this->info($total ? "{$total} drift row(s) found. Nothing was changed." : 'No drift found.');

        return $total && $this->option('fail') ? self::FAILURE : self::SUCCESS;
    }
}
