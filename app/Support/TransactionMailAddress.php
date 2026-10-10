<?php

/*
 * TransactionMailAddress.php
 *
 * This file is part of Firefly III (https://github.com/firefly-iii).
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 */

declare(strict_types=1);

namespace FireflyIII\Support;

/**
 * The mail address that files mail on a transaction: you+t4821-k7f3q9x2ab@gmail.com.
 *
 * The signature is a truncated HMAC-SHA256 of the transaction group id, in lower-case base32. It
 * must be computed exactly as upfirefly computes it (TransactionAddresses.cs), since upfirefly is
 * what checks it when the mail arrives.
 */
class TransactionMailAddress
{
    // Separates this use of the key from any other. Must match upfirefly.
    private const string PURPOSE = "upfirefly transaction mail v1\n";

    /**
     * The address for a transaction group, or null when no key or mailbox is configured.
     */
    public static function forGroup(int $groupId): ?string
    {
        $key     = (string) config('transaction_mail.key');
        $mailbox = (string) config('transaction_mail.address');
        $length  = (int) config('transaction_mail.signature_length');

        // upfirefly refuses a shorter key, so an address signed with one would never work.
        if (strlen($key) < 32 || !str_contains($mailbox, '@') || $length < 8 || $length > 32) {
            return null;
        }

        $at        = (int) strrpos($mailbox, '@');
        $mac       = hash_hmac('sha256', self::PURPOSE.$groupId, $key, true);
        $signature = substr(self::base32($mac), 0, $length);

        return sprintf('%s+t%d-%s%s', substr($mailbox, 0, $at), $groupId, $signature, substr($mailbox, $at));
    }

    /**
     * RFC 4648 base32, lower case, unpadded.
     */
    private static function base32(string $bytes): string
    {
        $alphabet = 'abcdefghijklmnopqrstuvwxyz234567';
        $text     = '';
        $buffer   = 0;
        $bits     = 0;

        foreach (str_split($bytes) as $byte) {
            $buffer = (($buffer << 8) | ord($byte)) & 0xFFFF;
            $bits += 8;

            while ($bits >= 5) {
                $text .= $alphabet[($buffer >> ($bits - 5)) & 31];
                $bits -= 5;
            }
        }

        if ($bits > 0) {
            $text .= $alphabet[($buffer << (5 - $bits)) & 31];
        }

        return $text;
    }
}
