<?php
require_once 'tests/units/Base.php';

use Kanboard\Plugin\Steward\Model\Redactor;
use PHPUnit\Framework\TestCase;

class RedactorTest extends TestCase
{
    /** message, secrets that must be gone, text that must survive */
    public static function shapes(): array
    {
        return [
            'password=' => ['login failed password=hunter2 for ops', ['hunter2'], ['password=[redacted]', 'for ops']],
            'pass:' => ['pass: hunter2', ['hunter2'], ['pass=[redacted]']],
            'pwd=' => ['pwd=hunter2;', ['hunter2'], ['pwd=[redacted]']],
            'passwd' => ['passwd = hunter2', ['hunter2'], ['passwd=[redacted]']],
            'secret' => ['client secret: s3cr3t-value', ['s3cr3t-value'], ['secret=[redacted]']],
            'token' => ['token=tok_123', ['tok_123'], ['token=[redacted]']],
            'api_key' => ['api_key: sk-live-123', ['sk-live-123'], ['api_key=[redacted]']],
            'apikey prefixed' => ['smtp_api-key=sk-live-123', ['sk-live-123'], ['api-key=[redacted]']],
            'username' => ['username=ops@example.test rejected', ['ops@example.test'], ['username=[redacted]', 'rejected']],
            'user' => ['user: opsbot', ['opsbot'], ['user=[redacted]']],
            'double-quoted with spaces' => ['password="correct horse battery" bad', ['correct', 'horse', 'battery'], ['password=[redacted]', 'bad']],
            'single-quoted with spaces' => ["secret='two words' end", ['two', 'words'], ['secret=[redacted]', 'end']],
            'authorization bearer' => ["Authorization: Bearer abc.def\nnext line", ['Bearer', 'abc.def'], ['Authorization=[redacted]', 'next line']],
            'authorization basic' => ['authorization: Basic b3BzOmh1bnRlcjI=', ['Basic', 'b3BzOmh1bnRlcjI='], ['authorization=[redacted]']],
            'proxy-authorization' => ['Proxy-Authorization: Basic b3BzOmh1bnRlcjI=', ['b3BzOmh1bnRlcjI='], ['Proxy-Authorization=[redacted]']],
            'url userinfo' => ['cannot reach smtp://ops:hunter2@mail.example.test:587', ['ops:', 'hunter2'], ['smtp://[redacted]@mail.example.test:587']],
            'auth plain' => ['> AUTH PLAIN AG9wcwBodW50ZXIy', ['AG9wcwBodW50ZXIy'], ['AUTH PLAIN [redacted]']],
            'auth login 334 lines' => ["> AUTH LOGIN\n< 334 VXNlcm5hbWU6\n> b3Bz\n< 334 UGFzc3dvcmQ6\n> aHVudGVyMg==\n< 535 failed",
                ['b3Bz', 'aHVudGVyMg=='], ['AUTH LOGIN', '334 VXNlcm5hbWU6', '535 failed']],
            'auth xoauth2' => ['AUTH XOAUTH2 dXNlcj1vcHMBYXV0aD1CZWFyZXIgeHl6AQE=', ['dXNlcj1vcHMBYXV0aD1CZWFyZXIgeHl6AQE='], ['AUTH XOAUTH2 [redacted]']],
        ];
    }

    /** @dataProvider shapes */
    public function testShapes(string $message, array $gone, array $kept): void
    {
        $out = (new Redactor())->redact($message);
        foreach ($gone as $secret) {
            $this->assertStringNotContainsString($secret, $out, $out);
        }
        foreach ($kept as $text) {
            $this->assertStringContainsString($text, $out, $out);
        }
    }

    public function testConfiguredValuesAreReplacedLiterallyWhereverTheyAppear(): void
    {
        $out = (new Redactor(['mailops', 'Tr1cky pass', 'ab', '']))->redact('535 mailops not allowed (Tr1cky pass) ab');
        $this->assertStringNotContainsString('mailops', $out);
        $this->assertStringNotContainsString('Tr1cky pass', $out);
        // Under 3 characters: too likely to be ordinary text.
        $this->assertStringContainsString(' ab', $out);
        $this->assertStringContainsString('535', $out);
    }

    public function testPlainTextIsUnchanged(): void
    {
        $this->assertSame('Connection could not be established with host mail', (new Redactor())->redact('Connection could not be established with host mail'));
    }
}
