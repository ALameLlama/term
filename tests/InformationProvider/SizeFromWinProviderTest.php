<?php

declare(strict_types=1);

namespace PhpTui\Term\Tests\InformationProvider;

use Generator;
use PHPUnit\Framework\Attributes\DataProvider;
use PhpTui\Term\InformationProvider\SizeFromWinProvider;
use PhpTui\Term\ProcessResult;
use PhpTui\Term\ProcessRunner\ClosureRunner;
use PhpTui\Term\TerminalInformation\Size;
use PHPUnit\Framework\TestCase;

final class SizeFromWinProviderTest extends TestCase
{
    #[DataProvider('provideSizeFromWin')]
    public function testSizeFromWin(string $output, Size $expected): void
    {
        $runner = ClosureRunner::new(function (array $command) use ($output): ProcessResult {
            return new ProcessResult(
                0,
                $output,
                '',
            );
        });

        $provider = SizeFromWinProvider::new($runner);
        self::assertEquals($expected, $provider->for(Size::class));
    }

    public static function provideSizeFromWin(): Generator
    {
        yield 'normal' => [
                <<<'EOT'
                    Status for device COM1:
                    -----------------------
                        Baud:            1200
                        Parity:          None
                        Data Bits:       7
                        Stop Bits:       1
                        Timeout:         OFF
                        XON/XOFF:        OFF
                        CTS handshaking: OFF
                        DSR handshaking: OFF
                        DSR sensitivity: OFF
                        DTR circuit:     ON
                        RTS circuit:     ON


                    Status for device CON:
                    ----------------------
                        Lines:          42
                        Columns:        140
                        Keyboard rate:  31
                        Keyboard delay: 1
                        Code page:      437
                    EOT,
            new Size(42, 140)

        ];
    }

    public function testSizeFromWinNoMatch(): void
    {
        $runner = ClosureRunner::new(function (array $command): ProcessResult {
            return new ProcessResult(
                0,
                <<<'EOT'
                    foobar
                    EOT,
                ''
            );
        });

        $provider = SizeFromWinProvider::new($runner);
        $size = $provider->for(Size::class);
        self::assertNull($size);
    }
}
