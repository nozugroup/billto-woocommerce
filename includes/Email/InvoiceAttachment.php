<?php

declare(strict_types=1);

namespace BillTo\WooCommerce\Email;

use BillTo\WooCommerce\Support\Meta;
use BillTo\WooCommerce\Support\Options;
use BillTo\WooCommerce\Support\PdfStorage;
use WC_Order;

/**
 * "PDF as attachment of a WooCommerce e-mail" delivery mode.
 *
 * Status e-mails are sent synchronously during the status change, i.e. before the background job
 * has issued the invoice. So once the invoice exists the plugin triggers the standard WooCommerce
 * "Customer invoice" e-mail (once per order) and attaches the PDF to it - and to any later
 * processing/completed e-mail that may still be sent.
 */
final class InvoiceAttachment
{
    private const EMAIL_IDS = ['customer_invoice', 'customer_completed_order', 'customer_processing_order'];

    public function __construct(private Options $options, private PdfStorage $pdfStorage) {}

    public function register(): void
    {
        if (! $this->options->attachPdfToWcEmail()) {
            return;
        }

        add_filter('woocommerce_email_attachments', [$this, 'attach'], 10, 3);
        add_action('billto_wc_invoice_issued', [$this, 'sendInvoiceEmail'], 10, 1);
    }

    /**
     * @param  array<int, string>  $attachments
     * @param  mixed  $object
     * @return array<int, string>
     */
    public function attach(array $attachments, string $emailId, $object): array
    {
        if (! in_array($emailId, self::EMAIL_IDS, true) || ! $object instanceof WC_Order) {
            return $attachments;
        }

        $path = (string) $object->get_meta(Meta::INVOICE_PDF_PATH);

        if ($this->pdfStorage->exists($path)) {
            $attachments[] = $path;
        }

        return $attachments;
    }

    public function sendInvoiceEmail(WC_Order $order): void
    {
        if ((string) $order->get_meta(Meta::INVOICE_EMAILED) === 'yes') {
            return;
        }

        if (! $this->pdfStorage->exists((string) $order->get_meta(Meta::INVOICE_PDF_PATH))) {
            return; // nothing to attach - the customer can still download from My account
        }

        $mailer = WC()->mailer();
        $email = $mailer->emails['WC_Email_Customer_Invoice'] ?? null;

        if (! $email instanceof \WC_Email_Customer_Invoice) {
            return;
        }

        $email->trigger($order->get_id(), $order);

        $order->update_meta_data(Meta::INVOICE_EMAILED, 'yes');
        $order->save_meta_data();
        $order->add_order_note(__('BillTo: faktura wysłana jako załącznik e-maila WooCommerce.', 'billto-woocommerce'));
    }
}
