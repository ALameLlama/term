<?php

declare(strict_types=1);

namespace PhpTui\Term\InformationProvider;

use PhpTui\Term\InformationProvider;
use PhpTui\Term\ProcessRunner;
use PhpTui\Term\ProcessRunner\ProcRunner;
use PhpTui\Term\TerminalInformation\Size;
use PhpTui\Term\TerminalInformation;

final class SizeFromWinProvider implements InformationProvider
{
    private function __construct(private readonly ProcessRunner $runner)
    {
    }

    public static function new(?ProcessRunner $processRunner = null): self
    {
        return new self($processRunner ?? new ProcRunner());
    }

    public function for(string $classFqn): ?TerminalInformation
    {
        if ($classFqn !== Size::class) {
            return null;
        }
        $out = $this->queryWin();
        if (null === $out) {
            return null;
        }

        /**
         * @phpstan-ignore-next-line */
        return $this->parse($out);

    }

    private function queryWin(): ?string
    {
        $result = $this->runner->run(['cmd', '/c', 'mode']);
        if ($result->exitCode !== 0) {
            return null;
        }

        return $result->stdout;
    }

    // Example output:
    // Status for device COM1:
    // -----------------------
    //     Baud:            1200
    //     Parity:          None
    //     Data Bits:       7
    //     Stop Bits:       1
    //     Timeout:         OFF
    //     XON/XOFF:        OFF
    //     CTS handshaking: OFF
    //     DSR handshaking: OFF
    //     DSR sensitivity: OFF
    //     DTR circuit:     ON
    //     RTS circuit:     ON
    //
    //
    // Status for device CON:
    // ----------------------
    //     Lines:          30
    //     Columns:        120
    //     Keyboard rate:  31
    //     Keyboard delay: 1
    //     Code page:      437
    private function parse(string $out): ?Size
    {
        if (!preg_match('/Lines:\s+(\d+)\s+Columns:\s+(\d+)/i', $out, $matches)) {
            return null;
        }

        return new Size(max(0, (int) ($matches[1])), max(0, (int) ($matches[2])));
    }
}
