<?php

namespace Bizzsol\EmployeeSync\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Idempotent sync of one person across the ERP's identity tables. hrms_employees is the source of truth;
 * everything is keyed on its `uid`:
 *
 *   hrms_employees.uid == hr_as_basic_info.associate_id == users.associate_id
 *   users.hr_as_basic_info_id -> hr_as_basic_info.id
 *   hrms_employee_users: exactly one active row per employee (and per user)
 *   user_priorities: diffed against the wanted set, never wiped
 *
 * Running it twice with the same input changes nothing the second time. Uses the query builder only, so it
 * works from any app (and from seeders) without depending on that app's models.
 *
 * $access (every key optional; null/absent = leave that part untouched):
 *   'basic'      => ['as_doj'=>..,'as_dob'=>..,'as_contact'=>..,'as_ot'=>..,'created_by'=>..]  extra hr_as_basic_info columns
 *   'user_id'    => int  sync this existing user instead of looking one up (caller already saved it)
 *   'user'       => ['name','email','phone','panel','password_hash']  create/update the login user.
 *                   A new user needs password_hash (already hashed). Without 'user', only an existing user is synced.
 *   'priorities' => [['unit_id'=>..,'department_id'=>..,'section_id'=>..|null], ...]  complete wanted set
 *   'roles'      => ['Role name', ...]  complete wanted set (needs spatie/laravel-permission)
 */
class EmployeeAccessSync
{
    /** @return array{employee_id:int,uid:string,basic_info_id:int,user_id:?int,changes:array<string,mixed>} */
    public function sync(int $employeeId, array $access = []): array
    {
        return DB::transaction(function () use ($employeeId, $access) {
            $t = config('employee-sync.tables');
            $employee = DB::table($t['employees'])->where('id', $employeeId)->first();
            if (! $employee || ! filled($employee->uid)) {
                throw new \InvalidArgumentException("hrms_employees #{$employeeId} not found or has no uid.");
            }

            $changes = [];
            $basicId = $this->syncBasicInfo($employee, $access['basic'] ?? [], $t, $changes);
            $userId = $this->syncUser($employee, $basicId, $access['user'] ?? null, $access['user_id'] ?? null, $t, $changes);

            if ($userId) {
                $this->syncLink($employee->id, $userId, $t, $changes);
                if (array_key_exists('priorities', $access) && $access['priorities'] !== null) {
                    $this->syncPriorities($userId, $access['priorities'], $t, $changes);
                }
                if (array_key_exists('roles', $access) && $access['roles'] !== null) {
                    $this->syncRoles($userId, $access['roles'], $changes);
                }
            }

            return ['employee_id' => $employee->id, 'uid' => $employee->uid, 'basic_info_id' => $basicId, 'user_id' => $userId, 'changes' => $changes];
        });
    }

    private function syncBasicInfo(object $e, array $extra, array $t, array &$changes): int
    {
        $row = array_filter([
            'as_name' => $this->displayName($e),
            'as_gender' => $e->gender ?? null,
            'as_designation_id' => $e->main_designation_id,
            'as_unit_id' => $e->main_unit_id,
            'as_department_id' => $e->main_department_id,
            'as_section_id' => $e->main_section_id,
            'as_location' => $e->main_location_id,
        ], fn ($v) => $v !== null && $v !== '');
        $row = array_merge($row, array_filter($extra, fn ($v) => $v !== null));

        $existing = DB::table($t['basic_info'])->where('associate_id', $e->uid)->first();
        if ($existing) {
            $diff = array_filter($row, fn ($v, $k) => ! $this->same($existing->{$k} ?? null, $v), ARRAY_FILTER_USE_BOTH);
            if ($diff) {
                DB::table($t['basic_info'])->where('id', $existing->id)->update($diff + ['updated_at' => now()]);
                $changes['basic_info'] = array_keys($diff);
            }

            return (int) $existing->id;
        }

        $id = DB::table($t['basic_info'])->insertGetId($row + [
            'associate_id' => $e->uid, 'as_status' => 1, 'as_doj' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $changes['basic_info'] = 'created';

        return (int) $id;
    }

    private function syncUser(object $e, int $basicId, ?array $input, ?int $explicitUserId, array $t, array &$changes): ?int
    {
        if ($explicitUserId) {
            $user = DB::table($t['users'])->where('id', $explicitUserId)->whereNull('deleted_at')->first()
                ?? throw new \InvalidArgumentException("users #{$explicitUserId} not found.");
        } else {
            $user = $this->findUser($e, $t);
        }

        $wanted = ['associate_id' => $e->uid, 'hr_as_basic_info_id' => $basicId];
        foreach (['name', 'email', 'phone', 'panel'] as $k) {
            if ($input && array_key_exists($k, $input)) {
                $wanted[$k] = $input[$k];
            }
        }
        if ($input && ! empty($input['password_hash'])) {
            $wanted['password'] = $input['password_hash'];
        }

        if ($user) {
            $diff = array_filter($wanted, fn ($v, $k) => ! $this->same($user->{$k} ?? null, $v), ARRAY_FILTER_USE_BOTH);
            if ($diff) {
                DB::table($t['users'])->where('id', $user->id)->update($diff + ['updated_at' => now()]);
                $changes['user'] = array_keys($diff);
            }

            return (int) $user->id;
        }

        if (! $input) {
            return null;
        }
        if (empty($input['password_hash'])) {
            throw new \InvalidArgumentException('Creating a user needs access[user][password_hash].');
        }

        $wanted += ['name' => $this->displayName($e), 'panel' => 'main', 'token' => Str::random(32), 'created_at' => now(), 'updated_at' => now()];
        $changes['user'] = 'created';

        return (int) DB::table($t['users'])->insertGetId($wanted);
    }

    private function findUser(object $e, array $t): ?object
    {
        return DB::table($t['employee_users'])->where($t['employee_users'].'.employee_id', $e->id)->whereNull($t['employee_users'].'.deleted_at')
            ->join($t['users'], $t['users'].'.id', '=', $t['employee_users'].'.user_id')->whereNull($t['users'].'.deleted_at')
            ->select($t['users'].'.*')->first()
            ?? DB::table($t['users'])->where('associate_id', $e->uid)->whereNull('deleted_at')->first();
    }

    /** exactly one active link per employee and per user */
    private function syncLink(int $employeeId, int $userId, array $t, array &$changes): void
    {
        $links = DB::table($t['employee_users']);
        $same = (clone $links)->where(['employee_id' => $employeeId, 'user_id' => $userId])->whereNull('deleted_at')->value('id');

        // retire any other active link for this employee or this user
        $stale = DB::table($t['employee_users'])->whereNull('deleted_at')
            ->where(fn ($q) => $q->where('employee_id', $employeeId)->orWhere('user_id', $userId))
            ->when($same, fn ($q) => $q->where('id', '!=', $same))->pluck('id');
        if ($stale->isNotEmpty()) {
            DB::table($t['employee_users'])->whereIn('id', $stale)->update(['deleted_at' => now()]);
            $changes['link_retired'] = $stale->all();
        }

        if (! $same) {
            DB::table($t['employee_users'])->insert(['employee_id' => $employeeId, 'user_id' => $userId, 'created_at' => now(), 'updated_at' => now()]);
            $changes['link'] = 'created';
        }
    }

    private function syncPriorities(int $userId, array $wanted, array $t, array &$changes): void
    {
        $key = fn ($u, $d, $s) => $u.'|'.$d.'|'.($s ?? '');
        $want = [];
        foreach ($wanted as $p) {
            $want[$key($p['unit_id'], $p['department_id'], $p['section_id'] ?? null)] = $p;
        }

        $have = DB::table($t['priorities'])->where('user_id', $userId)->whereNull('deleted_at')->orderBy('id')->get()
            ->groupBy(fn ($r) => $key($r->hr_unit_id, $r->hr_department_id, $r->hr_section_id));

        // every row of an unwanted key goes; of a wanted key only the first row stays (removes duplicates too)
        $remove = collect();
        foreach ($have as $k => $rows) {
            $remove = $remove->merge(isset($want[$k]) ? $rows->skip(1)->pluck('id') : $rows->pluck('id'));
        }
        $add = array_diff_key($want, $have->all());

        if ($remove->isNotEmpty()) {
            DB::table($t['priorities'])->whereIn('id', $remove->all())->update(['deleted_at' => now()]);
        }
        foreach ($add as $p) {
            DB::table($t['priorities'])->insert([
                'user_id' => $userId, 'hr_unit_id' => $p['unit_id'], 'hr_department_id' => $p['department_id'],
                'hr_section_id' => $p['section_id'] ?? null, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        if ($remove->isNotEmpty() || $add) {
            $changes['priorities'] = ['added' => count($add), 'removed' => $remove->count()];
        }
    }

    private function syncRoles(int $userId, array $roles, array &$changes): void
    {
        $userClass = config('auth.providers.users.model');
        if ($user = $userClass::find($userId)) {
            $before = $user->roles->pluck('name')->sort()->values()->all();
            $user->syncRoles($roles);
            if ($before !== collect($roles)->sort()->values()->all()) {
                $changes['roles'] = $roles;
            }
        }
    }

    /** string compare, but a bare date ("2024-01-01") equals the same day stored as a datetime */
    private function same($current, $wanted): bool
    {
        $a = (string) $current;
        $b = $wanted instanceof \DateTimeInterface ? $wanted->format('Y-m-d H:i:s') : (string) $wanted;

        return $a === $b || (strlen($b) === 10 && str_starts_with($a, $b.' '));
    }

    /** first/middle/last may be plain text or a {"en":..,"bn":..} translation JSON (HRMS) */
    private function displayName(object $e): string
    {
        $part = function ($v) {
            $d = is_string($v) ? json_decode($v, true) : null;

            return trim(is_array($d) ? ($d['en'] ?? reset($d) ?: '') : (string) $v);
        };

        return trim(preg_replace('/\s+/', ' ', $part($e->first_name ?? '').' '.$part($e->last_name ?? ''))) ?: (string) $e->uid;
    }
}
