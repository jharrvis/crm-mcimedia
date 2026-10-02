<?php

namespace App\Domains\Invoicing\Exceptions;

use RuntimeException;

/**
 * Gagal memecah invoice menjadi termin (F4-10).
 *
 * Berbeda dari InvalidInvoiceTransition (yang soal transisi status),
 * exception ini soal validitas rencana termin: persentase tidak genap 100%,
 * termin sudah ada sebelumnya, atau nilai kontrak masih nol.
 */
class InvalidTerminSplit extends RuntimeException {}
