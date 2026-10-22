<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace mod_customcert\service;

use advanced_testcase;

/**
 * Tests for the failed-student-email retry backoff.
 *
 * @package    mod_customcert
 * @category   test
 * @copyright  2026 Certificate Manager
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_customcert\service\email_retry_backoff
 */
final class email_retry_backoff_test extends advanced_testcase {
    /**
     * An issue with no recorded failure may be attempted straight away.
     */
    public function test_unknown_issue_is_due(): void {
        $this->resetAfterTest();
        $this->assertTrue(email_retry_backoff::is_due(42));
    }

    /**
     * After a failure the issue is not due until the delay has passed, and the delay doubles each time.
     */
    public function test_failures_back_off_exponentially_until_due(): void {
        $this->resetAfterTest();
        $now = 1_800_000_000;

        $this->assertSame($now + 900, email_retry_backoff::record_failure(7, $now));
        $this->assertFalse(email_retry_backoff::is_due(7, $now + 899));
        $this->assertTrue(email_retry_backoff::is_due(7, $now + 900));

        $this->assertSame($now + 1800, email_retry_backoff::record_failure(7, $now));
        $this->assertSame($now + 3600, email_retry_backoff::record_failure(7, $now));
    }

    /**
     * The delay never exceeds 24 hours.
     */
    public function test_delay_is_capped_at_one_day(): void {
        $this->resetAfterTest();
        $now = 1_800_000_000;

        for ($i = 0; $i < 20; $i++) {
            $next = email_retry_backoff::record_failure(9, $now);
        }
        $this->assertSame($now + DAYSECS, $next);
    }

    /**
     * Failures are tracked per issue, and clearing one does not affect another.
     */
    public function test_state_is_per_issue_and_clearable(): void {
        $this->resetAfterTest();
        $now = 1_800_000_000;

        email_retry_backoff::record_failure(1, $now);
        email_retry_backoff::record_failure(2, $now);
        $this->assertFalse(email_retry_backoff::is_due(1, $now));
        $this->assertFalse(email_retry_backoff::is_due(2, $now));

        email_retry_backoff::clear(1);
        $this->assertTrue(email_retry_backoff::is_due(1, $now));
        $this->assertFalse(email_retry_backoff::is_due(2, $now));

        email_retry_backoff::clear(2);
        $this->assertSame('', (string)get_config('customcert', 'emailretry'));
    }
}
