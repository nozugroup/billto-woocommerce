<?php

declare(strict_types=1);

namespace BillTo\WooCommerce\Support;

/**
 * Order meta keys written by the plugin.
 */
final class Meta
{
    /** Customer asked for an invoice (checkout checkbox): yes|no. */
    public const WANTS_INVOICE = '_billto_wants_invoice';

    /** Tax number entered at checkout (classic checkout). */
    public const NIP = '_billing_nip';

    /** Blocks checkout stores additional fields under this key. */
    public const NIP_BLOCKS = '_wc_other/billto/nip';

    public const WANTS_INVOICE_BLOCKS = '_wc_other/billto/wants-invoice';

    public const ORDER_ID = '_billto_order_id';

    public const ORDER_NUMBER = '_billto_order_number';

    /** JSON map of WooCommerce item id => BillTo line_number, used to build corrections. */
    public const LINE_MAP = '_billto_line_map';

    public const INVOICE_ID = '_billto_invoice_id';

    public const INVOICE_NUMBER = '_billto_invoice_number';

    public const INVOICE_PUBLIC_URL = '_billto_invoice_public_url';

    public const INVOICE_PDF_PATH = '_billto_invoice_pdf';

    public const INVOICE_EMAILED = '_billto_invoice_emailed';

    public const KSEF_STATUS = '_billto_ksef_status';

    public const KSEF_NUMBER = '_billto_ksef_number';

    public const LAST_ERROR = '_billto_last_error';

    /** Core warning codes from the last sync (JSON list): consistent invoice, but not the expected scenario. */
    public const WARNINGS = '_billto_warnings';

    /** md5 of the last order payload sent to BillTo; an unchanged payload skips the update call. */
    public const PAYLOAD_HASH = '_billto_payload_hash';

    /** Refund id => correction invoice number, JSON. */
    public const CORRECTIONS = '_billto_corrections';

    /** Whether the BillTo invoice has a payment recorded: yes|no (unpaid gateways). */
    public const INVOICE_PAID = '_billto_invoice_paid';

    /** VIES result for the buyer's EU VAT id, JSON {status, name, checked_at}. */
    public const VIES = '_billto_vies';
}
