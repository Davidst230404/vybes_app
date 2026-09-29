<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('bookings:expire-holds')
    ->everyMinute();

Schedule::command('event-ticket-orders:expire-holds')
    ->everyMinute();