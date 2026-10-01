<?php

namespace App\Domains\Invoicing\Exceptions;

use RuntimeException;

/**
 * Dilempar saat transisi status invoice tidak diizinkan,
 * misalnya mengirim ulang invoice yang sudah lunas/dibatalkan.
 */
class InvalidInvoiceTransition extends RuntimeException {}
