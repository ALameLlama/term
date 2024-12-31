<?php

declare(strict_types=1);

namespace PhpTui\Term\InformationProvider;

use PhpTui\Term\InformationProvider;
use PhpTui\Term\TerminalInformation\Size;
use PhpTui\Term\TerminalInformation;
use PhpTui\Term\WindowsConsole;

final class SizeFromWinProvider implements InformationProvider
{
    private WindowsConsole $windowsConsole;

    private function __construct()
    {
        $this->windowsConsole = WindowsConsole::new();
    }

    public static function new(): self
    {
        return new self();
    }

    public function for(string $classFqn): ?TerminalInformation
    {
        if ($classFqn !== Size::class) {
            return null;
        }

        $out = $this->windowsConsole->getConsoleScreenBufferInfo();

        if (! is_array($out)) {
            return null;
        }

        /**
         * @phpstan-ignore-next-line */
        return $this->parse($out);
    }

    /**
    * @param array{screenBufferSize: array{x: int, y: int}} $out
    */
    private function parse(array $out): Size
    {
        return new Size(max(0, (int) ($out['screenBufferSize']['y'])), max(0, (int) ($out['screenBufferSize']['x'])));
    }
}
