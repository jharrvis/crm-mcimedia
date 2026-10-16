<?php

namespace App\Domains\Quotation\Exceptions;

use RuntimeException;

/**
 * Dilempar saat transisi status quotation tidak diizinkan, misalnya
 * menerima penawaran yang sudah ditolak/dikonversi (status terminal).
 */
class InvalidQuotationTransition extends RuntimeException {}
