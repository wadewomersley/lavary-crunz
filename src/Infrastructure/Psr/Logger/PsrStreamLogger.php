<?php

declare(strict_types=1);

namespace Crunz\Infrastructure\Psr\Logger;

use Crunz\Clock\ClockInterface;
use Crunz\Exception\CrunzException;
use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;

final class PsrStreamLogger extends AbstractLogger
{
    private const DATE_FORMAT = 'Y-m-d H:i:s';

    private string $outputStreamPath;
    private string $errorStreamPath;
    /** @var resource|null */
    private $outputHandler;
    /** @var resource|null */
    private $errorHandler;

    public function __construct(
        private \DateTimeZone $timezone,
        private ClockInterface $clock,
        ?string $outputStreamPath,
        ?string $errorStreamPath,
        private bool $ignoreEmptyContext = false,
        private bool $timezoneLog = false,
        private bool $allowLineBreaks = false
    ) {
        $this->outputStreamPath = $outputStreamPath ?? '';
        $this->errorStreamPath = $errorStreamPath ?? '';
    }

    public function __destruct()
    {
        $this->closeStream($this->outputHandler);
        $this->closeStream($this->errorHandler);
    }

    /** {@inheritdoc} */
    public function log(
        $level,
        $message,
        array $context = []
    ): void {
        $resource = match ($level) {
            LogLevel::INFO => $this->createInfoHandler(),
            LogLevel::ERROR => $this->createErrorHandler(),
            default => null,
        };

        if (null === $resource) {
            return;
        }

        $date = $this->formatDate();
        $levelFormatted = \mb_strtoupper($level);
        $extraString = $this->formatContext([]);
        $contextString = $this->formatContext($context);
        $formattedMessage = $this->replaceNewlines($message);
        $record = "[{$date}] crunz.{$levelFormatted}: {$formattedMessage} {$extraString} {$contextString}";

        \fwrite($resource, $record . PHP_EOL);
    }

    /** @return resource */
    private function createInfoHandler()
    {
        if (null === $this->outputHandler) {
            $this->outputHandler = $this->initializeHandler($this->outputStreamPath);
        }

        return $this->outputHandler;
    }

    /** @return resource */
    private function createErrorHandler()
    {
        if (null === $this->errorHandler) {
            $this->errorHandler = $this->initializeHandler($this->errorStreamPath);
        }

        return $this->errorHandler;
    }

    /** @return resource */
    private function initializeHandler(string $path)
    {
        if ('' === $path) {
            throw new CrunzException('Stream path cannot be empty.');
        }

        $directory = $this->dirFromStream($path);
        if (null !== $directory) {
            if (\is_file($directory)) {
                throw new CrunzException(
                    "Unable to create directory '{$directory}', file at this path already exists."
                );
            }

            if (!\file_exists($directory)) {
                \mkdir(
                    $directory,
                    0777,
                    true
                );
            }

            if (!\is_dir($directory)) {
                throw new CrunzException("Unable to create directory '{$directory}'.");
            }
        }

        $handler = \fopen($path, 'ab');
        if (false === $handler) {
            throw new CrunzException("Unable to open stream for path: '{$path}'.");
        }

        return $handler;
    }

    /** @param resource|null $stream */
    private function closeStream($stream): void
    {
        if (!\is_resource($stream)) {
            return;
        }

        \fclose($stream);
    }

    private function dirFromStream(string $stream): ?string
    {
        $pos = \mb_strpos($stream, '://');
        if (false === $pos) {
            return \dirname($stream);
        }

        if (\str_starts_with($stream, 'file://')) {
            return \dirname(
                \mb_substr(
                    $stream,
                    7
                )
            );
        }

        return null;
    }

    /** @param array<mixed,mixed> $data */
    private function formatContext(array $data): string
    {
        if ($this->ignoreEmptyContext && empty($data)) {
            return '';
        }

        return \json_encode($data, JSON_THROW_ON_ERROR);
    }

    private function formatDate(): string
    {
        $now = $this->clock
            ->now()
        ;

        if ($this->timezoneLog) {
            $now = $now->setTimezone($this->timezone);
        }

        return $now->format(self::DATE_FORMAT);
    }

    private function replaceNewlines(string $message): string
    {
        if ($this->allowLineBreaks) {
            if (\str_starts_with($message, '{')) {
                return \str_replace(
                    ['\r', '\n'],
                    ["\r", "\n"],
                    $message
                );
            }

            return $message;
        }

        return \str_replace(
            [
                "\r\n",
                "\r",
                "\n",
            ],
            ' ',
            $message
        );
    }
}
