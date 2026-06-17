<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Import;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Repository\PurchaseInvoiceRepository;
use MyInvoice\Service\Import\PurchaseInvoicePdfArchiver;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

/**
 * Sdílená archivace PDF přijatých faktur — společná pro dropzone/AI, dávkový import
 * (issue #149) i inbox scan. Ověřuje zápis souboru na disk + metadata na fakturu,
 * odmítnutí ne-PDF obsahu a override pdf_hash (ISDOCX inbox dedup).
 */
#[AllowMockObjectsWithoutExpectations]
final class PurchaseInvoicePdfArchiverTest extends TestCase
{
    private string $archiveRoot;

    protected function setUp(): void
    {
        $this->archiveRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pi-archiver-test-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        if (is_dir($this->archiveRoot)) {
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->archiveRoot, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($it as $f) {
                $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
            }
            @rmdir($this->archiveRoot);
        }
    }

    private function makeConfig(): Config
    {
        $config = $this->createMock(Config::class);
        $config->method('get')->willReturnCallback(
            fn (string $key, $default = null) => $key === 'purchase_invoice.archive_storage' ? $this->archiveRoot : $default,
        );
        return $config;
    }

    public function testArchiveBytesWritesFileAndMetadata(): void
    {
        $pdf = "%PDF-1.4\nhello invoice\n%%EOF";
        $contentSha = hash('sha256', $pdf);
        $expectedDisk = substr($contentSha, 0, 16) . '.pdf';

        $repo = $this->createMock(PurchaseInvoiceRepository::class);
        $repo->expects(self::once())->method('setPdfMetadata')->with(
            42,
            7,
            'supplier-7/' . $expectedDisk,
            $contentSha,
            strlen($pdf),
            'faktura.pdf',
        );

        (new PurchaseInvoicePdfArchiver($this->makeConfig(), $repo))
            ->archiveBytes(42, 7, $pdf, 'faktura.pdf');

        $onDisk = $this->archiveRoot . '/supplier-7/' . $expectedDisk;
        self::assertFileExists($onDisk);
        self::assertSame($pdf, file_get_contents($onDisk));
    }

    public function testArchiveBytesRejectsNonPdf(): void
    {
        $repo = $this->createMock(PurchaseInvoiceRepository::class);
        $repo->expects(self::never())->method('setPdfMetadata');

        (new PurchaseInvoicePdfArchiver($this->makeConfig(), $repo))
            ->archiveBytes(42, 7, 'PK\x03\x04 not a pdf', 'x.pdf');

        self::assertDirectoryDoesNotExist($this->archiveRoot . '/supplier-7');
    }

    public function testArchiveBytesFallsBackToDefaultName(): void
    {
        $repo = $this->createMock(PurchaseInvoiceRepository::class);
        $repo->expects(self::once())->method('setPdfMetadata')->with(
            1, 1, self::anything(), self::anything(), self::anything(), 'imported.pdf',
        );

        (new PurchaseInvoicePdfArchiver($this->makeConfig(), $repo))
            ->archiveBytes(1, 1, "%PDF-1.4\nx", null);
    }

    public function testHashKeyOverridesPdfHashButNotDiskName(): void
    {
        // ISDOCX inbox scan: na disk jdou vnitřní PDF bajty (disk name = jejich hash),
        // ale pdf_hash = hash celého .isdocx (= scanner dedup klíč).
        $pdf = "%PDF-1.4\ninner\n%%EOF";
        $contentSha = hash('sha256', $pdf);
        $isdocxSha = hash('sha256', 'whole-isdocx-bytes');
        $expectedDisk = substr($contentSha, 0, 16) . '.pdf';

        $repo = $this->createMock(PurchaseInvoiceRepository::class);
        $repo->expects(self::once())->method('setPdfMetadata')->with(
            5,
            3,
            'supplier-3/' . $expectedDisk,
            $isdocxSha,
            strlen($pdf),
            'doc.isdocx',
        );

        (new PurchaseInvoicePdfArchiver($this->makeConfig(), $repo))
            ->archiveBytes(5, 3, $pdf, 'doc.isdocx', $isdocxSha);

        self::assertFileExists($this->archiveRoot . '/supplier-3/' . $expectedDisk);
    }

    public function testArchiveFileCopiesFromSourcePath(): void
    {
        $src = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pi-archiver-src-' . bin2hex(random_bytes(4)) . '.pdf';
        file_put_contents($src, "%PDF-1.5\nfrom path\n%%EOF");
        $fileSha = (string) hash_file('sha256', $src);
        $expectedDisk = substr($fileSha, 0, 16) . '.pdf';

        try {
            $repo = $this->createMock(PurchaseInvoiceRepository::class);
            $repo->expects(self::once())->method('setPdfMetadata')->with(
                9,
                2,
                'supplier-2/' . $expectedDisk,
                $fileSha,
                filesize($src),
                basename($src),
            );

            (new PurchaseInvoicePdfArchiver($this->makeConfig(), $repo))
                ->archiveFile(9, 2, $src, null, $fileSha, (int) filesize($src));

            self::assertFileExists($this->archiveRoot . '/supplier-2/' . $expectedDisk);
        } finally {
            @unlink($src);
        }
    }
}
