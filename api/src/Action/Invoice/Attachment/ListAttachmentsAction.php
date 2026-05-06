<?php

declare(strict_types=1);

namespace MyInvoice\Action\Invoice\Attachment;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Repository\InvoiceAttachmentRepository;
use MyInvoice\Repository\InvoiceRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class ListAttachmentsAction
{
    public function __construct(
        private readonly InvoiceRepository $invoices,
        private readonly InvoiceAttachmentRepository $attachments,
    ) {}

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);
        $invoice = $this->invoices->find($id);
        if (!SupplierGuard::owns($request, $invoice)) {
            return Json::error($response, 'not_found', 'Faktura nenalezena.', 404);
        }
        return Json::ok($response, ['items' => $this->attachments->listForInvoice($id)]);
    }
}
