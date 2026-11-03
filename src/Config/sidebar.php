<?php

return [
    [
        'title' => 'Booking Dashboard',
        'icon' => 'fas fa-calendar-check',
        'route' => 'abs.dashboard',
        'for_active' => 'abs.dashboard',
        'permit' => 'abs_appointment.view',
        'icon_color' => 'icc-38',
        'sl' => 10,
    ],
    [
        'title' => 'Appointments',
        'icon' => 'fas fa-calendar-alt',
        'icon_color' => 'icc-81',
        'sl' => 11,
        'children' => [
            ['title' => 'All Appointments', 'icon' => 'fas fa-list', 'route' => 'abs.appointments.index', 'for_active' => 'abs.appointments.', 'permit' => 'abs_appointment.view', 'icon_color' => 'icc-81'],
            ['title' => 'Calendar', 'icon' => 'fas fa-calendar-week', 'route' => 'abs.calendar', 'for_active' => 'abs.calendar', 'permit' => 'abs_appointment.view', 'icon_color' => 'icc-38'],
            ['title' => 'New Booking', 'icon' => 'fas fa-plus-circle', 'route' => 'abs.appointments.create', 'for_active' => 'abs.appointments.create', 'permit' => 'abs_appointment.create', 'icon_color' => 'icc-55'],
            ['title' => 'Payments', 'icon' => 'fas fa-money-bill-wave', 'route' => 'abs.payments.index', 'for_active' => 'abs.payments.', 'permit' => 'abs_payment.view', 'icon_color' => 'icc-40'],
        ],
    ],
    [
        'title' => 'Catalog & Staff',
        'icon' => 'fas fa-concierge-bell',
        'icon_color' => 'icc-67',
        'sl' => 12,
        'children' => [
            ['title' => 'Service Categories', 'icon' => 'fas fa-tags', 'route' => 'abs.categories.index', 'for_active' => 'abs.categories.', 'permit' => 'abs_service.view', 'icon_color' => 'icc-67'],
            ['title' => 'Services', 'icon' => 'fas fa-concierge-bell', 'route' => 'abs.services.index', 'for_active' => 'abs.services.', 'permit' => 'abs_service.view', 'icon_color' => 'icc-38'],
            ['title' => 'Staff', 'icon' => 'fas fa-user-tie', 'route' => 'abs.staff.index', 'for_active' => 'abs.staff.', 'permit' => 'abs_staff.view', 'icon_color' => 'icc-81'],
            ['title' => 'Holidays', 'icon' => 'fas fa-umbrella-beach', 'route' => 'abs.holidays.index', 'for_active' => 'abs.holidays.', 'permit' => 'abs_schedule.view', 'icon_color' => 'icc-55'],
        ],
    ],
    [
        'title' => 'Business',
        'icon' => 'fas fa-store',
        'icon_color' => 'icc-40',
        'sl' => 13,
        'children' => [
            ['title' => 'Businesses', 'icon' => 'fas fa-building', 'route' => 'abs.businesses.index', 'for_active' => 'abs.businesses.', 'permit' => 'abs_business.view', 'icon_color' => 'icc-40'],
            ['title' => 'Branches', 'icon' => 'fas fa-code-branch', 'route' => 'abs.branches.index', 'for_active' => 'abs.branches.', 'permit' => 'abs_branch.view', 'icon_color' => 'icc-67'],
            ['title' => 'Team Members', 'icon' => 'fas fa-users', 'route' => 'abs.members.index', 'for_active' => 'abs.members.', 'permit' => 'abs_business.edit', 'icon_color' => 'icc-81'],
            ['title' => 'Payment Gateways', 'icon' => 'fas fa-credit-card', 'route' => 'abs.gateways.index', 'for_active' => 'abs.gateways.', 'permit' => 'abs_setting.view', 'icon_color' => 'icc-55'],
            ['title' => 'Booking Settings', 'icon' => 'fas fa-sliders-h', 'route' => 'abs.settings.edit', 'for_active' => 'abs.settings.', 'permit' => 'abs_setting.view', 'icon_color' => 'icc-38'],
        ],
    ],
    [
        'title' => 'Booking Reports',
        'icon' => 'fas fa-chart-line',
        'icon_color' => 'icc-55',
        'sl' => 14,
        'children' => [
            ['title' => 'Reports', 'icon' => 'fas fa-chart-pie', 'route' => 'abs.reports.index', 'for_active' => 'abs.reports.', 'permit' => 'abs_report.view', 'icon_color' => 'icc-55'],
            ['title' => 'Diagnostics', 'icon' => 'fas fa-stethoscope', 'route' => 'abs.diagnostics', 'for_active' => 'abs.diagnostics', 'permit' => 'abs_diagnostic.view', 'icon_color' => 'icc-40'],
        ],
    ],
];
