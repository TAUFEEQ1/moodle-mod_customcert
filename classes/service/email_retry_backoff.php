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

defined('MOODLE_INTERNAL') || die();

/**
 * Exponential backoff for retrying a failed student certificate email.
 *
 * A failed send leaves the issue in the explicit retryable state (studentemailed = 0). Without a delay the
 * issue task retried every failing issue on every run, regenerating a certificate PDF each time, which on a
 * site with no working mail transport meant tens of GB of PDFs per day and a full disk.
 *
 * State is one small JSON map in plugin config ({issueid: [attempts, notbefore]}), so no schema change.
 * Delays: 15 min, 30 min, 1 h, 2 h ... capped at 24 h.
 *
 * @package    mod_customcert
 * @copyright  2026 Certificate Manager
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class email_retry_backoff {
    /** @var string Plugin config key holding the retry map. */
    private const CONFIGKEY = 'emailretry';

    /** @var int Delay after the first failure, in seconds. */
    private const BASEDELAY = 900;

    /** @var int Longest delay between attempts, in seconds. */
    private const MAXDELAY = 86400;

    /**
     * Whether a retry of this issue's student email may be attempted now.
     *
     * @param int $issueid
     * @param int|null $now Current time (injectable for tests).
     * @return bool True if there is no recorded failure or its delay has elapsed.
     */
    public static function is_due(int $issueid, ?int $now = null): bool {
        $state = self::load();
        if (!isset($state[$issueid])) {
            return true;
        }
        return ($now ?? time()) >= (int)$state[$issueid][1];
    }

    /**
     * Record a failed attempt and schedule the next one.
     *
     * @param int $issueid
     * @param int|null $now Current time (injectable for tests).
     * @return int Unix time before which no retry will be made.
     */
    public static function record_failure(int $issueid, ?int $now = null): int {
        $now = $now ?? time();
        $state = self::load();
        $attempts = isset($state[$issueid]) ? (int)$state[$issueid][0] + 1 : 1;
        $delay = (int)min(self::MAXDELAY, self::BASEDELAY * (2 ** ($attempts - 1)));
        $state[$issueid] = [$attempts, $now + $delay];
        self::save($state);
        return $now + $delay;
    }

    /**
     * Forget any recorded failure (the email was sent).
     *
     * @param int $issueid
     * @return void
     */
    public static function clear(int $issueid): void {
        $state = self::load();
        if (isset($state[$issueid])) {
            unset($state[$issueid]);
            self::save($state);
        }
    }

    /**
     * Load the retry map.
     *
     * @return array<int, array{0: int, 1: int}>
     */
    private static function load(): array {
        $decoded = json_decode((string)get_config('customcert', self::CONFIGKEY), true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Persist the retry map.
     *
     * @param array<int, array{0: int, 1: int}> $state
     * @return void
     */
    private static function save(array $state): void {
        set_config(self::CONFIGKEY, $state ? json_encode($state) : '', 'customcert');
    }
}
