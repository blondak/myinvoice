<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Action\Invoice;

use MyInvoice\Action\Invoice\ExportSelectedPdfAction;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Export\MergedInvoicePdfExporter;
use MyInvoice\Service\IpMatcher;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

#[AllowMockObjectsWithoutExpectations]
final class ExportSelectedPdfActionTest extends TestCase
{
    public function testExportsOwnedInvoicesForReadonlyUserAndPreservesOrder(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'selected-pdf-test-');
        self::assertNotFalse($path);
        file_put_contents($path, '%PDF-selected');

        $invoices = $this->createStub(InvoiceRepository::class);
        $invoices->method('find')->willReturnCallback(
            static fn (int $id): array => ['id' => $id, 'supplier_id' => 7],
        );
        $exporter = $this->createMock(MergedInvoicePdfExporter::class);
        $exporter->expects(self::once())
            ->method('export')
            ->with([12, 11], ['id' => 7], 3, true)
            ->willReturn(['path' => $path, 'signed' => true]);

        try {
            $response = ($this->action($invoices, $exporter))(
                $this->request(['ids' => '12,11,12', 'sign_pdf' => '1']),
                (new ResponseFactory())->createResponse(),
            );

            self::assertSame(200, $response->getStatusCode());
            self::assertSame('application/pdf', $response->getHeaderLine('Content-Type'));
            self::assertStringContainsString('attachment', $response->getHeaderLine('Content-Disposition'));
            self::assertSame('%PDF-selected', (string) $response->getBody());
        } finally {
            @unlink($path);
        }
    }

    public function testRejectsMissingAndMalformedIds(): void
    {
        $invoices = $this->createStub(InvoiceRepository::class);
        $exporter = $this->createMock(MergedInvoicePdfExporter::class);
        $exporter->expects(self::never())->method('export');
        $action = $this->action($invoices, $exporter);

        $missing = $action($this->request([]), (new ResponseFactory())->createResponse());
        $malformed = $action($this->request(['ids' => '12,nope']), (new ResponseFactory())->createResponse());

        self::assertSame(400, $missing->getStatusCode());
        self::assertSame(400, $malformed->getStatusCode());
    }

    public function testRejectsMoreThanOneHundredInvoices(): void
    {
        $invoices = $this->createStub(InvoiceRepository::class);
        $exporter = $this->createMock(MergedInvoicePdfExporter::class);
        $exporter->expects(self::never())->method('export');
        $ids = implode(',', range(1, 101));

        $response = ($this->action($invoices, $exporter))(
            $this->request(['ids' => $ids]),
            (new ResponseFactory())->createResponse(),
        );

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('too_many', (string) $response->getBody());
    }

    public function testReturns404BeforeExportForForeignInvoice(): void
    {
        $invoices = $this->createStub(InvoiceRepository::class);
        $invoices->method('find')->willReturn(['id' => 12, 'supplier_id' => 99]);
        $exporter = $this->createMock(MergedInvoicePdfExporter::class);
        $exporter->expects(self::never())->method('export');

        $response = ($this->action($invoices, $exporter))(
            $this->request(['ids' => '12']),
            (new ResponseFactory())->createResponse(),
        );

        self::assertSame(404, $response->getStatusCode());
    }

    public function testReportsUnavailableSignature(): void
    {
        $invoices = $this->createStub(InvoiceRepository::class);
        $invoices->method('find')->willReturn(['id' => 12, 'supplier_id' => 7]);
        $exporter = $this->createMock(MergedInvoicePdfExporter::class);
        $exporter->method('export')->willThrowException(new \DomainException('Podpis není dostupný.'));

        $response = ($this->action($invoices, $exporter))(
            $this->request(['ids' => '12', 'sign_pdf' => '1']),
            (new ResponseFactory())->createResponse(),
        );

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('signature_unavailable', (string) $response->getBody());
    }

    private function action(
        InvoiceRepository $invoices,
        MergedInvoicePdfExporter $exporter,
    ): ExportSelectedPdfAction {
        $logger = $this->createStub(ActivityLogger::class);
        $ip = $this->createStub(IpMatcher::class);
        $ip->method('clientIpFromRequest')->willReturn('127.0.0.1');
        return new ExportSelectedPdfAction($invoices, $exporter, $logger, $ip);
    }

    /** @param array<string,string> $query */
    private function request(array $query): \Psr\Http\Message\ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest('GET', '/api/invoices/export.pdf')
            ->withQueryParams($query)
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, 7)
            ->withAttribute(AuthMiddleware::ATTR_USER, [
                'id' => 3,
                'role' => 'readonly',
            ]);
    }
}
