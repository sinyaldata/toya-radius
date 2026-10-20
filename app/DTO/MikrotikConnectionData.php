<?php

namespace App\DTO;

readonly class MikrotikConnectionData
{
    public function __construct(
        public string $host,
        public int $port,
        public string $username,
        public string $password,
        public bool $useSsl = false,
        public int $timeout = 2,
    ) {}

    public static function fromRouter(\App\Models\Router $router): self
    {
        return new self(
            host: $router->ip_address,
            port: $router->api_port,
            username: $router->username,
            password: $router->password,
            useSsl: $router->use_ssl,
        );
    }
}
