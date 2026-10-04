<?php

return [
    // Table names (all apps share one database; override only if a table is renamed).
    'tables' => [
        'users' => 'users',
        'basic_info' => 'hr_as_basic_info',
        'employees' => 'hrms_employees',
        'employee_users' => 'hrms_employee_users',
        'priorities' => 'user_priorities',
        'user_companies' => 'user_companies',
        'user_cost_centres' => 'user_cost_centres',
    ],

    // Reserved for the next step (observers that call EmployeeAccessSync on Eloquent writes).
    // Keep false in apps that only read people data.
    'observers' => env('EMPLOYEE_SYNC_OBSERVERS', false),
];
