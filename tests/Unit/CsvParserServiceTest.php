<?php

namespace Tests\Unit;

use App\Domain\Reconciliation\Exceptions\CsvParseException;
use App\Domain\Reconciliation\Services\CsvParserService;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CsvParserServiceTest extends TestCase
{
    private CsvParserService $parser;

    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->parser = new CsvParserService;
        $this->tempDir = sys_get_temp_dir().'/reconciliation-csv-'.uniqid('', true);
        mkdir($this->tempDir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tempDir.'/*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($this->tempDir);

        parent::tearDown();
    }

    #[Test]
    public function it_parses_comma_delimited_rows(): void
    {
        $path = $this->write('a.csv', "Gift Date,Gift Value\n2025-01-01,10.00\n2025-01-02,20.00\n");

        $result = $this->parser->parse($path);

        $this->assertSame(',', $result['delimiter']);
        $this->assertSame(['Gift Date', 'Gift Value'], $result['headers']);
        $this->assertCount(2, $result['rows']);
        $this->assertSame('10.00', $result['rows'][0]['Gift Value']);
    }

    #[Test]
    public function it_detects_semicolon_and_tab_delimiters(): void
    {
        $semicolon = $this->write('semi.csv', "Gift Date;Gift Value\n2025-01-01;10.00\n");
        $tab = $this->write('tab.csv', "Gift Date\tGift Value\n2025-01-01\t10.00\n");

        $this->assertSame(';', $this->parser->parse($semicolon)['delimiter']);
        $this->assertSame("\t", $this->parser->parse($tab)['delimiter']);
    }

    #[Test]
    public function it_strips_utf8_bom(): void
    {
        $path = $this->write('bom.csv', "\xEF\xBB\xBFGift Date,Gift Value\n2025-01-01,10.00\n");

        $result = $this->parser->parse($path);

        $this->assertSame('Gift Date', $result['headers'][0]);
    }

    #[Test]
    public function it_parses_quoted_fields_with_embedded_newlines_and_commas(): void
    {
        $path = $this->write(
            'quoted.csv',
            "Supporter,Notes\n\"Dana Smith\",\"Line 1\nLine 2, still quoted\"\n"
        );

        $result = $this->parser->parse($path);

        $this->assertSame("Line 1\nLine 2, still quoted", $result['rows'][0]['Notes']);
    }

    #[Test]
    public function it_rejects_binary_content(): void
    {
        $path = $this->write('bin.csv', "Gift Date,Gift Value\n2025-01-01,10\0.00\n");

        $this->expectException(CsvParseException::class);
        $this->expectExceptionMessageMatches('/Binary/');

        $this->parser->parse($path);
    }

    #[Test]
    public function it_rejects_too_many_rows(): void
    {
        config(['reconciliation.max_data_rows' => 2]);

        $path = $this->write(
            'rows.csv',
            "Gift Date,Gift Value\n2025-01-01,1\n2025-01-02,2\n2025-01-03,3\n"
        );

        try {
            $this->parser->parse($path);
            $this->fail('Expected CsvParseException');
        } catch (CsvParseException $exception) {
            $this->assertSame('TOO_MANY_ROWS', $exception->errorCode);
        }
    }

    #[Test]
    public function it_rejects_too_many_columns(): void
    {
        config(['reconciliation.max_columns' => 2]);

        $path = $this->write('cols.csv', "A,B,C\n1,2,3\n");

        try {
            $this->parser->parse($path);
            $this->fail('Expected CsvParseException');
        } catch (CsvParseException $exception) {
            $this->assertSame('TOO_MANY_COLUMNS', $exception->errorCode);
        }
    }

    #[Test]
    public function it_rejects_fields_that_are_too_long(): void
    {
        config(['reconciliation.max_field_length' => 5]);

        $path = $this->write('long.csv', "Name\nabcdef\n");

        try {
            $this->parser->parse($path);
            $this->fail('Expected CsvParseException');
        } catch (CsvParseException $exception) {
            $this->assertSame('FIELD_TOO_LONG', $exception->errorCode);
        }
    }

    #[Test]
    public function it_rejects_malformed_quoting(): void
    {
        $path = $this->write('bad.csv', "Name,Value\n\"unclosed,10\n");

        $this->expectException(CsvParseException::class);

        $this->parser->parse($path);
    }

    private function write(string $name, string $contents): string
    {
        $path = $this->tempDir.'/'.$name;
        file_put_contents($path, $contents);

        return $path;
    }
}
