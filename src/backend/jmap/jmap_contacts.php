<?php
/***********************************************
* File      :   jmap_contacts.php
* Project   :   Z-Push
* Descr     :   JSContact (RFC 9553) <-> SyncContact conversion for
*               the JMAP backend. No external dependencies.
*
* Copyright 2024 - Z-Push Contributors
* AGPL-3.0 - see LICENSE
************************************************/

class JmapContactConverter {

    // -------------------------------------------------------------------------
    // JSContact → SyncContact
    // -------------------------------------------------------------------------

    public static function cardToSyncContact(array $card): SyncContact {
        $c = new SyncContact();

        // Name
        $name = $card['name'] ?? [];
        $c->firstname  = $name['given']      ?? null;
        $c->lastname   = $name['surname']    ?? null;
        $c->middlename = $name['additional'] ?? null;
        $c->title      = $name['prefix']     ?? null;
        $c->suffix     = $name['suffix']     ?? null;
        $full          = $name['full']       ?? null;
        $c->fileas     = $full ?: trim(($c->firstname ?? '') . ' ' . ($c->lastname ?? '')) ?: null;

        // Nicknames
        $nicks = array_values($card['nicknames'] ?? []);
        if ($nicks) $c->nickname = $nicks[0]['name'] ?? null;

        // Emails — work first, then home, then any
        $emails = array_values($card['emails'] ?? []);
        usort($emails, fn($a, $b) =>
            (int)isset($b['contexts']['work']) - (int)isset($a['contexts']['work'])
        );
        foreach ($emails as $i => $e) {
            match ($i) {
                0 => $c->email1address = $e['address'] ?? null,
                1 => $c->email2address = $e['address'] ?? null,
                2 => $c->email3address = $e['address'] ?? null,
                default => null,
            };
        }

        // Phones — classify by context/features
        foreach (array_values($card['phones'] ?? []) as $p) {
            $num      = $p['number']   ?? '';
            $ctx      = $p['contexts'] ?? [];
            $features = $p['features'] ?? [];

            if (isset($features['cell']) || isset($features['mobile'])) {
                $c->mobilephonenumber ??= $num;
            } elseif (isset($features['fax'])) {
                if (isset($ctx['work'])) $c->businessfaxnumber ??= $num;
                else                     $c->homefaxnumber     ??= $num;
            } elseif (isset($features['pager'])) {
                $c->pagernumber ??= $num;
            } elseif (isset($ctx['work'])) {
                if (!isset($c->businessphonenumber)) $c->businessphonenumber  = $num;
                else                                  $c->business2phonenumber ??= $num;
            } elseif (isset($ctx['home'])) {
                if (!isset($c->homephonenumber)) $c->homephonenumber  = $num;
                else                              $c->home2phonenumber ??= $num;
            } else {
                $c->mobilephonenumber ??= $num;
            }
        }

        // Addresses
        foreach (array_values($card['addresses'] ?? []) as $addr) {
            $ctx    = $addr['contexts'] ?? [];
            $parsed = self::parseAddressComponents($addr['components'] ?? []);
            if (isset($ctx['work'])) {
                $c->businessstreet     ??= $parsed['street'];
                $c->businesscity       ??= $parsed['city'];
                $c->businessstate      ??= $parsed['state'];
                $c->businesspostalcode ??= $parsed['postal'];
                $c->businesscountry    ??= $parsed['country'];
            } elseif (isset($ctx['home'])) {
                $c->homestreet     ??= $parsed['street'];
                $c->homecity       ??= $parsed['city'];
                $c->homestate      ??= $parsed['state'];
                $c->homepostalcode ??= $parsed['postal'];
                $c->homecountry    ??= $parsed['country'];
            } else {
                $c->otherstreet     ??= $parsed['street'];
                $c->othercity       ??= $parsed['city'];
                $c->otherstate      ??= $parsed['state'];
                $c->otherpostalcode ??= $parsed['postal'];
                $c->othercountry    ??= $parsed['country'];
            }
        }

        // Organization, job title, department
        $orgs   = array_values($card['organizations'] ?? []);
        $titles = array_values($card['jobTitles']     ?? []);
        $depts  = array_values($card['departments']   ?? []);
        if ($orgs)   $c->companyname = $orgs[0]['name']   ?? null;
        if ($titles) $c->jobtitle    = $titles[0]['name'] ?? null;
        if ($depts)  $c->department  = $depts[0]['name']  ?? null;

        // URLs
        foreach (array_values($card['links'] ?? []) as $link) {
            $c->webpage ??= $link['uri'] ?? null;
        }

        // IM
        $ims = array_values($card['onlineServices'] ?? []);
        foreach ($ims as $i => $im) {
            $val = $im['uri'] ?? $im['service'] ?? null;
            match ($i) {
                0 => $c->imaddress  = $val,
                1 => $c->imaddress2 = $val,
                2 => $c->imaddress3 = $val,
                default => null,
            };
        }

        // Anniversaries
        foreach ($card['anniversaries'] ?? [] as $ann) {
            $date = self::formatAnniversaryDate($ann['date'] ?? null);
            if (!$date) continue;
            if (str_contains(strtolower($ann['type'] ?? 'birth'), 'birth')) {
                $c->birthday    ??= $date;
            } else {
                $c->anniversary ??= $date;
            }
        }

        // Notes → body / asbody
        $notes = array_values($card['notes'] ?? []);
        if ($notes) {
            $text = $notes[0]['note'] ?? '';
            $c->asbody = new SyncBaseBody();
            $c->asbody->type              = SYNC_BODYPREFERENCE_PLAIN;
            $c->asbody->data              = StringStreamWrapper::Open($text);
            $c->asbody->estimatedDataSize = strlen($text);
            $c->asbody->truncated         = 0;
        }

        // Categories
        if (!empty($card['categories'])) {
            $c->categories = array_keys($card['categories']);
        }

        // Relations
        foreach ($card['relations'] ?? [] as $rel) {
            $roles = $rel['relation'] ?? [];
            $name  = $rel['name']    ?? '';
            if (isset($roles['spouse']))    $c->spouse        ??= $name;
            if (isset($roles['manager']))   $c->managername   ??= $name;
            if (isset($roles['assistant'])) $c->assistantname ??= $name;
        }

        return $c;
    }

    // -------------------------------------------------------------------------
    // SyncContact → JSContact
    // -------------------------------------------------------------------------

    public static function syncContactToCard(SyncContact $c, string $addressBookId): array {
        $card = [
            '@type'          => 'Card',
            'version'        => '1.0',
            'addressBookIds' => [$addressBookId => true],
        ];

        // Name
        $nameParts = array_filter([
            'given'      => $c->firstname  ?? null,
            'surname'    => $c->lastname   ?? null,
            'additional' => $c->middlename ?? null,
            'prefix'     => $c->title      ?? null,
            'suffix'     => $c->suffix     ?? null,
            'full'       => $c->fileas     ?? trim(($c->firstname ?? '') . ' ' . ($c->lastname ?? '')) ?: null,
        ]);
        if ($nameParts) $card['name'] = $nameParts;

        if (!empty($c->nickname)) {
            $card['nicknames'] = ['n1' => ['name' => $c->nickname]];
        }

        // Emails
        $emails = [];
        if (!empty($c->email1address)) $emails['e1'] = ['address' => $c->email1address, 'contexts' => ['work' => true]];
        if (!empty($c->email2address)) $emails['e2'] = ['address' => $c->email2address, 'contexts' => ['home' => true]];
        if (!empty($c->email3address)) $emails['e3'] = ['address' => $c->email3address];
        if ($emails) $card['emails'] = $emails;

        // Phones
        $phones = [];
        $pi = 1;
        foreach ([
            [$c->businessphonenumber  ?? null, ['work' => true],  []],
            [$c->business2phonenumber ?? null, ['work' => true],  []],
            [$c->homephonenumber      ?? null, ['home' => true],  []],
            [$c->home2phonenumber     ?? null, ['home' => true],  []],
            [$c->mobilephonenumber    ?? null, [],                ['cell' => true]],
            [$c->businessfaxnumber    ?? null, ['work' => true],  ['fax'  => true]],
            [$c->homefaxnumber        ?? null, ['home' => true],  ['fax'  => true]],
            [$c->pagernumber          ?? null, [],                ['pager'=> true]],
            [$c->carphonenumber       ?? null, ['other' => true], []],
        ] as [$num, $ctx, $feat]) {
            if (!$num) continue;
            $entry = ['number' => $num];
            if ($ctx)  $entry['contexts'] = $ctx;
            if ($feat) $entry['features'] = $feat;
            $phones['p' . $pi++] = $entry;
        }
        if ($phones) $card['phones'] = $phones;

        // Addresses
        $addrs = [];
        if (!empty($c->businessstreet) || !empty($c->businesscity)) {
            $addrs['a1'] = self::buildAddress($c->businessstreet, $c->businesscity, $c->businessstate, $c->businesspostalcode, $c->businesscountry, 'work');
        }
        if (!empty($c->homestreet) || !empty($c->homecity)) {
            $addrs['a2'] = self::buildAddress($c->homestreet, $c->homecity, $c->homestate, $c->homepostalcode, $c->homecountry, 'home');
        }
        if (!empty($c->otherstreet) || !empty($c->othercity)) {
            $addrs['a3'] = self::buildAddress($c->otherstreet, $c->othercity, $c->otherstate, $c->otherpostalcode, $c->othercountry, null);
        }
        if ($addrs) $card['addresses'] = $addrs;

        // Organization / job / department
        if (!empty($c->companyname)) $card['organizations'] = ['o1' => ['name' => $c->companyname]];
        if (!empty($c->jobtitle))    $card['jobTitles']     = ['jt1' => ['name' => $c->jobtitle]];
        if (!empty($c->department))  $card['departments']   = ['d1'  => ['name' => $c->department]];

        // URL
        if (!empty($c->webpage)) {
            $card['links'] = ['l1' => ['@type' => 'Link', 'uri' => $c->webpage]];
        }

        // IM
        $ims = [];
        if (!empty($c->imaddress))  $ims['im1'] = ['uri' => $c->imaddress];
        if (!empty($c->imaddress2)) $ims['im2'] = ['uri' => $c->imaddress2];
        if (!empty($c->imaddress3)) $ims['im3'] = ['uri' => $c->imaddress3];
        if ($ims) $card['onlineServices'] = $ims;

        // Anniversaries
        $anns = [];
        if (!empty($c->birthday))    $anns['b1'] = ['type' => 'birth',       'date' => self::toPartialDate($c->birthday)];
        if (!empty($c->anniversary)) $anns['a1'] = ['type' => 'anniversary', 'date' => self::toPartialDate($c->anniversary)];
        if ($anns) $card['anniversaries'] = $anns;

        // Notes
        if (!empty($c->body)) {
            $card['notes'] = ['n1' => ['note' => $c->body]];
        }

        // Categories
        if (!empty($c->categories)) {
            $cats = [];
            foreach ($c->categories as $cat) $cats[$cat] = true;
            $card['categories'] = $cats;
        }

        // Relations
        $rels = [];
        if (!empty($c->spouse))        $rels['r1'] = ['name' => $c->spouse,        'relation' => ['spouse'    => true]];
        if (!empty($c->managername))   $rels['r2'] = ['name' => $c->managername,   'relation' => ['manager'   => true]];
        if (!empty($c->assistantname)) $rels['r3'] = ['name' => $c->assistantname, 'relation' => ['assistant' => true]];
        if ($rels) $card['relations'] = $rels;

        return $card;
    }

    // -------------------------------------------------------------------------
    // Build a mod hash for change detection
    // -------------------------------------------------------------------------

    public static function cardModHash(array $card): string {
        $key = ($card['name']['full'] ?? '') .
               ($card['emails']['e1']['address'] ?? array_key_first($card['emails'] ?? []) ?? '') .
               ($card['phones']['p1']['number']  ?? '') .
               ($card['updated'] ?? '');
        return sprintf('%08x', crc32($key));
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private static function parseAddressComponents(array $components): array {
        $r = ['street' => '', 'city' => '', 'state' => '', 'postal' => '', 'country' => ''];
        foreach ($components as $comp) {
            $v = $comp['value'] ?? '';
            match ($comp['kind'] ?? '') {
                'name', 'number', 'street' => $r['street']  .= ($r['street'] ? ', ' : '') . $v,
                'locality'                 => $r['city']     = $v,
                'region'                   => $r['state']    = $v,
                'postcode', 'postalCode'   => $r['postal']   = $v,
                'country', 'countryName'   => $r['country']  = $v,
                default                    => null,
            };
        }
        return $r;
    }

    private static function buildAddress(?string $street, ?string $city, ?string $state, ?string $postal, ?string $country, ?string $context): array {
        $components = [];
        if ($street)  $components[] = ['kind' => 'street',   'value' => $street];
        if ($city)    $components[] = ['kind' => 'locality', 'value' => $city];
        if ($state)   $components[] = ['kind' => 'region',   'value' => $state];
        if ($postal)  $components[] = ['kind' => 'postcode', 'value' => $postal];
        if ($country) $components[] = ['kind' => 'country',  'value' => $country];

        $addr = ['@type' => 'Address', 'components' => $components];
        if ($context) $addr['contexts'] = [$context => true];
        return $addr;
    }

    private static function formatAnniversaryDate(mixed $date): ?string {
        if (!$date) return null;
        if (is_string($date)) return $date;
        if (is_array($date)) {
            $y = $date['year']  ?? 0;
            $m = $date['month'] ?? 1;
            $d = $date['day']   ?? 1;
            return sprintf('%04d-%02d-%02d', $y, $m, $d);
        }
        return null;
    }

    private static function toPartialDate(string $date): array {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m)) {
            return ['@type' => 'PartialDate', 'year' => (int)$m[1], 'month' => (int)$m[2], 'day' => (int)$m[3]];
        }
        return ['@type' => 'PartialDate', 'year' => 0, 'month' => 1, 'day' => 1];
    }
}
