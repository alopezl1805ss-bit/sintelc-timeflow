<?php

namespace Tests;

use App\Services\AttendanceEmployeeGuard;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // El guardarraíl memoiza por proceso y SyncAttendanceToFactorial lo
        // llama siempre; con RefreshDatabase los ids se repiten entre pruebas.
        AttendanceEmployeeGuard::flush();
    }
}
