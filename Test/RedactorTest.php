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
            'json double-quoted' => ['{"password":"x y","user":"bob"}', ['x y', 'bob'], ['"password=[redacted]', '"user=[redacted]']],
            'json single-quoted' => ["{'token': 'abc def'}", ['abc', 'def'], ["'token=[redacted]"]],
            'json authorization' => ['{"Authorization":"Bearer abc.def"}', ['Bearer', 'abc.def'], ['"Authorization=[redacted]']],
            'escaped double quote' => ['{"password":"a\\"b c","user":"bob"}', ['a\\"b', 'b c', 'bob'], ['"password=[redacted]', '"user=[redacted]']],
            'escaped single quote' => ["{'token': 'a\\'b c'}", ["a\\'b", 'b c'], ["'token=[redacted]"]],
            'unterminated quote' => ["password: \"a b c\nnext", ['a b c', 'b c'], ['password=[redacted]', 'next']],
            'camelCase compound' => ['authToken=xyz123', ['xyz123'], ['authToken=[redacted]']],
            'PascalCase compound' => ['ClientSecret: xyz123', ['xyz123'], ['ClientSecret=[redacted]']],
            'snake compound' => ['access_token=tok_456', ['tok_456'], ['access_token=[redacted]']],
            'kebab compound' => ['x-api-key: k-789', ['k-789'], ['x-api-key=[redacted]']],
            'credentials' => ['credentials=ops:hunter2', ['ops:hunter2', 'hunter2'], ['credentials=[redacted]']],
            'credential' => ['credential: abc', ['abc'], ['credential=[redacted]']],
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

    /** @return array<string,array{0:int,1:string,2:string}> size, value fill, quote */
    public static function longQuotedValues(): array
    {
        $rows = [];
        foreach (['10 KB' => 10 * 1024, '100 KB' => 100 * 1024, '1 MB' => 1024 * 1024] as $label => $size) {
            $rows[$label.' double'] = [$size, 'x', '"'];
            $rows[$label.' double escaped'] = [$size, 'x\\"', '"'];
            $rows[$label.' single'] = [$size, 'x', "'"];
            $rows[$label.' single escaped'] = [$size, "x\\'", "'"];
        }
        return $rows;
    }

    /**
     * A long quoted value must not exhaust PCRE (which would turn the whole message into '').
     *
     * @dataProvider longQuotedValues
     */
    public function testLongQuotedValuesAreRedactedWithoutAPcreError(int $size, string $fill, string $quote): void
    {
        $secret = 'SECRETSTART'.str_repeat($fill, intdiv($size, strlen($fill))).'SECRETEND';
        $message = 'before {'.$quote.'password'.$quote.':'.$quote.$secret.$quote.',"note":"after"} tail';

        $out = (new Redactor())->redact($message);

        $this->assertSame(PREG_NO_ERROR, preg_last_error(), preg_last_error_msg());
        $this->assertStringContainsString($quote.'password=[redacted]', $out);
        $this->assertStringNotContainsString('SECRETSTART', $out);
        $this->assertStringNotContainsString('SECRETEND', $out);
        $this->assertStringStartsWith('before {', $out);
        $this->assertStringEndsWith(',"note":"after"} tail', $out);
        $this->assertLessThan(100, strlen($out));
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
        foreach (['Cannot bypass: tokens expired for users', 'superuser=root rejected', 'passed: 3, compass: north'] as $text) {
            $this->assertSame($text, (new Redactor())->redact($text));
        }
        $this->assertSame('Connection could not be established with host mail', (new Redactor())->redact('Connection could not be established with host mail'));
    }
}
