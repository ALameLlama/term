<?php

declare(strict_types=1);

namespace PhpTui\Term\RawMode;

use PhpTui\Term\RawMode;
use PhpTui\Term\WindowsConsole;

final class WinRawMode implements RawMode
{
    // https://learn.microsoft.com/en-us/windows/console/setconsolemode
    private const ENABLE_PROCESSED_INPUT = 0x0001;
    private const ENABLE_LINE_INPUT = 0x0002;
    private const ENABLE_ECHO_INPUT = 0x0004;
    private const ENABLE_QUICK_EDIT_MODE = 0x0040;
    private const NOT_RAW_MODE_MASK = self::ENABLE_LINE_INPUT | self::ENABLE_ECHO_INPUT | self::ENABLE_PROCESSED_INPUT | self::ENABLE_QUICK_EDIT_MODE;

    private ?int $originalSettings = null;

    private WindowsConsole $windowsConsole;

    public function __construct()
    {
        $this->windowsConsole = WindowsConsole::new();
    }

    public static function new(): self
    {
        return new self();
    }

    // https://github.com/crossterm-rs/crossterm/blob/master/src/terminal/sys/windows.rs#L31
    public function enable(): void
    {
        if ($this->isEnabled()) {
            return;
        }

        $mode = $this->windowsConsole->GetConsoleMode();

        $this->originalSettings = $mode;

        $newMode = $mode &= ~self::NOT_RAW_MODE_MASK;

        $this->windowsConsole->SetConsoleMode($newMode);
    }

    public function disable(): void
    {
        if (! $this->isEnabled()) {
            return;
        }

        /**
         * @phpstan-ignore-next-line */
        $this->windowsConsole->SetConsoleMode($this->originalSettings);

        $this->originalSettings = null;
    }

    public function isEnabled(): bool
    {
        return $this->originalSettings !== null;
    }
}
