<?php

namespace App\Support;

class WorkflowUrl
{
    public static function route(string $name, mixed $parameters = []): string
    {
        $url = route($name, $parameters);

        return (parse_url($url, PHP_URL_PATH) ?: '/')
            .(($query = parse_url($url, PHP_URL_QUERY)) ? '?'.$query : '');
    }
}
