<?php

namespace Modules\Purchase\Exceptions;

use DomainException;

/**
 * Pelanggaran ATURAN BISNIS pembelian (bukan kesalahan format input):
 * status dokumen tidak mengizinkan aksi, penerimaan melebihi sisa PO, faktur masih terikat, dst.
 * Dipetakan ke respons 422/409 oleh exception handler (Langkah 4).
 */
class PurchaseRuleException extends DomainException
{
}