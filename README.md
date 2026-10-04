# bizzsol/employee-sync

Keeps the ERP's identity tables consistent. All ERP apps share one database; `hrms_employees` is the source of truth and everything is keyed on its `uid`:

```
hrms_employees.uid == hr_as_basic_info.associate_id == users.associate_id
users.hr_as_basic_info_id -> hr_as_basic_info.id
hrms_employee_users : one active row per employee and per user
user_priorities     : unit / department / section scope per user
```

## Step 1 (this release): service + read-only report

Nothing is wired into any app yet.

### Drift report (changes nothing)

```bash
php artisan employee-sync:report            # counts + 10 sample rows per check
php artisan employee-sync:report --limit=0  # counts only
php artisan employee-sync:report --fail     # exit 1 if any drift (CI / scheduler)
```

Checks: users sharing one `hr_as_basic_info` row; `users.associate_id` vs basic-info / employee `uid`; users with no link; multiple links; duplicate `uid` / `associate_id`; missing reporting managers; linked users without a priority for their home department.

### `EmployeeAccessSync` (idempotent)

```php
app(\Bizzsol\EmployeeSync\Services\EmployeeAccessSync::class)->sync($hrmsEmployeeId, [
    'user'       => ['name' => '...', 'email' => '...', 'password_hash' => Hash::make('...')], // optional
    'priorities' => [['unit_id' => 2, 'department_id' => 239, 'section_id' => null]],            // complete wanted set
    'roles'      => ['Employee'],                                                                 // complete wanted set
]);
```

Upserts basic info, user, link, priorities, companies and cost centres in one transaction and returns what changed.

**It never wipes.** It adds what is missing and soft-deletes a row only when that row is inside the optional `*_scope` (the ids the form could show) and is no longer wanted. Rows outside the scope (e.g. under a retired profit centre) are never touched, duplicate rows are left alone, and a department-level priority row (`hr_section_id` NULL) counts as covering that department's sections. Running it twice changes nothing the second time. Omitted keys are left untouched.

## Install

```json
"require": { "bizzsol/employee-sync": "^0.1" },
"repositories": [{ "type": "vcs", "url": "https://github.com/farookhridoy/bizz-employee-sync.git", "no-api": true }]
```

Config (`employee-sync.php`, publish with `--tag=employee-sync-config`) holds the table names and the `observers` flag reserved for the next step.

Optional `companies` and `cost_centres` keys: complete wanted id sets for `user_companies` / `user_cost_centres` (diffed). `user.cost_centre_id` sets the user's own cost centre.

Optional `user_id` key: sync that existing user (the caller already saved it) instead of looking one up.

Optional `basic` key: extra `hr_as_basic_info` columns (`as_doj`, `as_dob`, `as_contact`, `as_ot`, `created_by`, ...).

## Roadmap
2. HRMS `EmployeesController` calls the service. 3. User-admin screens in main/finance/pmd call it. 4. Observers + `DataImport`. 5. Unique indexes once the report is clean.
