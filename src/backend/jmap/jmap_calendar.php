<?php
/***********************************************
* File      :   jmap_calendar.php
* Project   :   Z-Push
* Descr     :   JSCalendar (RFC 8984) <-> SyncAppointment conversion
*               for the JMAP backend. No external dependencies.
*
* Copyright 2024 - Z-Push Contributors
* AGPL-3.0 - see LICENSE
************************************************/

class JmapCalendarConverter {

    // -------------------------------------------------------------------------
    // JSCalendar Event → SyncAppointment
    // -------------------------------------------------------------------------

    public static function eventToSyncAppointment(array $event): SyncAppointment {
        $a = new SyncAppointment();

        $a->subject = $event['title'] ?? '(No Subject)';
        $a->uid     = $event['uid']   ?? '';

        // Time
        $tz    = $event['timeZone'] ?? 'UTC';
        $start = $event['start']    ?? '';
        $startTs = $start ? self::localToTimestamp($start, $tz) : time();
        $durSecs = self::parseDuration($event['duration'] ?? 'PT1H');

        $a->starttime   = $startTs;
        $a->endtime     = $startTs + $durSecs;
        $a->alldayevent = !empty($event['showWithoutTime']) ? 1 : 0;

        // Description / body
        if (!empty($event['description'])) {
            $desc = $event['description'];
            $a->asbody = new SyncBaseBody();
            $a->asbody->type              = SYNC_BODYPREFERENCE_PLAIN;
            $a->asbody->data              = StringStreamWrapper::Open($desc);
            $a->asbody->estimatedDataSize = strlen($desc);
            $a->asbody->truncated         = 0;
        }

        // Location
        $locations = array_values($event['locations'] ?? []);
        if ($locations) $a->location = $locations[0]['name'] ?? null;

        // Organizer + attendees
        $attendees = [];
        foreach ($event['participants'] ?? [] as $p) {
            $roles = $p['roles'] ?? [];
            $name  = $p['name']  ?? '';
            $email = $p['email'] ?? $p['sendTo']['imip'] ?? '';

            if (isset($roles['organizer'])) {
                $a->organizername  = $name;
                $a->organizeremail = $email;
            } else {
                $att = new SyncAttendee();
                $att->name  = $name;
                $att->email = $email;

                $att->attendeestatus = match(strtolower($p['participationStatus'] ?? 'needs-action')) {
                    'accepted'  => 3,
                    'declined'  => 4,
                    'tentative' => 2,
                    default     => 0,
                };
                $att->attendeetype = match(true) {
                    isset($roles['optional'])      => 2,
                    isset($roles['informational']) => 3,
                    default                        => 1,
                };
                $attendees[] = $att;
            }
        }
        if ($attendees) {
            $a->attendees    = $attendees;
            $a->meetingstatus = 1;
        }

        // Sensitivity
        $a->sensitivity = match($event['privacy'] ?? 'public') {
            'private', 'secret' => 2,
            'confidential'      => 3,
            default             => 0,
        };

        // Free/busy
        $a->busystatus = match($event['freeBusyStatus'] ?? 'busy') {
            'free'        => 0,
            'tentative'   => 1,
            'unavailable' => 3,
            default       => 2,
        };

        // Reminder (first alert with negative offset)
        foreach ($event['alerts'] ?? [] as $alert) {
            $offset = $alert['trigger']['offset'] ?? null;
            if ($offset && str_starts_with($offset, '-')) {
                $secs = self::parseDuration(ltrim($offset, '-'));
                $a->reminder = max(0, (int)($secs / 60));
                break;
            }
        }

        // Recurrence
        $rules = $event['recurrenceRules'] ?? [];
        if ($rules) {
            $a->recurrence = self::jmapRuleToSyncRecurrence($rules[0]);
        }

        // Categories
        $cats = array_keys($event['categories'] ?? []);
        if ($cats) $a->categories = $cats;

        return $a;
    }

    // -------------------------------------------------------------------------
    // SyncAppointment → JSCalendar Event
    // -------------------------------------------------------------------------

    public static function syncAppointmentToEvent(SyncAppointment $a, string $calendarId): array {
        $startTs = $a->starttime ?? time();
        $endTs   = $a->endtime   ?? ($startTs + 3600);
        $allDay  = !empty($a->alldayevent);

        $event = [
            '@type'       => 'Event',
            'calendarIds' => [$calendarId => true],
            'title'       => $a->subject ?? '(No Subject)',
            'uid'         => !empty($a->uid) ? $a->uid : self::newUid(),
        ];

        if ($allDay) {
            $event['showWithoutTime'] = true;
            $event['start']           = gmdate('Y-m-d', $startTs);
            $event['duration']        = 'P' . max(1, intdiv($endTs - $startTs, 86400)) . 'D';
            $event['timeZone']        = 'UTC';
        } else {
            $event['start']    = gmdate('Y-m-d\TH:i:s', $startTs);
            $event['duration'] = self::secondsToDuration($endTs - $startTs);
            $event['timeZone'] = 'UTC';
        }

        // Location
        if (!empty($a->location)) {
            $event['locations'] = ['l1' => ['@type' => 'Location', 'name' => $a->location]];
        }

        // Description
        if (!empty($a->body)) {
            $event['description'] = $a->body;
        }

        // Organizer + attendees
        $participants = [];
        if (!empty($a->organizeremail)) {
            $participants['org'] = [
                '@type' => 'Participant',
                'name'  => $a->organizername ?? $a->organizeremail,
                'email' => $a->organizeremail,
                'roles' => ['organizer' => true, 'attendee' => true],
            ];
        }
        foreach ($a->attendees ?? [] as $i => $att) {
            $participants['att' . $i] = [
                '@type'               => 'Participant',
                'name'                => $att->name  ?? '',
                'email'               => $att->email ?? '',
                'roles'               => match((int)($att->attendeetype ?? 1)) {
                    2 => ['optional'      => true],
                    3 => ['informational' => true],
                    default => ['attendee'  => true],
                },
                'participationStatus' => match((int)($att->attendeestatus ?? 0)) {
                    3 => 'accepted',
                    4 => 'declined',
                    2 => 'tentative',
                    default => 'needs-action',
                },
            ];
        }
        if ($participants) $event['participants'] = $participants;

        // Sensitivity
        $event['privacy'] = match((int)($a->sensitivity ?? 0)) {
            2, 1 => 'private',
            3    => 'secret',
            default => 'public',
        };

        // Free/busy
        $event['freeBusyStatus'] = match((int)($a->busystatus ?? 2)) {
            0 => 'free',
            1 => 'tentative',
            3 => 'unavailable',
            default => 'busy',
        };

        // Reminder
        if (!empty($a->reminder)) {
            $mins = (int)$a->reminder;
            $event['alerts'] = ['al1' => [
                '@type'   => 'Alert',
                'trigger' => [
                    '@type'      => 'OffsetTrigger',
                    'offset'     => '-PT' . $mins . 'M',
                    'relativeTo' => 'start',
                ],
                'action'  => 'display',
            ]];
        }

        // Recurrence
        if (!empty($a->recurrence)) {
            $rule = self::syncRecurrenceToJmap($a->recurrence);
            if ($rule) $event['recurrenceRules'] = [$rule];
        }

        // Categories
        if (!empty($a->categories)) {
            $cats = [];
            foreach ($a->categories as $cat) $cats[$cat] = true;
            $event['categories'] = $cats;
        }

        return $event;
    }

    // -------------------------------------------------------------------------
    // Mod hash for change detection
    // -------------------------------------------------------------------------

    public static function eventModHash(array $event): string {
        $key = ($event['title'] ?? '') .
               ($event['start'] ?? '') .
               ($event['duration'] ?? '') .
               ($event['updated'] ?? '');
        return sprintf('%08x', crc32($key));
    }

    // -------------------------------------------------------------------------
    // Recurrence helpers
    // -------------------------------------------------------------------------

    private static function jmapRuleToSyncRecurrence(array $rule): SyncRecurrence {
        $r = new SyncRecurrence();

        $freq   = strtolower($rule['frequency'] ?? 'daily');
        $byDay  = $rule['byDay']      ?? [];
        $byPos  = $rule['bySetPosition'] ?? [];

        // Monthly nth-day vs plain monthly
        if ($freq === 'monthly' && $byDay && $byPos) {
            $r->type = 3; // Monthly nth day
        } elseif ($freq === 'yearly' && ($byDay || $byPos)) {
            $r->type = 6;
        } else {
            $r->type = match($freq) {
                'daily'   => 0,
                'weekly'  => 1,
                'monthly' => 2,
                'yearly'  => 5,
                default   => 0,
            };
        }

        $r->interval = max(1, (int)($rule['interval'] ?? 1));

        if (!empty($rule['count'])) {
            $r->occurrences = (int)$rule['count'];
        } elseif (!empty($rule['until'])) {
            $r->until = self::localToTimestamp($rule['until'], 'UTC');
        }

        // Day-of-week bitmask
        if ($byDay) {
            $map  = ['su' => 1, 'mo' => 2, 'tu' => 4, 'we' => 8, 'th' => 16, 'fr' => 32, 'sa' => 64];
            $mask = 0;
            foreach ($byDay as $d) {
                $mask |= $map[strtolower($d['day'] ?? '')] ?? 0;
            }
            if ($mask) $r->dayofweek = $mask;

            // nth occurrence within month
            if ($byPos) {
                $r->weekofmonth = (int)$byPos[0] === -1 ? 5 : (int)$byPos[0];
            }
        }

        if (!empty($rule['byMonthDay'])) $r->dayofmonth  = (int)$rule['byMonthDay'][0];
        if (!empty($rule['byMonth']))    $r->monthofyear = (int)$rule['byMonth'][0];

        return $r;
    }

    private static function syncRecurrenceToJmap(SyncRecurrence $r): ?array {
        $type = (int)($r->type ?? 0);
        $rule = [
            '@type'     => 'RecurrenceRule',
            'frequency' => match($type) {
                0    => 'daily',
                1    => 'weekly',
                2, 3 => 'monthly',
                5, 6 => 'yearly',
                default => 'daily',
            },
        ];

        if (!empty($r->interval) && $r->interval > 1) $rule['interval'] = (int)$r->interval;

        if (!empty($r->occurrences)) {
            $rule['count'] = (int)$r->occurrences;
        } elseif (!empty($r->until)) {
            $rule['until'] = gmdate('Y-m-d', $r->until);
        }

        if (!empty($r->dayofweek)) {
            $map = [1 => 'su', 2 => 'mo', 4 => 'tu', 8 => 'we', 16 => 'th', 32 => 'fr', 64 => 'sa'];
            $byDay = [];
            $nDay  = [];
            foreach ($map as $bit => $day) {
                if ($r->dayofweek & $bit) {
                    $entry = ['@type' => 'NDay', 'day' => $day];
                    $byDay[] = $entry;
                    $nDay[]  = $day;
                }
            }
            $rule['byDay'] = $byDay;
            if (!empty($r->weekofmonth)) {
                $rule['bySetPosition'] = [$r->weekofmonth === 5 ? -1 : (int)$r->weekofmonth];
            }
        }

        if (!empty($r->dayofmonth))  $rule['byMonthDay'] = [(int)$r->dayofmonth];
        if (!empty($r->monthofyear)) $rule['byMonth']    = [(string)(int)$r->monthofyear];

        return $rule;
    }

    // -------------------------------------------------------------------------
    // Time utilities
    // -------------------------------------------------------------------------

    public static function localToTimestamp(string $datetime, string $tz): int {
        try {
            $dt = new \DateTime($datetime, new \DateTimeZone($tz));
            return $dt->getTimestamp();
        } catch (\Throwable) {
            return 0;
        }
    }

    public static function parseDuration(string $duration): int {
        $neg = str_starts_with($duration, '-');
        $d   = ltrim($duration, '-');
        if (!preg_match('/^P(?:(\d+)Y)?(?:(\d+)M)?(?:(\d+)W)?(?:(\d+)D)?(?:T(?:(\d+)H)?(?:(\d+)M)?(?:(\d+)S)?)?$/', $d, $m)) {
            return 0;
        }
        $secs = (int)($m[1] ?? 0) * 365 * 86400
              + (int)($m[2] ?? 0) * 30  * 86400
              + (int)($m[3] ?? 0) * 7   * 86400
              + (int)($m[4] ?? 0)       * 86400
              + (int)($m[5] ?? 0)       * 3600
              + (int)($m[6] ?? 0)       * 60
              + (int)($m[7] ?? 0);
        return $neg ? -$secs : $secs;
    }

    private static function secondsToDuration(int $seconds): string {
        if ($seconds <= 0) return 'PT0S';
        $days  = intdiv($seconds, 86400); $rem = $seconds % 86400;
        $hours = intdiv($rem, 3600);      $rem %= 3600;
        $mins  = intdiv($rem, 60);
        $secs  = $rem % 60;

        $s = 'P';
        if ($days)  $s .= $days  . 'D';
        $t = '';
        if ($hours) $t .= $hours . 'H';
        if ($mins)  $t .= $mins  . 'M';
        if ($secs)  $t .= $secs  . 'S';
        if ($t)     $s .= 'T' . $t;
        return $s ?: 'PT0S';
    }

    private static function newUid(): string {
        return sprintf('%s@zpush-jmap', bin2hex(random_bytes(16)));
    }
}
