<?php

declare(strict_types=1);

namespace MyInvoice\Service\Mail;

/**
 * E-mail byl transportem úspěšně DORUČEN, ale následné uložení kopie do IMAP
 * složky odeslané pošty selhalo a profil má `imap_on_failure = fail_send`.
 *
 * Vlastní typ (potomek RuntimeException) záměrně odlišuje „doručeno, jen se
 * neuložila kopie" od „nepodařilo se odeslat". Caller / fronta NESMÍ na tuto
 * výjimku odeslání retryovat — jinak by příjemce dostal e-mail dvakrát.
 */
final class MailDeliveredArchiveException extends \RuntimeException
{
}
