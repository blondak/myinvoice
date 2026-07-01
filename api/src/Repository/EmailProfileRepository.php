<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Auth\SecretEncryption;
use PDO;

/**
 * Per-supplier odesílací e-mailové profily.
 *
 * Profil řeší hlavičky From/Reply-To, volitelné napojení na DKIM/S/MIME
 * metadata a volitelný odchozí transport.
 */
final class EmailProfileRepository
{
    private const TRANSPORT_TYPES = ['global', 'smtp', 'sendmail'];
    private const SMTP_ENCRYPTIONS = ['none', 'tls', 'ssl'];
    private const SMTP_AUTH_TYPES = ['LOGIN', 'PLAIN', 'CRAM-MD5', 'XOAUTH2'];

    public function __construct(
        private readonly Connection $db,
        private readonly SecretEncryption $secrets,
    ) {}

    /**
     * @return list<array<string,mixed>>
     */
    public function listProfiles(int $supplierId, bool $includeDeleted = false, bool $includeSecret = false): array
    {
        $sql = 'SELECT ep.*, sp.name AS signing_profile_name, sp.code AS signing_profile_code
                  FROM email_profiles ep
             LEFT JOIN signing_profiles sp ON sp.id = ep.signing_profile_id
                 WHERE ep.supplier_id = ?'
             . ($includeDeleted ? '' : ' AND ep.deleted_at IS NULL')
             . ' ORDER BY ep.is_default DESC, ep.is_active DESC, ep.name, ep.id';
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([$supplierId]);

        return array_map(fn (array $row): array => $this->hydrate($row, $includeSecret), $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /**
     * @return array<string,mixed>|null
     */
    public function findProfile(int $supplierId, int $profileId, bool $includeDeleted = false, bool $includeSecret = false): ?array
    {
        $sql = 'SELECT ep.*, sp.name AS signing_profile_name, sp.code AS signing_profile_code
                  FROM email_profiles ep
             LEFT JOIN signing_profiles sp ON sp.id = ep.signing_profile_id
                 WHERE ep.supplier_id = ? AND ep.id = ?'
             . ($includeDeleted ? '' : ' AND ep.deleted_at IS NULL')
             . ' LIMIT 1';
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([$supplierId, $profileId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $this->hydrate($row, $includeSecret) : null;
    }

    /**
     * @return array<string,mixed>|null
     */
    public function defaultProfile(int $supplierId, bool $includeSecret = false): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT ep.*, sp.name AS signing_profile_name, sp.code AS signing_profile_code
               FROM email_profiles ep
          LEFT JOIN signing_profiles sp ON sp.id = ep.signing_profile_id
              WHERE ep.supplier_id = ?
                AND ep.deleted_at IS NULL
                AND ep.is_active = 1
                AND ep.is_default = 1
           ORDER BY ep.id
              LIMIT 1'
        );
        $stmt->execute([$supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $this->hydrate($row, $includeSecret) : null;
    }

    /**
     * @param array<string,mixed> $data
     */
    public function createProfile(int $supplierId, array $data, ?int $createdBy = null): int
    {
        $normalized = $this->normalize($supplierId, $data, false);

        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO email_profiles
                (supplier_id, name, code, from_email, from_name, reply_to_email, reply_to_name,
                 reply_to_enabled, signing_profile_id, dkim_domain, dkim_selector, dkim_enabled,
                 transport_type, smtp_host, smtp_port, smtp_encryption, smtp_auth_enabled,
                 smtp_auth_type, smtp_username, smtp_password_enc, smtp_verify_peer,
                 smtp_verify_peer_name, smtp_allow_self_signed, smtp_timeout, smtp_keepalive,
                 sendmail_command,
                 is_default, is_active, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $supplierId,
            $normalized['name'],
            $normalized['code'],
            $normalized['from_email'],
            $normalized['from_name'],
            $normalized['reply_to_email'],
            $normalized['reply_to_name'],
            $normalized['reply_to_enabled'] ? 1 : 0,
            $normalized['signing_profile_id'],
            $normalized['dkim_domain'],
            $normalized['dkim_selector'],
            $normalized['dkim_enabled'] ? 1 : 0,
            $normalized['transport_type'],
            $normalized['smtp_host'],
            $normalized['smtp_port'],
            $normalized['smtp_encryption'],
            $normalized['smtp_auth_enabled'] ? 1 : 0,
            $normalized['smtp_auth_type'],
            $normalized['smtp_username'],
            $normalized['smtp_password_enc'],
            $normalized['smtp_verify_peer'] ? 1 : 0,
            $normalized['smtp_verify_peer_name'] ? 1 : 0,
            $normalized['smtp_allow_self_signed'] ? 1 : 0,
            $normalized['smtp_timeout'],
            $normalized['smtp_keepalive'] ? 1 : 0,
            $normalized['sendmail_command'],
            $normalized['is_default'] ? 1 : 0,
            $normalized['is_active'] ? 1 : 0,
            $createdBy,
        ]);
        $id = (int) $this->db->pdo()->lastInsertId();

        if ($normalized['is_default']) {
            $this->clearOtherDefaults($supplierId, $id);
        }

        return $id;
    }

    /**
     * @param array<string,mixed> $changes
     */
    public function updateProfile(int $supplierId, int $profileId, array $changes): bool
    {
        $current = $this->findProfile($supplierId, $profileId);
        if ($current === null) {
            return false;
        }
        $currentWithSecret = $this->findProfile($supplierId, $profileId, false, true);

        $merged = $current;
        foreach ($changes as $key => $value) {
            $merged[$key] = $value;
        }
        if ($currentWithSecret !== null
            && (!array_key_exists('smtp_password', $changes) || trim((string) ($changes['smtp_password'] ?? '')) === '')
        ) {
            $merged['smtp_password'] = $currentWithSecret['smtp_password'] ?? null;
        }
        if (!array_key_exists('reply_to_enabled', $changes) && array_key_exists('reply_to_email', $changes)) {
            $merged['reply_to_enabled'] = trim((string) ($changes['reply_to_email'] ?? '')) !== '';
        }
        if (!array_key_exists('dkim_enabled', $changes)
            && (array_key_exists('dkim_domain', $changes) || array_key_exists('dkim_selector', $changes))
        ) {
            $merged['dkim_enabled'] = trim((string) ($merged['dkim_domain'] ?? '')) !== ''
                || trim((string) ($merged['dkim_selector'] ?? '')) !== '';
        }
        $normalized = $this->normalize($supplierId, $merged, true, $profileId);

        $stmt = $this->db->pdo()->prepare(
            'UPDATE email_profiles
                SET name = ?, code = ?, from_email = ?, from_name = ?,
                    reply_to_email = ?, reply_to_name = ?, reply_to_enabled = ?, signing_profile_id = ?,
                    dkim_domain = ?, dkim_selector = ?, dkim_enabled = ?,
                    transport_type = ?, smtp_host = ?, smtp_port = ?, smtp_encryption = ?,
                    smtp_auth_enabled = ?, smtp_auth_type = ?, smtp_username = ?, smtp_password_enc = ?,
                    smtp_verify_peer = ?, smtp_verify_peer_name = ?, smtp_allow_self_signed = ?,
                    smtp_timeout = ?, smtp_keepalive = ?, sendmail_command = ?,
                    is_default = ?, is_active = ?
              WHERE supplier_id = ? AND id = ? AND deleted_at IS NULL'
        );
        $stmt->execute([
            $normalized['name'],
            $normalized['code'],
            $normalized['from_email'],
            $normalized['from_name'],
            $normalized['reply_to_email'],
            $normalized['reply_to_name'],
            $normalized['reply_to_enabled'] ? 1 : 0,
            $normalized['signing_profile_id'],
            $normalized['dkim_domain'],
            $normalized['dkim_selector'],
            $normalized['dkim_enabled'] ? 1 : 0,
            $normalized['transport_type'],
            $normalized['smtp_host'],
            $normalized['smtp_port'],
            $normalized['smtp_encryption'],
            $normalized['smtp_auth_enabled'] ? 1 : 0,
            $normalized['smtp_auth_type'],
            $normalized['smtp_username'],
            $normalized['smtp_password_enc'],
            $normalized['smtp_verify_peer'] ? 1 : 0,
            $normalized['smtp_verify_peer_name'] ? 1 : 0,
            $normalized['smtp_allow_self_signed'] ? 1 : 0,
            $normalized['smtp_timeout'],
            $normalized['smtp_keepalive'] ? 1 : 0,
            $normalized['sendmail_command'],
            $normalized['is_default'] ? 1 : 0,
            $normalized['is_active'] ? 1 : 0,
            $supplierId,
            $profileId,
        ]);

        if ($normalized['is_default']) {
            $this->clearOtherDefaults($supplierId, $profileId);
        }

        return $stmt->rowCount() > 0;
    }

    /**
     * Připraví runtime profil z neuloženého formuláře. Slouží pro test odeslání
     * bez zápisu do `email_profiles`.
     *
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    public function profileForDraftTest(int $supplierId, array $data, ?int $profileId = null): array
    {
        $draft = $data;
        if ($profileId !== null) {
            $current = $this->findProfile($supplierId, $profileId);
            if ($current === null) {
                throw new \InvalidArgumentException('E-mailový profil nenalezen.');
            }
            $currentWithSecret = $this->findProfile($supplierId, $profileId, false, true);

            $draft = $current;
            foreach ($data as $key => $value) {
                $draft[$key] = $value;
            }
            if ($currentWithSecret !== null
                && (!array_key_exists('smtp_password', $data) || trim((string) ($data['smtp_password'] ?? '')) === '')
            ) {
                $draft['smtp_password'] = $currentWithSecret['smtp_password'] ?? null;
            }
        }

        if (!array_key_exists('reply_to_enabled', $data) && array_key_exists('reply_to_email', $data)) {
            $draft['reply_to_enabled'] = trim((string) ($data['reply_to_email'] ?? '')) !== '';
        }
        if (!array_key_exists('dkim_enabled', $data)
            && (array_key_exists('dkim_domain', $data) || array_key_exists('dkim_selector', $data))
        ) {
            $draft['dkim_enabled'] = trim((string) ($draft['dkim_domain'] ?? '')) !== ''
                || trim((string) ($draft['dkim_selector'] ?? '')) !== '';
        }

        $normalized = $this->normalize($supplierId, $draft, $profileId !== null, $profileId);
        $normalized['id'] = $profileId;
        $normalized['supplier_id'] = $supplierId;

        return $normalized;
    }

    public function softDeleteProfile(int $supplierId, int $profileId): bool
    {
        $code = $this->deletedProfileCode($supplierId, $profileId);
        $stmt = $this->db->pdo()->prepare(
            'UPDATE email_profiles
                SET code = ?, deleted_at = CURRENT_TIMESTAMP, is_active = 0, is_default = 0
              WHERE supplier_id = ? AND id = ? AND deleted_at IS NULL'
        );
        $stmt->execute([$code, $supplierId, $profileId]);

        return $stmt->rowCount() > 0;
    }

    private function clearOtherDefaults(int $supplierId, int $profileId): void
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE email_profiles
                SET is_default = 0
              WHERE supplier_id = ? AND id <> ? AND deleted_at IS NULL'
        );
        $stmt->execute([$supplierId, $profileId]);
    }

    /**
     * @param array<string,mixed> $data
     * @return array{
     *   name:string,code:string,from_email:string,from_name:?string,
     *   reply_to_email:?string,reply_to_name:?string,reply_to_enabled:bool,signing_profile_id:?int,
     *   dkim_domain:?string,dkim_selector:?string,dkim_enabled:bool,
     *   transport_type:string,smtp_host:?string,smtp_port:?int,smtp_encryption:string,
     *   smtp_auth_enabled:bool,smtp_auth_type:string,smtp_username:?string,smtp_password_enc:?string,
     *   smtp_password:?string,
     *   smtp_verify_peer:bool,smtp_verify_peer_name:bool,smtp_allow_self_signed:bool,
     *   smtp_timeout:?int,smtp_keepalive:bool,sendmail_command:?string,is_default:bool,is_active:bool
     * }
     */
    private function normalize(int $supplierId, array $data, bool $updating, ?int $profileId = null): array
    {
        $name = $this->nonEmpty((string) ($data['name'] ?? ''), 'name', 120);
        $code = $this->code((string) ($data['code'] ?? ''));
        $fromEmail = $this->email((string) ($data['from_email'] ?? ''), 'from_email');
        $fromName = $this->nullableString($data['from_name'] ?? null, 'from_name', 120);
        $replyToEmail = $this->nullableEmail($data['reply_to_email'] ?? null, 'reply_to_email');
        $replyToName = $this->nullableString($data['reply_to_name'] ?? null, 'reply_to_name', 120);
        $replyToEnabled = (bool) ($data['reply_to_enabled'] ?? ($replyToEmail !== null));
        if (!$replyToEnabled) {
            $replyToEmail = null;
            $replyToName = null;
        } elseif ($replyToEmail === null) {
            throw new \InvalidArgumentException('Při zapnutém Reply-To je pole reply_to_email povinné.');
        }
        $signingProfileId = $this->nullableInt($data['signing_profile_id'] ?? null);
        if ($signingProfileId !== null) {
            $this->assertSigningProfile($supplierId, $signingProfileId);
        }

        $dkimDomain = $this->nullableDomain($data['dkim_domain'] ?? null, 'dkim_domain', 190);
        $dkimSelector = $this->nullableToken($data['dkim_selector'] ?? null, 'dkim_selector', 80);
        $dkimEnabled = (bool) ($data['dkim_enabled'] ?? ($dkimDomain !== null || $dkimSelector !== null));
        if (!$dkimEnabled) {
            $dkimDomain = null;
            $dkimSelector = null;
        } elseif ($dkimDomain === null || $dkimSelector === null) {
            throw new \InvalidArgumentException('Při zapnutém DKIM jsou pole dkim_domain a dkim_selector povinná.');
        }

        $transportType = $this->oneOf((string) ($data['transport_type'] ?? 'global'), self::TRANSPORT_TYPES, 'transport_type');
        $smtpHost = null;
        $smtpPort = null;
        $smtpEncryption = 'tls';
        $smtpAuthEnabled = false;
        $smtpAuthType = 'PLAIN';
        $smtpUsername = null;
        $smtpPassword = null;
        $smtpPasswordEnc = null;
        $smtpVerifyPeer = true;
        $smtpVerifyPeerName = true;
        $smtpAllowSelfSigned = false;
        $smtpTimeout = null;
        $smtpKeepalive = false;
        $sendmailCommand = null;

        if ($transportType === 'smtp') {
            $smtpHost = $this->nonEmpty((string) ($data['smtp_host'] ?? ''), 'smtp_host', 190);
            $smtpPort = max(1, min(65535, (int) ($data['smtp_port'] ?? 587)));
            $smtpEncryption = $this->oneOf((string) ($data['smtp_encryption'] ?? 'tls'), self::SMTP_ENCRYPTIONS, 'smtp_encryption');
            $smtpAuthEnabled = (bool) ($data['smtp_auth_enabled'] ?? false);
            $smtpAuthType = $this->oneOf(strtoupper((string) ($data['smtp_auth_type'] ?? 'PLAIN')), self::SMTP_AUTH_TYPES, 'smtp_auth_type');
            $smtpVerifyPeer = (bool) ($data['smtp_verify_peer'] ?? true);
            $smtpVerifyPeerName = (bool) ($data['smtp_verify_peer_name'] ?? true);
            $smtpAllowSelfSigned = (bool) ($data['smtp_allow_self_signed'] ?? false);
            $smtpTimeout = max(1, min(300, (int) ($data['smtp_timeout'] ?? 30)));
            $smtpKeepalive = (bool) ($data['smtp_keepalive'] ?? false);
            if ($smtpAuthEnabled) {
                $smtpUsername = $this->nonEmpty((string) ($data['smtp_username'] ?? ''), 'smtp_username', 190);
                $smtpPassword = $this->nullableString($data['smtp_password'] ?? null, 'smtp_password', 255);
                $smtpPasswordEnc = $smtpPassword !== null ? $this->secrets->encrypt($smtpPassword) : null;
            }
        } elseif ($transportType === 'sendmail') {
            $sendmailCommand = $this->nullableString($data['sendmail_command'] ?? null, 'sendmail_command', 255);
        }

        $isActive = (bool) ($data['is_active'] ?? true);
        $isDefault = (bool) ($data['is_default'] ?? (!$updating && $isActive && !$this->hasAnyActiveProfile($supplierId)));
        if ($isDefault && !$isActive) {
            throw new \InvalidArgumentException('Neaktivní e-mailový profil nemůže být výchozí.');
        }

        return [
            'name' => $name,
            'code' => $code,
            'from_email' => $fromEmail,
            'from_name' => $fromName,
            'reply_to_email' => $replyToEmail,
            'reply_to_name' => $replyToName,
            'reply_to_enabled' => $replyToEnabled,
            'signing_profile_id' => $signingProfileId,
            'dkim_domain' => $dkimDomain,
            'dkim_selector' => $dkimSelector,
            'dkim_enabled' => $dkimEnabled,
            'transport_type' => $transportType,
            'smtp_host' => $smtpHost,
            'smtp_port' => $smtpPort,
            'smtp_encryption' => $smtpEncryption,
            'smtp_auth_enabled' => $smtpAuthEnabled,
            'smtp_auth_type' => $smtpAuthType,
            'smtp_username' => $smtpUsername,
            'smtp_password' => $smtpPassword,
            'smtp_password_enc' => $smtpPasswordEnc,
            'smtp_verify_peer' => $smtpVerifyPeer,
            'smtp_verify_peer_name' => $smtpVerifyPeerName,
            'smtp_allow_self_signed' => $smtpAllowSelfSigned,
            'smtp_timeout' => $smtpTimeout,
            'smtp_keepalive' => $smtpKeepalive,
            'sendmail_command' => $sendmailCommand,
            'is_default' => $isDefault,
            'is_active' => $isActive,
        ];
    }

    private function assertSigningProfile(int $supplierId, int $profileId): void
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT allowed_usages_json, owner_user_id, is_active
               FROM signing_profiles
              WHERE supplier_id = ? AND id = ? AND deleted_at IS NULL'
        );
        $stmt->execute([$supplierId, $profileId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false || (int) $row['is_active'] !== 1 || $row['owner_user_id'] !== null) {
            throw new \InvalidArgumentException('S/MIME podpisový profil není dostupný pro tohoto dodavatele.');
        }
        $usages = json_decode((string) $row['allowed_usages_json'], true);
        if (!is_array($usages) || !in_array('email_smime', $usages, true)) {
            throw new \InvalidArgumentException('Vybraný podpisový profil nepodporuje S/MIME e-mail.');
        }
    }

    private function hasAnyActiveProfile(int $supplierId): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT 1 FROM email_profiles
              WHERE supplier_id = ? AND deleted_at IS NULL AND is_active = 1
              LIMIT 1'
        );
        $stmt->execute([$supplierId]);

        return $stmt->fetchColumn() !== false;
    }

    private function deletedProfileCode(int $supplierId, int $profileId): string
    {
        $stmt = $this->db->pdo()->prepare('SELECT code FROM email_profiles WHERE supplier_id = ? AND id = ?');
        $stmt->execute([$supplierId, $profileId]);
        $code = (string) ($stmt->fetchColumn() ?: ('profile-' . $profileId));
        $suffix = '__deleted_' . bin2hex(random_bytes(4));
        $prefixLength = max(0, 80 - strlen($suffix));

        return substr($code, 0, $prefixLength) . $suffix;
    }

    private function nonEmpty(string $value, string $field, int $max): string
    {
        $value = trim($value);
        if ($value === '') {
            throw new \InvalidArgumentException("Pole '{$field}' je povinné.");
        }
        if (mb_strlen($value) > $max) {
            throw new \InvalidArgumentException("Pole '{$field}' je příliš dlouhé.");
        }

        return $value;
    }

    private function code(string $value): string
    {
        $value = strtolower(trim($value));
        if ($value === '' || !preg_match('/^[a-z0-9][a-z0-9_-]{1,79}$/', $value)) {
            throw new \InvalidArgumentException('Kód profilu smí obsahovat jen malá písmena, číslice, pomlčku a podtržítko.');
        }

        return $value;
    }

    /**
     * @param list<string> $allowed
     */
    private function oneOf(string $value, array $allowed, string $field): string
    {
        if (!in_array($value, $allowed, true)) {
            throw new \InvalidArgumentException("Pole '{$field}' má neplatnou hodnotu.");
        }

        return $value;
    }

    private function email(string $value, string $field): string
    {
        $value = trim($value);
        if ($value === '' || filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
            throw new \InvalidArgumentException("Pole '{$field}' musí být platný e-mail.");
        }

        return strtolower($value);
    }

    private function nullableEmail(mixed $value, string $field): ?string
    {
        $value = $this->nullableString($value, $field, 190);
        return $value !== null ? $this->email($value, $field) : null;
    }

    private function nullableString(mixed $value, string $field, int $max): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        if (mb_strlen($value) > $max) {
            throw new \InvalidArgumentException("Pole '{$field}' je příliš dlouhé.");
        }

        return $value;
    }

    private function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        $int = (int) $value;
        return $int > 0 ? $int : null;
    }

    private function nullableDomain(mixed $value, string $field, int $max): ?string
    {
        $value = $this->nullableString($value, $field, $max);
        if ($value === null) {
            return null;
        }
        $value = strtolower($value);
        if (!preg_match('/^(?=.{1,190}$)([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $value)) {
            throw new \InvalidArgumentException("Pole '{$field}' musí být platná doména.");
        }

        return $value;
    }

    private function nullableToken(mixed $value, string $field, int $max): ?string
    {
        $value = $this->nullableString($value, $field, $max);
        if ($value === null) {
            return null;
        }
        if (!preg_match('/^[A-Za-z0-9._-]+$/', $value)) {
            throw new \InvalidArgumentException("Pole '{$field}' obsahuje nepovolené znaky.");
        }

        return $value;
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function hydrate(array $row, bool $includeSecret = false): array
    {
        $passwordEnc = trim((string) ($row['smtp_password_enc'] ?? ''));
        $profile = [
            'id' => (int) $row['id'],
            'supplier_id' => (int) $row['supplier_id'],
            'name' => (string) $row['name'],
            'code' => (string) $row['code'],
            'from_email' => (string) $row['from_email'],
            'from_name' => $row['from_name'] !== null ? (string) $row['from_name'] : null,
            'reply_to_email' => $row['reply_to_email'] !== null ? (string) $row['reply_to_email'] : null,
            'reply_to_name' => $row['reply_to_name'] !== null ? (string) $row['reply_to_name'] : null,
            'reply_to_enabled' => (int) ($row['reply_to_enabled'] ?? 0) === 1,
            'signing_profile_id' => $row['signing_profile_id'] !== null ? (int) $row['signing_profile_id'] : null,
            'signing_profile_name' => $row['signing_profile_name'] !== null ? (string) $row['signing_profile_name'] : null,
            'signing_profile_code' => $row['signing_profile_code'] !== null ? (string) $row['signing_profile_code'] : null,
            'dkim_domain' => $row['dkim_domain'] !== null ? (string) $row['dkim_domain'] : null,
            'dkim_selector' => $row['dkim_selector'] !== null ? (string) $row['dkim_selector'] : null,
            'dkim_enabled' => (int) ($row['dkim_enabled'] ?? 0) === 1,
            'transport_type' => (string) ($row['transport_type'] ?? 'global'),
            'smtp_host' => ($row['smtp_host'] ?? null) !== null ? (string) $row['smtp_host'] : null,
            'smtp_port' => ($row['smtp_port'] ?? null) !== null ? (int) $row['smtp_port'] : null,
            'smtp_encryption' => (string) ($row['smtp_encryption'] ?? 'tls'),
            'smtp_auth_enabled' => (int) ($row['smtp_auth_enabled'] ?? 0) === 1,
            'smtp_auth_type' => (string) ($row['smtp_auth_type'] ?? 'PLAIN'),
            'smtp_username' => ($row['smtp_username'] ?? null) !== null ? (string) $row['smtp_username'] : null,
            'has_smtp_password' => $passwordEnc !== '',
            'smtp_verify_peer' => (int) ($row['smtp_verify_peer'] ?? 1) === 1,
            'smtp_verify_peer_name' => (int) ($row['smtp_verify_peer_name'] ?? 1) === 1,
            'smtp_allow_self_signed' => (int) ($row['smtp_allow_self_signed'] ?? 0) === 1,
            'smtp_timeout' => ($row['smtp_timeout'] ?? null) !== null ? (int) $row['smtp_timeout'] : null,
            'smtp_keepalive' => (int) ($row['smtp_keepalive'] ?? 0) === 1,
            'sendmail_command' => ($row['sendmail_command'] ?? null) !== null ? (string) $row['sendmail_command'] : null,
            'is_default' => (int) $row['is_default'] === 1,
            'is_active' => (int) $row['is_active'] === 1,
            'created_by' => $row['created_by'] !== null ? (int) $row['created_by'] : null,
            'created_at' => (string) $row['created_at'],
            'updated_at' => (string) $row['updated_at'],
            'deleted_at' => $row['deleted_at'] !== null ? (string) $row['deleted_at'] : null,
        ];

        if ($includeSecret && $passwordEnc !== '') {
            $profile['smtp_password'] = $this->secrets->decrypt($passwordEnc);
        }

        return $profile;
    }
}
