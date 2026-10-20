<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Config;

/**
 * How one role logs in, for `scenarios:run` (ADR-0021): a login form, or HTTP basic authentication. The
 * credentials are read from the environment variables it names, never from a file.
 */
final readonly class LoginSettings
{
    public const string FORM = 'form';

    public const string HTTP_BASIC = 'http-basic';

    public function __construct(
        public string $role,
        public string $type,
        public string $usernameEnv,
        public string $passwordEnv,
        public ?string $url = null,
        public string $usernameField = '_username',
        public string $passwordField = '_password',
        public ?string $submit = null,
    ) {
        if (!\in_array($type, [self::FORM, self::HTTP_BASIC], true)) {
            throw new InvalidConfig(\sprintf('"%s" is not a way to log in: "%s" or "%s".', $type, self::FORM, self::HTTP_BASIC));
        }

        if (self::FORM === $type && null === $url) {
            throw new InvalidConfig(\sprintf('The login of "%s" is a form: give its url.', $role));
        }
    }
}
