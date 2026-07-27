<?php
// lib/ImapHelper.php — IMAP utilities for HRI Mail

class ImapHelper {

    /**
     * Open an IMAP connection to a folder.
     * Automatically resolves cPanel Dovecot folder names (Trash, Sent, Drafts, Spam).
     */
    public static function open(string $email, string $password, string $folderType = 'INBOX') {
        $folderName = self::resolveFolder($email, $password, $folderType);
        $connStr    = '{' . IMAP_HOST . ':' . IMAP_PORT . '/imap/ssl/novalidate-cert}' . $folderName;
        $mbox       = @imap_open($connStr, $email, $password, 0, 1, ['DISABLE_AUTHENTICATOR' => 'GSSAPI']);
        return $mbox ?: null;
    }

    /**
     * Resolve the actual IMAP folder name for common folder types.
     * cPanel Dovecot can use "Sent", "Sent Items", "INBOX.Sent" etc.
     */
    public static function resolveFolder(string $email, string $password, string $folderType): string {
        if ($folderType === 'INBOX') return 'INBOX';

        // Hardcoded verified folder names for hrindexx.com (confirmed 17 Jun 2026)
        $known = [
            'Sent'   => 'INBOX.Sent',
            'Trash'  => 'INBOX.Trash',
            'Drafts' => 'INBOX.Drafts',
            'Spam'   => 'INBOX.Junk E-mail',
        ];
        if (isset($known[$folderType])) return $known[$folderType];

        // Dynamic discovery for anything else
        $cacheKey = 'imap_folder_v3_' . md5($email . $folderType);
        if (!empty($_SESSION[$cacheKey])) return $_SESSION[$cacheKey];

        $server   = '{' . IMAP_HOST . ':' . IMAP_PORT . '/imap/ssl/novalidate-cert}';
        $listConn = @imap_open($server, $email, $password, OP_HALFOPEN, 1, ['DISABLE_AUTHENTICATOR' => 'GSSAPI']);
        if ($listConn) {
            $folders = @imap_list($listConn, '{' . IMAP_HOST . ':' . IMAP_PORT . '}', '*') ?: [];
            imap_close($listConn);
            foreach ($folders as $f) {
                $fname = str_replace('{' . IMAP_HOST . ':' . IMAP_PORT . '}', '', $f);
                if (stripos($fname, $folderType) !== false) {
                    $_SESSION[$cacheKey] = $fname;
                    return $fname;
                }
            }
        }
        return $folderType;
    }
    /**
     * Get all available folders for this mailbox.
     */
    public static function listFolders(string $email, string $password): array {
        $connStr = '{' . IMAP_HOST . ':' . IMAP_PORT . '/imap/ssl/novalidate-cert}';
        $mbox    = @imap_open($connStr, $email, $password, OP_HALFOPEN, 1, ['DISABLE_AUTHENTICATOR' => 'GSSAPI']);
        if (!$mbox) return [];

        $folders = @imap_list($mbox, '{' . IMAP_HOST . ':' . IMAP_PORT . '}', '*') ?: [];
        imap_close($mbox);

        return array_map(function($f) {
            return str_replace('{' . IMAP_HOST . ':' . IMAP_PORT . '}', '', $f);
        }, $folders);
    }

    /**
     * Decode email subject properly (handles =?utf-8?Q? and =?utf-8?B? encoding).
     */
    public static function decodeSubject(string $subject): string {
        $decoded = imap_mime_header_decode($subject);
        $result  = '';
        foreach ($decoded as $part) {
            $charset  = strtolower($part->charset ?? 'utf-8');
            $text     = $part->text ?? '';
            if ($charset !== 'default' && $charset !== 'utf-8') {
                $text = @iconv($charset, 'UTF-8//TRANSLIT//IGNORE', $text) ?: $text;
            }
            $result .= $text;
        }
        return $result ?: $subject;
    }

    /**
     * Decode sender name properly.
     */
    public static function decodeSender(string $from): string {
        $decoded = imap_mime_header_decode($from);
        $result  = '';
        foreach ($decoded as $part) {
            $charset = strtolower($part->charset ?? 'utf-8');
            $text    = $part->text ?? '';
            if ($charset !== 'default' && $charset !== 'utf-8') {
                $text = @iconv($charset, 'UTF-8//TRANSLIT//IGNORE', $text) ?: $text;
            }
            $result .= $text;
        }
        // Strip angle brackets and quotes
        $result = preg_replace('/<[^>]+>/', '', $result);
        return trim($result, ' "\'') ?: $from;
    }

    /**
     * Extract email body — returns ['html' => ..., 'plain' => ..., 'attachments' => []]
     */
    public static function getBody($mbox, int $msgNo): array {
        $result = ['html' => '', 'plain' => '', 'attachments' => []];

        $structure = @imap_fetchstructure($mbox, $msgNo);
        if (!$structure) return $result;

        if (!isset($structure->parts)) {
            // Simple single-part message
            $body = @imap_body($mbox, $msgNo);
            $body = self::decodeBody($body, $structure->encoding ?? 0);
            if ($structure->subtype === 'HTML') {
                $result['html'] = $body;
            } else {
                $result['plain'] = $body;
            }
            return $result;
        }

        // Multi-part message
        self::parseParts($mbox, $msgNo, $structure->parts, $result, '');
        return $result;
    }

    /**
     * Recursively parse MIME parts.
     */
    private static function parseParts($mbox, int $msgNo, array $parts, array &$result, string $prefix): void {
        foreach ($parts as $idx => $part) {
            $partNum = $prefix ? $prefix . '.' . ($idx + 1) : (string)($idx + 1);

            $type        = $part->type ?? 0;
            $subtype     = strtoupper($part->subtype ?? '');
            $enc         = $part->encoding ?? 0;
            $disposition = strtolower($part->ifdisposition ? ($part->disposition ?? '') : '');

            if ($type === 0) { // Text part
                $body    = @imap_fetchbody($mbox, $msgNo, $partNum);
                $body    = self::decodeBody($body, $enc);
                $charset = self::getCharset($part);
                if ($charset && strtolower($charset) !== 'utf-8') {
                    $body = @iconv($charset, 'UTF-8//TRANSLIT//IGNORE', $body) ?: $body;
                }
                if ($subtype === 'HTML' && empty($result['html'])) {
                    $result['html'] = $body;
                } elseif ($subtype === 'PLAIN' && empty($result['plain'])) {
                    $result['plain'] = $body;
                }
            } elseif ($type === 1 && isset($part->parts)) { // Multipart
                self::parseParts($mbox, $msgNo, $part->parts, $result, $partNum);
            } else { // Potential attachment or inline image
                // Extract filename
                $filename = '';
                if (!empty($part->dparameters)) {
                    foreach ($part->dparameters as $dp) {
                        if (strtolower($dp->attribute) === 'filename') $filename = $dp->value;
                    }
                }
                if (!$filename && !empty($part->parameters)) {
                    foreach ($part->parameters as $p) {
                        if (strtolower($p->attribute) === 'name') $filename = $p->value;
                    }
                }

                // Get Content-ID for inline images
                $cid = '';
                if ($part->ifid ?? false) {
                    $cid = trim($part->id ?? '', '<>');
                }

                // Skip inline non-text parts (embedded images in email body)
                // But NEVER skip text/html or text/plain parts regardless of disposition
                if ($type !== 0 && ($disposition === 'inline' || ($cid && !$filename))) {
                    if ($cid) {
                        if (!isset($result['cid_map'])) $result['cid_map'] = [];
                        $result['cid_map'][$cid] = $partNum;
                    }
                    continue;
                }

                // Real attachment - must have a filename
                if (!$filename) continue;

                $decodedName = (function($f) {
                    $d = @imap_utf8($f);
                    if ($d && strpos($d, '=?') === false) return $d;
                    $decoded = @iconv_mime_decode($f, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');
                    return $decoded ?: $f;
                })($filename);

                $result['attachments'][] = [
                    'name'    => $decodedName,
                    'partNum' => $partNum,
                    'type'    => $type,
                    'enc'     => $enc,
                ];
            }
        }
    }

    /**
     * Decode body based on encoding type.
     */
    public static function decodeBody(string $body, int $encoding): string {
        switch ($encoding) {
            case 1: return imap_8bit($body);       // 8bit
            case 2: return imap_binary($body);     // binary
            case 3: return base64_decode($body);   // base64
            case 4: return quoted_printable_decode($body); // QP
            default: return $body;                 // 7bit / other
        }
    }

    /**
     * Get charset from MIME part parameters.
     */
    private static function getCharset($part): string {
        if (!empty($part->parameters)) {
            foreach ($part->parameters as $p) {
                if (strtolower($p->attribute) === 'charset') return $p->value;
            }
        }
        return 'utf-8';
    }

    /**
     * Sanitize HTML email body for safe display.
     * Removes scripts, dangerous attributes while keeping formatting.
     */
    public static function sanitizeHtml(string $html): string {
        if (!$html) return '';

        // Remove script tags and their content
        $html = preg_replace('/<script\b[^>]*>.*?<\/script>/is', '', $html);
        // Keep <style> tags - they're safely scoped inside the srcdoc iframe
        // Removing them breaks email layout and branding
        // Remove event handlers
        $html = preg_replace('/\s+on\w+\s*=\s*["\'][^"\']*["\']/i', '', $html);
        $html = preg_replace('/\s+on\w+\s*=\s*[^\s>]*/i', '', $html);
        // Remove javascript: links
        $html = preg_replace('/href\s*=\s*["\']javascript:[^"\']*["\']/i', 'href="#"', $html);
        // Make all links open in new tab
        $html = preg_replace('/<a\s/i', '<a target="_blank" rel="noopener noreferrer" ', $html);
        // Remove meta/link/base tags
        $html = preg_replace('/<(meta|link|base)\b[^>]*>/i', '', $html);

        return $html;
    }

    /**
     * Convert plain text email to HTML with proper line breaks and link detection.
     */
    public static function plainToHtml(string $text): string {
        $text = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
        // Detect URLs and make them clickable
        $text = preg_replace(
            '/(https?:\/\/[^\s<>"{}|\\^`\[\]]+)/i',
            '<a href="$1" target="_blank" rel="noopener noreferrer" style="color:#002850;">$1</a>',
            $text
        );
        // Convert line breaks
        $text = nl2br($text);
        return '<div style="font-family:Arial,sans-serif;font-size:14px;line-height:1.8;color:#334155;">' . $text . '</div>';
    }
}
