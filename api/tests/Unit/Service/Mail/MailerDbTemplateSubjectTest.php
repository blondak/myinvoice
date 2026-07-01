<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Mail;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\EmailProfileRepository;
use MyInvoice\Repository\EmailTemplateRepository;
use MyInvoice\Service\Mail\Mailer;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\SendmailTransport;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mailer\Transport\Smtp\Stream\SocketStream;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;

final class MailerDbTemplateSubjectTest extends TestCase
{
    public function testDbTemplateSubjectIsRenderedWithTemplateVariables(): void
    {
        $templates = $this->createStub(EmailTemplateRepository::class);
        $templates->method('find')->willReturn([
            'id' => 1,
            'code' => 'invoice_send',
            'locale' => 'cs',
            'subject' => 'Faktura {{ invoice.varsymbol }}',
            'body_html' => '<p>Faktura {{ invoice.varsymbol }}</p>',
            'body_text' => 'Faktura {{ invoice.varsymbol }}',
            'updated_at' => '2026-06-02 12:00:00',
        ]);

        $mailer = new Mailer(
            new Config([
                'smtp' => [
                    'from_email' => 'noreply@example.test',
                    'from_name' => 'MyInvoice',
                    'dkim' => ['enabled' => false],
                ],
            ]),
            $this->createStub(LoggerInterface::class),
            $this->createStub(Connection::class),
            $templates,
        );

        $transport = new CapturingTransport();
        $transportProperty = new \ReflectionProperty(Mailer::class, 'transport');
        $transportProperty->setValue($mailer, $transport);

        $mailer->sendTemplate('invoice_send', 'cs', ['client@example.test'], [
            'invoice' => ['varsymbol' => '2605001'],
            'supplier' => [
                'id' => 1,
                'company_name' => 'Dodavatel s.r.o.',
                'display_name' => 'Dodavatel',
                'email_branding_enabled' => false,
            ],
        ]);

        self::assertInstanceOf(Email::class, $transport->message);
        self::assertSame('Faktura 2605001', $transport->message->getSubject());
        self::assertStringContainsString('Faktura 2605001', $transport->message->getTextBody() ?? '');
        self::assertStringContainsString('Faktura 2605001', $transport->message->getHtmlBody() ?? '');
    }

    public function testEmailProfileWithDisabledReplyToDoesNotUseFallback(): void
    {
        $templates = $this->createStub(EmailTemplateRepository::class);
        $templates->method('find')->willReturn([
            'id' => 1,
            'code' => 'invoice_send',
            'locale' => 'cs',
            'subject' => 'Faktura',
            'body_html' => '<p>Faktura</p>',
            'body_text' => 'Faktura',
            'updated_at' => '2026-06-02 12:00:00',
        ]);

        $profiles = $this->createMock(EmailProfileRepository::class);
        $profiles->expects($this->once())
            ->method('defaultProfile')
            ->with(1)
            ->willReturn([
                'id' => 10,
                'supplier_id' => 1,
                'name' => 'Profile',
                'code' => 'profile',
                'from_email' => 'profile@example.test',
                'from_name' => 'Profile sender',
                'reply_to_email' => null,
                'reply_to_name' => null,
                'reply_to_enabled' => false,
                'signing_profile_id' => null,
                'dkim_domain' => null,
                'dkim_selector' => null,
                'dkim_enabled' => false,
                'is_default' => true,
                'is_active' => true,
            ]);

        $mailer = new Mailer(
            new Config([
                'smtp' => [
                    'from_email' => 'noreply@example.test',
                    'from_name' => 'MyInvoice',
                    'reply_to_email' => 'global-reply@example.test',
                    'reply_to_name' => 'Global reply',
                    'dkim' => [
                        'enabled' => true,
                        'private_key_path' => __FILE__,
                        'domain' => 'global.example.test',
                        'selector' => 'global',
                        'passphrase' => '',
                    ],
                ],
            ]),
            $this->createStub(LoggerInterface::class),
            $this->createStub(Connection::class),
            $templates,
            null,
            $profiles,
        );

        $transport = new CapturingTransport();
        $transportProperty = new \ReflectionProperty(Mailer::class, 'transport');
        $transportProperty->setValue($mailer, $transport);

        $mailer->sendTemplate('invoice_send', 'cs', ['client@example.test'], [
            'supplier' => [
                'id' => 1,
                'company_name' => 'Dodavatel s.r.o.',
                'display_name' => 'Dodavatel',
                'email' => 'supplier@example.test',
                'email_branding_enabled' => false,
            ],
        ]);

        self::assertInstanceOf(Email::class, $transport->message);
        self::assertSame([], $transport->message->getReplyTo());
        self::assertSame('profile@example.test', $transport->message->getFrom()[0]->getAddress());
        self::assertFalse($transport->message->getHeaders()->has('DKIM-Signature'));
    }

    public function testExplicitEmailProfileOverrideIsUsedForTestSend(): void
    {
        $templates = $this->createStub(EmailTemplateRepository::class);
        $templates->method('find')->willReturn([
            'id' => 1,
            'code' => 'email_profile_test',
            'locale' => 'cs',
            'subject' => 'Test profilu',
            'body_html' => '<p>Test {{ profile.name }}</p>',
            'body_text' => 'Test {{ profile.name }}',
            'updated_at' => '2026-06-02 12:00:00',
        ]);

        $profiles = $this->createMock(EmailProfileRepository::class);
        $profiles->expects($this->never())->method('defaultProfile');

        $mailer = new Mailer(
            new Config([
                'smtp' => [
                    'from_email' => 'global@example.test',
                    'from_name' => 'Global',
                    'reply_to_email' => 'global-reply@example.test',
                    'reply_to_name' => 'Global reply',
                    'dkim' => ['enabled' => false],
                ],
            ]),
            $this->createStub(LoggerInterface::class),
            $this->createStub(Connection::class),
            $templates,
            null,
            $profiles,
        );

        $transport = new CapturingTransport();
        $transportProperty = new \ReflectionProperty(Mailer::class, 'transport');
        $transportProperty->setValue($mailer, $transport);

        $mailer->sendTemplate(
            'email_profile_test',
            'cs',
            ['admin@example.test'],
            [
                'supplier' => [
                    'id' => 1,
                    'company_name' => 'Dodavatel s.r.o.',
                    'display_name' => 'Dodavatel',
                    'email' => 'supplier@example.test',
                    'email_branding_enabled' => false,
                ],
                'profile' => ['name' => 'Test'],
            ],
            null,
            [],
            [],
            [],
            null,
            [
                'id' => 20,
                'supplier_id' => 1,
                'name' => 'Override',
                'code' => 'override',
                'from_email' => 'override@example.test',
                'from_name' => 'Override sender',
                'reply_to_email' => null,
                'reply_to_name' => null,
                'reply_to_enabled' => false,
                'signing_profile_id' => null,
                'dkim_domain' => null,
                'dkim_selector' => null,
                'dkim_enabled' => false,
                'transport_type' => 'global',
                'is_default' => false,
                'is_active' => false,
            ],
        );

        self::assertInstanceOf(Email::class, $transport->message);
        self::assertSame('override@example.test', $transport->message->getFrom()[0]->getAddress());
        self::assertSame('Override sender', $transport->message->getFrom()[0]->getName());
        self::assertSame([], $transport->message->getReplyTo());
    }

    public function testGlobalEmailProfileTransportUsesConfiguredSmtpTransport(): void
    {
        $mailer = new Mailer(
            new Config([
                'smtp' => [
                    'host' => 'cfg-smtp.example.test',
                    'port' => 2525,
                    'encryption' => 'none',
                    'auth_enabled' => true,
                    'auth_type' => 'LOGIN',
                    'user' => 'cfg-user@example.test',
                    'pass' => 'cfg-secret',
                    'verify_peer' => true,
                    'verify_peer_name' => true,
                    'allow_self_signed' => false,
                    'timeout' => 42,
                ],
            ]),
            $this->createStub(LoggerInterface::class),
            $this->createStub(Connection::class),
            $this->createStub(EmailTemplateRepository::class),
        );

        $method = new \ReflectionMethod(Mailer::class, 'transport');
        $transport = $method->invoke($mailer, [
            'transport_type' => 'global',
            'smtp_host' => 'ignored-profile-smtp.example.test',
            'smtp_port' => 465,
            'smtp_auth_enabled' => true,
            'smtp_auth_type' => 'PLAIN',
            'smtp_username' => 'ignored-profile-user',
            'smtp_password' => 'ignored-profile-secret',
            'smtp_encryption' => 'ssl',
            'smtp_timeout' => 1,
        ]);

        self::assertInstanceOf(EsmtpTransport::class, $transport);
        self::assertSame('cfg-user@example.test', $transport->getUsername());
        self::assertSame('cfg-secret', $transport->getPassword());

        $stream = $transport->getStream();
        self::assertInstanceOf(SocketStream::class, $stream);
        self::assertSame('cfg-smtp.example.test', $stream->getHost());
        self::assertSame(2525, $stream->getPort());
        self::assertFalse($stream->isTLS());
        self::assertSame(42.0, $stream->getTimeout());
    }

    public function testBuildsProfileSmtpAndSendmailTransport(): void
    {
        $mailer = new Mailer(
            new Config([
                'smtp' => [
                    'host' => 'global.example.test',
                    'port' => 587,
                    'encryption' => 'tls',
                    'auth_enabled' => false,
                    'verify_peer' => true,
                    'verify_peer_name' => true,
                    'allow_self_signed' => false,
                    'timeout' => 30,
                ],
            ]),
            $this->createStub(LoggerInterface::class),
            $this->createStub(Connection::class),
            $this->createStub(EmailTemplateRepository::class),
        );

        $method = new \ReflectionMethod(Mailer::class, 'buildTransport');

        $smtp = $method->invoke($mailer, [
            'transport_type' => 'smtp',
            'smtp_host' => 'smtp.example.test',
            'smtp_port' => 465,
            'smtp_auth_enabled' => true,
            'smtp_auth_type' => 'LOGIN',
            'smtp_username' => 'user@example.test',
            'smtp_password' => 'p@ss word',
            'smtp_encryption' => 'ssl',
            'smtp_verify_peer' => false,
            'smtp_verify_peer_name' => false,
            'smtp_allow_self_signed' => true,
            'smtp_timeout' => 45,
        ]);
        self::assertInstanceOf(EsmtpTransport::class, $smtp);
        self::assertSame('user@example.test', $smtp->getUsername());
        self::assertSame('p@ss word', $smtp->getPassword());
        self::assertFalse($smtp->isTlsRequired());

        $stream = $smtp->getStream();
        self::assertInstanceOf(SocketStream::class, $stream);
        self::assertSame('smtp.example.test', $stream->getHost());
        self::assertSame(465, $stream->getPort());
        self::assertTrue($stream->isTLS());
        self::assertSame(45.0, $stream->getTimeout());
        self::assertSame([
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true,
            ],
        ], $stream->getStreamOptions());

        $authenticatorsProperty = new \ReflectionProperty(EsmtpTransport::class, 'authenticators');
        $authenticators = $authenticatorsProperty->getValue($smtp);
        self::assertCount(1, $authenticators);
        self::assertSame('LOGIN', $authenticators[0]->getAuthKeyword());

        $sendmail = $method->invoke($mailer, [
            'transport_type' => 'sendmail',
            'sendmail_command' => '/usr/sbin/sendmail -bs',
        ]);
        self::assertInstanceOf(SendmailTransport::class, $sendmail);
        $commandProperty = new \ReflectionProperty(SendmailTransport::class, 'command');
        self::assertSame('/usr/sbin/sendmail -bs', $commandProperty->getValue($sendmail));
    }
}

final class CapturingTransport implements TransportInterface
{
    public ?RawMessage $message = null;

    public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
    {
        $this->message = $message;
        return null;
    }

    public function __toString(): string
    {
        return 'capturing://test';
    }
}
