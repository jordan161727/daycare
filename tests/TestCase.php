<?php

namespace Tests;

use App\Models\Setting;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Setting keeps the overrides in a static for the length of a request.
        // A test process is many requests over many databases, so a value one
        // test wrote (the centre's name format, say) must not be read by the
        // next — the ones that happened to run after NameFormatTest found out.
        Setting::forget();
    }
}
