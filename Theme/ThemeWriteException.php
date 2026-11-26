<?php

declare(strict_types=1);

namespace MauticPlugin\EmailToThemeBundle\Theme;

/**
 * The theme could not be written. The message is a translation key; parameters go with it.
 */
class ThemeWriteException extends \RuntimeException
{
    /**
     * @param array<string, string> $parameters
     */
    public function __construct(string $message, private readonly array $parameters = [], ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    /**
     * @return array<string, string>
     */
    public function getParameters(): array
    {
        return $this->parameters;
    }
}
