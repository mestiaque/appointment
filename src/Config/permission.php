<?php

/*
| Declared in metheme format: module => [title, actions]. Each key becomes "module.action".
| Business-scoped checks also need a membership role that allows the action (ME\Abs\Enums\BusinessRole).
*/
return [
    'abs_business' => [
        'title' => 'Booking: Businesses',
        'actions' => 'view,create,edit,delete,all',
    ],
    'abs_branch' => [
        'title' => 'Booking: Branches',
        'actions' => 'view,create,edit,delete',
    ],
    'abs_service' => [
        'title' => 'Booking: Services',
        'actions' => 'view,create,edit,delete',
    ],
    'abs_staff' => [
        'title' => 'Booking: Staff',
        'actions' => 'view,create,edit,delete',
    ],
    'abs_schedule' => [
        'title' => 'Booking: Schedules & Time Off',
        'actions' => 'view,edit',
    ],
    'abs_appointment' => [
        'title' => 'Booking: Appointments',
        'actions' => 'view,create,edit,approve,cancel,reschedule,status',
    ],
    'abs_payment' => [
        'title' => 'Booking: Payments',
        'actions' => 'view,record,refund',
    ],
    'abs_report' => [
        'title' => 'Booking: Reports',
        'actions' => 'view,export',
    ],
    'abs_setting' => [
        'title' => 'Booking: Settings',
        'actions' => 'view,edit',
    ],
    'abs_diagnostic' => [
        'title' => 'Booking: System Diagnostics',
        'actions' => 'view',
    ],
];
