<?php

namespace App\Support;

final class Assets
{
    private array $manifest;

    public function __construct()
    {
        $this->manifest = json_decode(file_get_contents(public_path('assets/.manifest.json')), true) ?: [];
    }

    public function path(string $name): string
    {
        return '/assets/'.($this->manifest[$name] ?? $name);
    }

    public function head(): string
    {
        $s = '';
        foreach (['app.css', 'lexxy-variables.css', 'lexxy-content.css', 'lexxy-editor.css'] as $logical) {
            $s .= '<link rel="stylesheet" href="'.e($this->path($logical)).'">';
        }

        return $s.'<script type="module" src="'.$this->path('campfire/application_json.js').'"></script>';
    }
}
