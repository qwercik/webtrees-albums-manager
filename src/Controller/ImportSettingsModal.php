<?php

declare(strict_types=1);

namespace Komputeryk\Webtrees\AlbumsManager\Controller;

use Fisharebest\Webtrees\Validator;
use Komputeryk\Webtrees\AlbumsManager\AlbumsManagerModule;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class ImportSettingsModal implements RequestHandlerInterface
{
    public function __construct(private AlbumsManagerModule $module) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $tree = Validator::attributes($request)->tree();
        return response(view($this->module->name() . '::modals/import-settings', [
            'tree' => $tree,
        ]));
    }
}
