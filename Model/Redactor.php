<?php
namespace Kanboard\Plugin\Steward\Model;

/**
 * Strips anything resembling a credential from a message before it is logged (spec §6). Keys stay visible
 * (`password=[redacted]`) so the log still says what went wrong.
 */
class Redactor
{
    public const MASK = '[redacted]';
    /** Shorter configured values are too likely to be ordinary text. */
    public const MIN_LITERAL = 3;

    /** pattern => replacement, applied in order. */
    private const RULES = [
        // (Proxy-)Authorization: scheme and credentials, to end of line. The key may be quoted (JSON).
        '/(?<![A-Za-z-])((?:proxy-)?authorization)["\']?\s*[:=][^\r\n]*/i' => '$1='.self::MASK,
        // URL userinfo: scheme://user:pass@host.
        '~\b([a-z][a-z0-9+.-]*://)[^/\s@]+@~i' => '$1'.self::MASK.'@',
        // SMTP AUTH with an initial response.
        '/\b(AUTH\s+(?:PLAIN|LOGIN|CRAM-MD5|XOAUTH2))[ \t]+[^\r\n]+/i' => '$1 '.self::MASK,
        // A bare base64 line answering a 334 challenge (AUTH LOGIN username/password, CRAM-MD5 response).
        '~(\b334\b[^\r\n]*\r?\n)([<>]?[ \t]*)[A-Za-z0-9+/]+={0,2}[ \t]*(?=\r?\n|$)~' => '$1$2'.self::MASK,
        // key: value / key=value. The key may be quoted (JSON) and compound when it ends in a listed word after a
        // `_`/`-` or a camelCase hump (access_token, x-api-key, authToken), never mid-word (bypass, superuser).
        // Prefix segments are bounded and each ends in a fixed separator or before an upper-case letter, so
        // backtracking stays small. The value is bare or quoted; an unterminated quote runs to end of line.
        '/(?<![A-Za-z0-9_-])((?:[A-Za-z0-9]{1,32}[_-]|[A-Z]?[a-z0-9]{1,32}(?=[A-Z])){0,4}'
            .'(?i:password|passwd|pass|pwd|secret|token|api[_-]?key|credentials?|username|user))'
            .'["\']?\s*[:=]\s*("[^"\r\n]*"?|\'[^\'\r\n]*\'?|\S+)/' => '$1='.self::MASK,
    ];

    /** @var list<string> longest first */
    private array $literals;

    /** @param list<string> $literals configured secrets (SMTP username, password), replaced wherever they appear */
    public function __construct(array $literals = [])
    {
        $literals = array_values(array_unique(array_filter(
            array_map('strval', $literals),
            fn (string $v) => strlen($v) >= self::MIN_LITERAL
        )));
        usort($literals, fn ($a, $b) => strlen($b) <=> strlen($a));
        $this->literals = $literals;
    }

    public function redact(string $message): string
    {
        if ($this->literals !== []) {
            $message = str_replace($this->literals, self::MASK, $message);
        }
        foreach (self::RULES as $pattern => $replacement) {
            $message = (string) preg_replace($pattern, $replacement, $message);
        }
        return $message;
    }
}
