<?php

/*
 * transaction_mail.php
 *
 * Per-transaction mail addresses, made by upfirefly. Anything sent to one is filed on its
 * transaction as a PDF. Firefly III only shows the address; upfirefly receives the mail.
 *
 * Both must be given the same key and mailbox, or the address shown here will be one that
 * upfirefly refuses.
 */

declare(strict_types=1);

return [
    // The secret that signs each address. Unset hides the address altogether.
    'key'              => trim((string) env('TRANSACTION_MAIL_KEY', '')),

    // The mailbox the addresses are plus-addresses of, e.g. you@gmail.com.
    'address'          => trim((string) env('TRANSACTION_MAIL_ADDRESS', '')),

    // Base32 characters of signature in each address. Must match upfirefly's signatureLength.
    'signature_length' => (int) env('TRANSACTION_MAIL_SIGNATURE_LENGTH', 10),
];
